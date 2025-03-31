<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\Bucket;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Nextmotion\GoogleCloudStorageDriver\Cache\BucketCache;
use function PHPUnit\Framework\isInstanceOf;

/**
 * Class ObjectListing.
 *
 * The logic is based on the central function getAllObjectsRecursive. This function recursively retrieves the complete
 * listing from the bucket and forms it into a simple array.
 *
 *       GoogleStorageClient->bucket->objects()        Google SDK API
 *              \/
 *       getAllObjectsFromBucket()                     Retrieves full list of bucket content
 *              \/
 *       getObjects(filter)                            Client side filter
 *
 * Other functions like filtering and sorting work with this array. This simple array is cached to achieve high
 * performance.
 */
class Objects
{
    public const array RECURSIVE_FILES_AND_FOLDERS = [];

    public const array DIRECT_SUB_FILES_ONLY = ['delimiter' => '/'];

    public const array DIRECT_SUB_FILES_AND_FOLDERS = ['delimiter' => '/', 'includeTrailingDelimiter' => true];

    /**
     * Objects constructor.
     *
     * @param Bucket $bucket
     * @param NamingHelper $namingHelper
     * @param BucketCache|null $cache
     */
    public function __construct(
        private readonly Bucket $bucket,
        private readonly NamingHelper $namingHelper,
        private ?BucketCache $cache = null,
    ) {
    }

    /**
     * Tells whether the filename is a directory.
     *
     * @return true|false
     */
    public function isFolder(string $folderName)
    {
        return $this->folderExists($folderName);
    }

    /**
     * Checks if directory exists.
     *
     * @param string $folderName directory
     *
     * @return true|false
     */
    public function folderExists(string $folderName)
    {
        // Its not possible to obtain information about bucket root.
        if ($this->isBucketRootFolder($folderName)) {
            return true;
        }

        return $this->getFolderObject($folderName) !== null;
    }

    /** FINAL
     * Returns if $folderName is the root folder. Root folder is an empty string
     * from perspective of google storage.
     *
     * @param string $folderName directory
     *
     * @return true|false
     */
    public function isBucketRootFolder(string $folderName): bool
    {
        $folderName = $this->namingHelper->normalizeFolderName($folderName);

        return $folderName === '';
    }

    /**
     * Returns SimpleBucketObject for a directory.
     *
     * Root directory ('' or '/') has no representation in Google Cloud Storage, so null will be returned.
     *
     * Folders are special in a flat filesystem. They can be implicit trough filenames like 'abc/test.txt' and
     * the can exist as objects. This objects are emtpy objects ending with a trail.
     *
     * After the first occurrence of a file or a directory a simple object will be returned.
     *
     * @param string $folderName directory
     *
     * @return SimpleBucketObject|null Returns a StorageObject resource on success, or null on root folder or if folder doesn't exists
     */
    public function getFolderObject(string $folderName): ?SimpleBucketObject
    {
        $folderName = $this->namingHelper->normalizeFolderName($folderName);

        // Its not possible to obtain information about bucket root.
        if ($this->isBucketRootFolder($folderName)) {
            return null;
        }

        if (count($this->getObjects(substr($folderName, 0, -1), true)) === 0) {
            return null;
        }

        return new SimpleBucketObject(['name' => $folderName, 'type' => SimpleBucketObject::TYPE_FOLDER]);
    }

    /**
     * Filter and sort the full directory and file list.
     *
     * @param string $prefix
     * @param bool $recursive
     * @param bool $includeFiles
     * @param bool $includeFolders
     * @param false|null $includeItSelf
     *
     * @return SimpleBucketObject[] All objects
     */
    public function getObjects(
        string $prefix = '',
        bool $recursive = false,
        bool $includeFiles = true,
        bool $includeFolders = true,
        false|null $includeItSelf = false
    ): array {
        if ($this->cache instanceof BucketCache && $this->cache->exists([__FUNCTION__, func_get_args()])) {
            return $this->cache->get([__FUNCTION__, func_get_args()]);
        }

        $result = [];
        $objects = $this->getAllObjectFromBucket();

        foreach ($objects as $objectName => $object) {
            // Skip the requested prefix itself
            if (!$includeItSelf && $objectName === $prefix) {
                continue;
            }

            // Skip objects with wrong prefix
            if (!str_starts_with($objectName, $prefix)) {
                continue;
            }

            // Skip folders
            if ($object->isFolder() && !$includeFolders) {
                continue;
            }

            // Skip files
            if ($object->isFile() && !$includeFiles) {
                continue;
            }

            // Skip subdirectories
            if (!$recursive
                && substr_count($objectName, '/', strlen($prefix)) > ($object->isFile() ? 0 : 1)) {
                continue;
            }

            $result[$objectName] = $object;
        }

        if ($this->cache) {
            $this->cache->set([__FUNCTION__, func_get_args()], $result);
        }

        return $result;
    }

    /**
     * Build a flat array with all objects from the bucket, including phantom dirs.
     *
     * @param string $prefix
     *
     * @return SimpleBucketObject[] All objects for the prefix
     */
    public function getAllObjectFromBucket($prefix = '')
    {
        if ($this->cache && $this->cache->exists([__FUNCTION__, func_get_args()])) {
            return $this->cache->get([__FUNCTION__, func_get_args()]);
        }

        // Default
        $options = [
            'prefix' => $prefix,
            'fields' => 'items/name,items/contentType,items/size,items/timeCreated,items/updated,nextPageToken',
        ];

        $objects = $this->bucket->objects($options);

        $result = [];
        foreach ($objects as $object) {
            $objectName = $object->name();
            $info = $object->info();

            // directories ends up with '/'
            $isFile = !str_ends_with($objectName, '/');

            $result[$objectName] = new SimpleBucketObject([
                'name' => $objectName,
                'contentType' => $info['contentType'],
                'filesize' => $info['size'],
                'created_at' => strtotime((string)$info['timeCreated']),
                'updated_at' => strtotime((string)$info['updated']),
                'type' => $isFile ? SimpleBucketObject::TYPE_FILE : SimpleBucketObject::TYPE_FOLDER,
            ]);

            // Add virtual directories.
            // If name contains a / (Slash), then add a virtual directory if it doesn't exists.
            // If a real directory (in meaning of Google Storage) is later found, it will be overwritten.
            //
            // Create directories recursive, cause a name in flat filesystem can be dir1/dir2/filename.txt.
            // Its necessary to create this virtual directories:
            // dir1/
            // dir1/dir2/
            if (str_contains($objectName, '/')) {
                $directories = explode('/', $objectName);
                array_pop($directories);
                $path = '';
                foreach ($directories as $directory) {
                    $path .= $directory . '/';
                    if (!isset($result[$path])) {
                        $result[$path] = new SimpleBucketObject([
                            'name' => $path,
                            'type' => SimpleBucketObject::TYPE_FOLDER,
                            'created_at' => strtotime((string)$info['timeCreated']),
                            'updated_at' => strtotime((string)$info['updated']),
                        ]);
                    }
                }
            }
        }

        if ($this->cache) {
            $this->cache->set([__FUNCTION__, func_get_args()], $result);
        }

        return $result;
    }

    /**
     * Checks if directory exists.
     *
     * @param string $filename
     *
     * @return true|false
     */
    public function fileExists($filename): bool // strict string typecast doesn't work because scheduler call function with null.
    {
        $filename = $this->namingHelper->normalizeFileName($filename);
        $object = $this->getObject($filename);

        return $object !== null && $object->isFile();
    }

    /**
     * @param string $name Filename or foldername
     *
     * @return SimpleBucketObject|null
     */
    public function getObject($name)
    {
        $objects = $this->getAllObjectFromBucket();

        return $objects[$name] ?? null;
    }

    /**
     * @param string $path
     * @param bool $recursive
     * @param bool $includeFiles
     * @param bool $includeFolders
     *
     * @return SimpleBucketObject[]
     */
    public function retrieveFileAndFoldersInPath($path, $recursive = false, $includeFiles = true, $includeFolders = true, string $sort = '', bool $sortRev = false)
    {
        $objects = $this->getObjects($path, $recursive, $includeFiles, $includeFolders);

        return $this->sortObjectsBy($objects, $sort, $sortRev);
    }

    /**
     * @param SimpleBucketObject[] $objects
     *
     * @return SimpleBucketObject[]
     */
    public function sortObjectsBy(array $objects, string $sort, bool $sortRev)
    {
        match ($sort) {
            'size' => usort($objects, fn (SimpleBucketObject $a, SimpleBucketObject $b): int => $a->getFilesize() <=> $b->getFilesize()),
            'fileext' => usort($objects, fn ($a, $b): int => strnatcasecmp(
                pathinfo((string)$a->getName(), PATHINFO_EXTENSION),
                pathinfo((string)$b->getName(), PATHINFO_EXTENSION),
            )),
            'tstamp' => usort($objects, fn ($a, $b): int => $a->getUpdatedAt() <=> $b->getUpdatedAt()),
            default => usort($objects, fn ($a, $b): int => strnatcasecmp((string)$a->getName(), (string)$b->getName())),
        };

        return $sortRev ? array_reverse($objects) : $objects;
    }

    public function convertSimpleBucketObjectsToArray($objects): array
    {
        return array_map(fn ($object) => $object->toArray(), $objects);
    }
}

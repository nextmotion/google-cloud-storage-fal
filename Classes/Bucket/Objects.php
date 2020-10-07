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
    const RECURSIVE_FILES_AND_FOLDERS = [];
    const DIRECT_SUB_FILES_ONLY = ['delimiter' => '/'];
    const DIRECT_SUB_FILES_AND_FOLDERS = ['delimiter' => '/', 'includeTrailingDelimiter' => true];

    /**
     * @var Bucket
     */
    private $bucket;
    /**
     * @var NamingHelper
     */
    private $namingHelper;

    /**
     * @var BucketCache|null
     */
    private $cache;

    /**
     * Objects constructor.
     *
     * @param Bucket $bucket
     * @param NamingHelper $namingHelper
     * @param BucketCache|null $bucketCache
     */
    public function __construct(Bucket $bucket, NamingHelper $namingHelper, $bucketCache = null)
    {
        $this->bucket = $bucket;
        $this->namingHelper = $namingHelper;
        $this->cache = $bucketCache;
    }

    /**
     * Tells whether the filename is a directory.
     *
     * @param string $folderName
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
    public function isBucketRootFolder(string $folderName)
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
    public function getFolderObject(string $folderName)
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
     * @param mixed $includeItSelf
     *
     * @return SimpleBucketObject[] All objects
     */
    public function getObjects($prefix = '', $recursive = false, $includeFiles = true, $includeFolders = true, $includeItSelf = false)
    {
        if ($this->cache && $this->cache->exists([__FUNCTION__, func_get_args()])) {
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
            if (substr($objectName, 0, strlen($prefix)) !== $prefix) {
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
            if (!$recursive &&
                substr_count($objectName, '/', strlen($prefix)) > ($object->isFile() ? 0 : 1)) {
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
        /** @var StorageObject $bucketObject */
        foreach ($objects as $bucketObject) {
            $objectName = $bucketObject->name();
            $info = $bucketObject->info();

            // directories ends up with '/'
            $isFile = substr($objectName, -1) !== '/';

            $result[$objectName] = new SimpleBucketObject([
                'name' => $objectName,
                'contentType' => $info['contentType'],
                'filesize' => $info['size'],
                'created_at' => strtotime($info['timeCreated']),
                'updated_at' => strtotime($info['updated']),
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
            if (strpos($objectName, '/') !== false) {
                $directories = explode('/', $objectName);
                array_pop($directories);
                $path = '';
                foreach ($directories as $directoryPart) {
                    $path .= $directoryPart . '/';
                    if (!isset($result[$path])) {
                        $result[$path] = new SimpleBucketObject([
                            'name' => $path,
                            'type' => SimpleBucketObject::TYPE_FOLDER,
                            'created_at' => strtotime($info['timeCreated']),
                            'updated_at' => strtotime($info['updated']),
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
    public function fileExists($filename) // strict string typecast doesn't work because scheduler call function with null.
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
     * @param string $sort
     * @param bool $sortRev
     *
     * @return SimpleBucketObject[]
     */
    public function retrieveFileAndFoldersInPath($path, $recursive = false, $includeFiles = true, $includeFolders = true, $sort = '', $sortRev = false)
    {
        $objects = $this->getObjects($path, $recursive, $includeFiles, $includeFolders);

        return $this->sortObjectsBy($objects, $sort, $sortRev);
    }

    /**
     * @param SimpleBucketObject[] $objects
     * @param string $sort
     * @param bool $sortRev
     *
     * @return SimpleBucketObject[]
     */
    public function sortObjectsBy($objects, string $sort, bool $sortRev)
    {
        switch ($sort) {
            case 'size':
                usort($objects, function ($a, $b) {
                    return $a->getSize() <=> $b->getSize();
                });
                break;
            case 'fileext':
                usort($objects, function ($a, $b) {
                    return
                        strnatcasecmp(
                            pathinfo($a->getName(), PATHINFO_EXTENSION),
                            pathinfo($b->getName(), PATHINFO_EXTENSION)
                        );
                });
                break;
            case 'tstamp':
                usort($objects, function ($a, $b) {
                    return $a->getUpdatedAt() <=> $b->getUpdatedAt();
                });
                break;
            case 'name':
            case 'file':
            case 'rw':
            default:
                usort($objects, function ($a, $b) {
                    return strnatcasecmp($a->getName(), $b->getName());
                });
        }

        return $sortRev ? array_reverse($objects) : $objects;
    }

    public function convertSimpleBucketObjectsToArray($objects)
    {
        return array_map(function ($object) {
            return $object->toArray();
        }, $objects);
    }
}

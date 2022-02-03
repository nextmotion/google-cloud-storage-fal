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
use Nextmotion\GoogleCloudStorageDriver\Cache\BucketCache;

/**
 * Class BucketOperations.
 */
class Operations
{

    /**
     * @var Bucket
     */
    private $bucket;
    /**
     * @var Objects
     */
    private $bucketObjects;
    /**
     * @var BucketCache
     */
    private $bucketCache;
    /**
     * @var NamingHelper
     */
    private $namingHelper;

    /**
     * Operations constructor.
     *
     * @param Bucket $bucket
     * @param NamingHelper $namingHelper
     * @param Objects $bucketObjects
     * @param mixed $cache
     */
    public function __construct(Bucket $bucket, NamingHelper $namingHelper, Objects $bucketObjects, $cache)
    {
        $this->bucket = $bucket;
        $this->namingHelper = $namingHelper;
        $this->bucketObjects = $bucketObjects;
        $this->bucketCache = $cache;
    }

    /**
     * @param string $folderName
     *
     * @return bool
     */
    public function mkdir($folderName)
    {
        $folderName = $this->namingHelper->normalizeFolderName($folderName);
        // Bucket root exists by default
        if ($this->bucketObjects->isBucketRootFolder($folderName)) {
            return true;
        }

        if ($this->bucketCache instanceof BucketCache) {
            $this->bucketCache->clear();
        }

        return (bool)$this->bucket->upload('', ['name' => $folderName]);
    }

    /**
     * Creates an empty file.
     *
     * @param string $filename
     *
     * @return \Google\Cloud\Storage\StorageObject
     */
    public function createEmptyFile($filename)
    {
        if ($this->bucketCache instanceof BucketCache) {
            $this->bucketCache->clear();
        }

        return $this->bucket->upload('', ['name' => $filename]);
    }

    /**
     * Copy an object.
     *
     * @param string $fileIdentifier
     * @param string $targetFileName
     *
     * @return \Google\Cloud\Storage\StorageObject
     */
    public function copyFromTo($fileIdentifier, $targetFileName)
    {
        if ($this->bucketCache instanceof BucketCache) {
            $this->bucketCache->clear();
        }

        return $this->bucket->object($fileIdentifier)->copy($this->bucket->name(), ['name' => $targetFileName]);
    }

    /**
     * Rename an object.
     *
     * @param string $oldName
     * @param string $newName
     *
     * @return \Google\Cloud\Storage\StorageObject|null
     */
    public function rename($oldName, $newName)
    {
        if ($this->bucketCache instanceof BucketCache) {
            $this->bucketCache->clear();
        }

        // Could happen on phantom directories.
        if (!$this->bucket->object($oldName)->exists()) {
            return null;
        }

        return $this->bucket->object($oldName)->rename($newName);
    }

    /**
     * Deletes an object.
     *
     * @param string $fileIdentifier
     *
     * @return void
     */
    public function delete($fileIdentifier)
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        if ($this->bucketCache instanceof BucketCache) {
            $this->bucketCache->clear();
        }

        $this->bucket->object($fileIdentifier)->delete();
    }
}

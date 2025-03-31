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

/**
 * Class BucketOperations.
 */
class Operations
{
    /**
     * Operations constructor.
     */
    public function __construct(private readonly Bucket $bucket, private readonly NamingHelper $namingHelper, private readonly Objects $bucketObjects, private readonly mixed $bucketCache)
    {
    }

    public function mkdir(string $folderName): bool
    {
        $folderName = $this->namingHelper->normalizeFolderName($folderName);
        // Bucket root exists by default
        if ($this->bucketObjects->isBucketRootFolder($folderName)) {
            return true;
        }

        $this->bucketCache->clear();

        return (bool)$this->bucket->upload('', ['name' => $folderName]);
    }

    /**
     * Creates an empty file.
     */
    public function createEmptyFile(string $filename): StorageObject
    {
        $this->bucketCache->clear();

        return $this->bucket->upload('', ['name' => $filename]);
    }

    /**
     * Copy an object.
     */
    public function copyFromTo(string $fileIdentifier, string $targetFileName): StorageObject
    {
        $this->bucketCache->clear();

        $fileIdentifier = ltrim($fileIdentifier, '/');
        $storageObject = $this->bucket->object($fileIdentifier);
        $bucketName = $this->bucket->name();

        return $storageObject->copy($bucketName, ['name' => $targetFileName]);
    }

    /**
     * Rename an object.
     */
    public function rename(string $oldName, string $newName): ?StorageObject
    {
        $this->bucketCache->clear();

        // Could happen on phantom directories.
        if (!$this->bucket->object($oldName)->exists()) {
            return null;
        }

        return $this->bucket->object($oldName)->rename($newName);
    }

    /**
     * Deletes an object.
     */
    public function delete(string $fileIdentifier, bool $isFolder = false): void
    {
        if ($isFolder) {
            $fileIdentifier = $this->namingHelper->normalizeFolderName($fileIdentifier);
        } else {
            $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        }

        $this->bucketCache->clear();

        $this->bucket->object($fileIdentifier)->delete();
    }
}

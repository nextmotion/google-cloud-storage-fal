<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\Index;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Index.
 */
class ImageMetaDataExtractor implements ExtractorInterface
{
    /**
     * Returns an array of supported file types.
     *
     * @return array<string>|array{}
     */
    public function getFileTypeRestrictions(): array
    {
        return [];
    }

    /**
     * Get all supported DriverClasses
     * empty array indicates no restrictions.
     *
     * @return array<string>
     */
    public function getDriverRestrictions(): array
    {
        return ['GoogleCloudStorageDriver'];
    }

    /**
     * Returns the data priority of the extraction Service.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * Returns the execution priority of the extraction Service.
     *
     * @return int
     */
    public function getExecutionPriority(): int
    {
        return 10;
    }

    /**
     * Checks if the given file can be processed by this Index.
     *
     * @return bool
     */
    public function canProcess(File $file): bool
    {
        return $file->isImage();
    }

    /**
     * The actual processing TASK
     * Should return an array with database properties for sys_file_metadata to write.
     *
     * @param array<mixed> $previousExtractedData optional, contains the array of already extracted data
     *
     * @return array<mixed>
     */
    public function extractMetaData(File $file, array $previousExtractedData = []): array
    {
        $metaData = [];

        if ($file->isImage()) {
            $rawFileLocation = $file->getForLocalProcessing(false);
            $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $rawFileLocation);
            $metaData = [
                'width' => $imageInfo->getWidth(),
                'height' => $imageInfo->getHeight(),
            ];
            if (file_exists($rawFileLocation)) {
                @unlink($rawFileLocation);
            }
        }

        return $metaData;
    }
}

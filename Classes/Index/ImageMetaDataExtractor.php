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
     * @return array
     */
    public function getFileTypeRestrictions()
    {
        return [];
    }

    /**
     * Get all supported DriverClasses
     * empty array indicates no restrictions.
     *
     * @return array
     */
    public function getDriverRestrictions()
    {
        return ['GoogleCloudStorageDriver'];
    }

    /**
     * Returns the data priority of the extraction Service.
     *
     * @return int
     */
    public function getPriority()
    {
        return 10;
    }

    /**
     * Returns the execution priority of the extraction Service.
     *
     * @return int
     */
    public function getExecutionPriority()
    {
        return 10;
    }

    /**
     * Checks if the given file can be processed by this Index.
     *
     * @param File $file
     *
     * @return bool
     */
    public function canProcess(File $file)
    {
        return $file->isImage();
    }

    /**
     * The actual processing TASK
     * Should return an array with database properties for sys_file_metadata to write.
     *
     * @param File $file
     * @param array $previousExtractedData optional, contains the array of already extracted data
     *
     * @return array
     */
    public function extractMetaData(File $file, array $previousExtractedData = [])
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

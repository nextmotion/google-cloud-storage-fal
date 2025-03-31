<?php

use Nextmotion\GoogleCloudStorageDriver\Driver\StorageDriver;
use Nextmotion\GoogleCloudStorageDriver\Index\ImageMetaDataExtractor;
use TYPO3\CMS\Core\Resource\Index\ExtractorRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\Driver\DriverRegistry;

if (!defined('TYPO3')) {
    exit('Access denied.');
}

call_user_func(static function ($extensionKey): void {
    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets'][$extensionKey] =
        'EXT:google_cloud_storage_fal/Resources/Public/Css/Backend.css';
}, 'google_cloud_storage_fal');

/** @var DriverRegistry $driverRegistry */
$driverRegistry = GeneralUtility::makeInstance(DriverRegistry::class);
$driverRegistry->registerDriverClass(
    StorageDriver::class,
    'GoogleCloudStorageDriver',
    'Google Cloud Storage',
    'FILE:EXT:google_cloud_storage_fal/Configuration/FlexForms/GoogleCloudStorage.xml',
);

$extractorRegistry = GeneralUtility::makeInstance(ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(ImageMetaDataExtractor::class);

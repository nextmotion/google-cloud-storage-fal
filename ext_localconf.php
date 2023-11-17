<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

use TYPO3\CMS\Core\Resource\Driver\DriverRegistry;

call_user_func(static function ($extensionKey) : void {
    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets'][$extensionKey] =
        'EXT:google_cloud_storage_fal/Resources/Public/Css/Backend.css';
},'google_cloud_storage_fal');

/** @var DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DriverRegistry::class);
$driverRegistry->registerDriverClass(
    \Nextmotion\GoogleCloudStorageDriver\Driver\StorageDriver::class,
    'GoogleCloudStorageDriver',
    'Google Cloud Storage',
    'FILE:EXT:google_cloud_storage_fal/Configuration/FlexForms/GoogleCloudStorage.xml'
);

$extractorRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(\Nextmotion\GoogleCloudStorageDriver\Index\ImageMetaDataExtractor::class);

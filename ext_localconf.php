<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class);
$driverRegistry->registerDriverClass(
    \Nextmotion\GoogleCloudStorageDriver\Driver\StorageDriver::class,
    'GoogleCloudStorageDriver',
    'Google Cloud Storage',
    'FILE:EXT:google_cloud_storage_fal/Configuration/FlexForms/GoogleCloudStorage.xml'
);

$extractorRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(\Nextmotion\GoogleCloudStorageDriver\Index\ImageMetaDataExtractor::class);

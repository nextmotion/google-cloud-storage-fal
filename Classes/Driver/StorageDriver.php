<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\Driver;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use Helhum\ConfigLoader\Processor\PlaceholderValue;
use Nextmotion\GoogleCloudStorageDriver\Bucket\NamingHelper;
use Nextmotion\GoogleCloudStorageDriver\Bucket\Objects;
use Nextmotion\GoogleCloudStorageDriver\Bucket\Operations;
use Nextmotion\GoogleCloudStorageDriver\Cache\BucketCache;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ResourceStorageInterface;
use TYPO3\CMS\Core\Utility\PathUtility;

class StorageDriver extends AbstractHierarchicalFilesystemDriver
{
    /**
     * @var Bucket
     */
    public Bucket $bucket;

    /**
     * @var Objects
     */
    public Objects $bucketObjects;

    /** @var array */
    protected array $mappingFolderNameToRole = [
        '_recycler_' => FolderInterface::ROLE_RECYCLER,
        '_temp_' => FolderInterface::ROLE_TEMPORARY,
        'user_upload' => FolderInterface::ROLE_USERUPLOAD,
    ];

    /**
     * @var StorageClient
     */
    private StorageClient $googleCloudStorageClient;

    /**
     * @var Operations
     */
    private Operations $bucketOperations;
    /**
     * @var BucketCache
     */
    private BucketCache $bucketCache;

    private string|null $keyFilePath = null;
    private array|null $keyFileContent = null;
    /**
     * Current Bucketname.
     *
     * @var string
     */
    private string $bucketName;
    /**
     * The base URL that points to this driver's storage. As long is this
     * is not set, it is assumed that this folder is not publicly available.
     *
     * @var string
     */
    private string $publicBaseUri;
    /**
     * @var NamingHelper
     */
    private NamingHelper $namingHelper;

    /**
     * Initialize this driver and expose the capabilities for the repository to use @param array $configuration .
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities =
            ResourceStorageInterface::CAPABILITY_BROWSABLE |
            ResourceStorageInterface::CAPABILITY_PUBLIC |
            ResourceStorageInterface::CAPABILITY_WRITABLE;
    }

    /**
     * {@inheritdoc}
     */
    public function processConfiguration(): void
    {
        $placeholder = new PlaceholderValue();
        $this->configuration = $placeholder->processConfig($this->configuration);

        $authType = $this->configuration['authenticationType'] ?? 'keyFilePath';

        if ($authType === 'keyFilePath') {
            $this->keyFilePath = $this->configuration['keyFilePath'] ?? null;
        }
        if ($authType === 'keyFileContent') {
            $keyFileContent = $this->configuration['keyFileContent'] ?? null;
            $this->keyFileContent = $keyFileContent ? json_decode($keyFileContent, true, 512, JSON_THROW_ON_ERROR) : "";
        }
        $this->bucketName = $this->configuration['bucketName'] ?? '';
        $this->publicBaseUri = $this->configuration['publicBaseUri'] ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(): void
    {
        $config = [];
        if ($this->keyFilePath !== null) {
            $config['keyFilePath'] = $this->getKeyFilePath($this->keyFilePath);
        }

        if (is_array($this->keyFileContent) && count($this->keyFileContent) > 0) {
            $config['keyFile'] = $this->keyFileContent;
        }

        $this->googleCloudStorageClient = new StorageClient($config);
        $this->bucket = $this->googleCloudStorageClient->bucket($this->bucketName);

        $this->bucketCache = new BucketCache();
        $this->namingHelper = new NamingHelper();
        $this->bucketObjects = new Objects($this->bucket, $this->namingHelper, $this->bucketCache);
        $this->bucketOperations = new Operations($this->bucket, $this->namingHelper, $this->bucketObjects, $this->bucketCache);
    }

    private function getKeyFilePath(string $path): string
    {
        return defined('TYPO3') ?
            PathUtility::isAbsolutePath($this->keyFilePath)
                ? $this->keyFilePath
                : \TYPO3\CMS\Core\Core\Environment::getProjectPath() . DIRECTORY_SEPARATOR . $this->keyFilePath :
            $this->keyFilePath;
    }

    /**
     * {@inheritdoc}
     */
    public function mergeConfigurationCapabilities($capabilities): int
    {
        $this->capabilities &= $capabilities;

        return $this->capabilities;
    }

    /**
     * {@inheritdoc}
     */
    public function getPublicUrl($identifier): ?string
    {
        return $this->fileExists($identifier) ?
            $this->publicBaseUri . $this->namingHelper->normalizeFileName($identifier) :
            '';
    }

    /**
     * {@inheritdoc}
     */
    public function fileExists($fileIdentifier): bool
    {
        return $this->bucketObjects->fileExists($fileIdentifier);
    }

    /**
     * Removes a folder in filesystem.
     *
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     *
     * @return bool
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = false): bool
    {
        $sourceFolderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier);

        $recycleDirectory = $this->getRecycleDirectory($sourceFolderIdentifier);

        if (!empty($recycleDirectory) && $sourceFolderIdentifier !== $recycleDirectory && !$this->isWithin($recycleDirectory, $sourceFolderIdentifier)) {
            return count($this->recycleFileOrFolder($sourceFolderIdentifier, $recycleDirectory)) > 0;
        } else {
            if ($deleteRecursively || $this->isFolderEmpty($sourceFolderIdentifier)) {
                $objects = $this->bucketObjects->getObjects(
                    $sourceFolderIdentifier,
                    $deleteRecursively,
                    true,
                    true,
                    true
                );
                foreach ($objects as $object) {
                    $this->bucketOperations->delete($object->getName(), $object->isFolder());
                }

                return true;
            }
        }
        return false;
    }

    /**
     * Get the path of the nearest recycler folder of a given $path.
     * Return an empty string if there is no recycler folder available.
     *
     * @param string $path
     * @return string
     */
    protected function getRecycleDirectory($path): string
    {
        $recyclerSubdirectory = array_search(FolderInterface::ROLE_RECYCLER, $this->mappingFolderNameToRole, true);
        if ($recyclerSubdirectory === false) {
            return '';
        }

        // Don't move _recycler_ in _recycler_ on higher levels.
        $basename = basename($path);
        if ($this->getRole($basename) === FolderInterface::ROLE_RECYCLER) {
            return '';
        }

        // Build traversal _recycler_ paths
        // dir/subdir/testfile.txt
        // ends up in
        // [
        //  'dir/subdir/_reycler_/’,
        //  'dir/_reycler_/’
        //  '_reycler_/’
        //  ]
        $path_parts = explode(
            $this->namingHelper->getDirDelimiter(),
            $path
        );
        $buildPath = "";
        $possibleRecyclerPaths = [];
        foreach ($path_parts as $part) {
            $possibleRecyclerPaths[] = $buildPath . $recyclerSubdirectory . $this->namingHelper->getDirDelimiter();
            $buildPath .= $part . $this->namingHelper->getDirDelimiter();
        }
        usort($possibleRecyclerPaths, function ($a, $b) {
            return strlen($a) > strlen($b) ? -1 : 1;
        });

        foreach ($possibleRecyclerPaths as $pathToTest) {
            if ($this->folderExists($pathToTest)) {
                return $pathToTest;
            }
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getRole($folderIdentifier)
    {
        $name = PathUtility::basename($folderIdentifier);

        return $this->mappingFolderNameToRole[$name] ?? FolderInterface::ROLE_DEFAULT;
    }

    /**
     * {@inheritdoc}
     */
    public function folderExists($folderIdentifier): bool
    {
        return $this->bucketObjects->folderExists($folderIdentifier);
    }

    /**
     * {@inheritdoc}
     */
    public function isWithin($folderIdentifier, $identifier): bool
    {
        $trimmedIdentifier = ltrim($identifier, '/');
        $searchIdentifier = '/' . $trimmedIdentifier;
        return str_starts_with($searchIdentifier, $folderIdentifier);
    }

    /**
     * Moves a file or folder to the given directory, renaming the source in the process if
     * a file or folder of the same name already exists in the target path.
     *
     * @param string $filePath
     * @param string $recycleDirectory
     * @return bool|array|string
     */
    protected function recycleFileOrFolder($filePath, $recycleDirectory): bool|array|string
    {
        $destinationPath = $recycleDirectory . '/' . basename($filePath);
        if ($this->fileExists($destinationPath) || $this->folderExists($destinationPath)) {
            $timeStamp = date('YmdHisu');
            $destinationBasename = $timeStamp . '_' . basename($filePath);
        } else {
            $destinationBasename = basename($filePath);
        }
        if ($this->folderExists($filePath) && !$this->isWithin($recycleDirectory, $filePath)) {
            $result = $this->moveFolderWithinStorage($filePath, $recycleDirectory, $destinationBasename);
        }
        if ($this->fileExists($filePath) && !$this->isWithin($recycleDirectory, $filePath)) {
            $result = $this->moveFileWithinStorage($filePath, $recycleDirectory, $destinationBasename);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName): array
    {
        $targetFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier);
        $newFolderName = $this->namingHelper->normalizeFolderName($newFolderName);

        $sourceFolderIdentifier = $this->namingHelper->normalizeFolderName($sourceFolderIdentifier);
        $destinationFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier . $newFolderName);

        $map = [];
        foreach ($this->bucketObjects->getObjects($sourceFolderIdentifier, true, true, true, true) as $object) {
            $oldFilename = $object->getName();
            $newFilename = $destinationFolderIdentifier . substr($oldFilename, strlen($sourceFolderIdentifier));
            $this->bucketOperations->rename($oldFilename, $newFilename);
            $map[$this->getRootLevelFolder() . $oldFilename] = $this->getRootLevelFolder() . $newFilename;
        }

        return $map;
    }

    /**
     * {@inheritdoc}
     */
    public function getRootLevelFolder(): string
    {
        return '/';
    }

    /**
     * {@inheritdoc}
     */
    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName): string
    {
        $targetFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier);
        $targetName = $this->namingHelper->normalizeFolderName($targetFolderIdentifier) . $newFileName;

        $this->bucketOperations->rename($fileIdentifier, $targetName);

        return $targetName;
    }

    /**
     * {@inheritdoc}
     */
    public function isFolderEmpty($folderIdentifier): bool
    {
        $folderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier);

        return $this->bucketObjects->isFolder($folderIdentifier) &&
            count(
                $this->bucketObjects->getObjects($folderIdentifier, true, true, true)
            ) == 0;
    }

    /**
     * {@inheritdoc}
     */
    public function createFile($fileName, $parentFolderIdentifier): string
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName(
            $this->namingHelper->normalizeFolderName($parentFolderIdentifier) .
            $fileName
        );
        $this->bucketOperations->createEmptyFile($fileIdentifier);

        return $fileIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName): string
    {
        $targetFileName = $this->namingHelper->normalizeFileName(
            $this->namingHelper->normalizeFolderName($targetFolderIdentifier) .
            $fileName
        );
        $this->bucketOperations->copyFromTo($fileIdentifier, $targetFileName);

        return $targetFileName;
    }

    /**
     * {@inheritdoc}
     */
    public function replaceFile($fileIdentifier, $localFilePath): bool
    {
        $this->bucketOperations->delete($fileIdentifier);
        $targetFolder = $this->namingHelper->normalizeFolderName(dirname($fileIdentifier));
        $newName = basename($fileIdentifier);
        $this->addFile($localFilePath, $targetFolder, $newName);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true): string
    {
        if ($newFileName === '') {
            $newFileName = basename($localFilePath);
        }

        $targetFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier);
        $fileIdentifier = $targetFolderIdentifier . $newFileName;

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = finfo_file($fileInfo, $localFilePath);
        finfo_close($fileInfo);

        $pathInfo = pathinfo($newFileName);

        // Special mapping
        $fileExtensionToMimeTypeMapping = $GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType'];

        if (isset($pathInfo['extension']) && array_key_exists($pathInfo['extension'], $fileExtensionToMimeTypeMapping)) {
            $contentType = $fileExtensionToMimeTypeMapping[$pathInfo['extension']];
        }

        $options = [
            'resumable' => filesize($localFilePath) > 0, // 0 byte files can't use resumable.
            'name' => $fileIdentifier,
            'metadata' => [
                'contentType' => $contentType,
            ],
        ];

        $uploader = $this->bucket->getResumableUploader(
            fopen($localFilePath, 'r'),
            $options
        );

        try {
            $uploader->upload();
        } catch (GoogleException $ex) {
            $resumeUri = $uploader->getResumeUri();
            $uploader->resume($resumeUri);
        }

        $this->bucketCache->clear();

        if ($removeOriginal === true) {
            @unlink($localFilePath);
        }

        return $fileIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteFile($fileIdentifier): bool
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        $this->bucketOperations->delete($fileIdentifier);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function renameFile($fileIdentifier, $newName): string
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        $targetFolder = $this->namingHelper->normalizeFolderName(dirname($fileIdentifier));
        $newName = $this->namingHelper->normalizeFileName($newName);
        $this->bucketOperations->rename($fileIdentifier, $targetFolder . $newName);

        return $targetFolder . $newName;
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContents($fileIdentifier): string
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        return $this->bucket->object($fileIdentifier)->downloadAsString();
    }

    /**
     * {@inheritdoc}
     */
    public function setFileContents($fileIdentifier, $contents): int
    {
        $options = [
            'resumable' => false,
            'name' => $fileIdentifier,
        ];
        $object = $this->bucket->upload(
            $contents,
            $options
        );
        $this->bucketCache->clear();

        return strlen($contents);
    }

    /**
     * {@inheritdoc}
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true): string
    {
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        $temporaryPath = '';

        $obj = $this->bucket->object($fileIdentifier);
        if ($obj->exists() !== false) {
            $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
            $obj->downloadToFile($temporaryPath);
            if (!file_exists($temporaryPath)) {
                throw new \RuntimeException('Writing file ' . $fileIdentifier . ' to temporary path failed.', 1320577649);
            }
        }

        return $temporaryPath;
    }

    /**
     * {@inheritdoc}
     */
    public function dumpFileContents($identifier): void
    {
        try {
            fpassthru($this->bucket->object($identifier)->downloadAsStream());
        } catch (\Throwable $e) {
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = []): array
    {
        if (empty($propertiesToExtract)) {
            $propertiesToExtract = [
                'size', 'atime', 'mtime', 'ctime', 'mimetype', 'name', 'extension',
                'identifier', 'identifier_hash', 'storage', 'folder_hash',
            ];
        }

        if ($this->bucketObjects->isFolder($fileIdentifier) || !$this->fileExists($fileIdentifier)) {
            return [];
        }

        $fileInformation = [];
        $fileIdentifier = $this->namingHelper->normalizeFileName($fileIdentifier);
        $simpleBucketObject = $this->bucketObjects->getObject($fileIdentifier);
        foreach ($propertiesToExtract as $property) {
            $fileInformation[$property] = $this->getSpecificFileInformation($fileIdentifier, $simpleBucketObject, $property);
        }

        return $fileInformation;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecificFileInformation($fileIdentifier, $simpleBucketObject, $property)
    {
        switch ($property) {
            case 'size':
                return $simpleBucketObject->getFilesize();
            case 'mtime':
            case 'atime':
                return $simpleBucketObject->getUpdatedAt();
            case 'ctime':
                return $simpleBucketObject->getCreatedAt();
            case 'name':
                return basename(rtrim($fileIdentifier, '/'));
            case 'extension':
                return PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION);
            case 'mimetype':
                return (string)$simpleBucketObject->getContentType();
            case 'identifier':
                return $fileIdentifier;
            case 'storage':
                return $this->storageUid;
            case 'identifier_hash':
                return $this->hashIdentifier($fileIdentifier);
            case 'folder_hash':
                return $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($fileIdentifier));
            default:
                throw new \InvalidArgumentException(sprintf('The information "%s" is not available.', $property), 1476047422);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFileInFolder($fileName, $folderIdentifier): string
    {
        return $this->namingHelper->normalizeFolderName($folderIdentifier) . $fileName;
    }

    /**
     * {@inheritdoc}
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = []): int
    {
        return count($this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks));
    }

    /**
     * {@inheritdoc}
     */
    public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = false, array $filenameFilterCallbacks = [], $sort = '', $sortRev = false): array
    {
        return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $filenameFilterCallbacks, true, false, $recursive, $sort, $sortRev);
    }

    /**
     * @param $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param array $filterMethods
     * @param bool $includeFiles
     * @param bool $includeDirs
     * @param bool $recursive
     * @param string $sort
     * @param bool $sortRev
     * @return array
     */
    protected function getDirectoryItemList($folderIdentifier, int $start, int $numberOfItems, array $filterMethods, $includeFiles = true, $includeDirs = true, $recursive = false, $sort = '', $sortRev = false): array
    {
        $folders = [];
        try {
            $folderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier);

            $objects = new \ArrayIterator($this->bucketObjects->retrieveFileAndFoldersInPath(
                $folderIdentifier,
                $recursive,
                $includeFiles,
                $includeDirs,
                $sort,
                $sortRev
            ));

            // $c is the counter for how many items we still have to fetch (-1 is unlimited)
            $c = $numberOfItems > 0 ? $numberOfItems : -1;

            while ($objects->valid() && ($numberOfItems === 0 || $c > 0)) {
                $bucketObject = $objects->current();
                $objects->next();

                $objectName = $bucketObject->getName();

                try {
                    if (
                        !$this->applyFilterMethodsToDirectoryItem(
                            $filterMethods,
                            basename($objectName),
                            $this->getRootLevelFolder() . $objectName,
                            $this->getRootLevelFolder() . dirname($objectName)
                        )
                    ) {
                        continue;
                    }
                } catch (Exception\InvalidPathException $e) {
                }

                // Skip numbers of $start objects
                if ($start > 0) {
                    --$start;
                    continue;
                }

                // Add leading slash
                $objectName = $this->getRootLevelFolder() . $objectName;
                $folders[$objectName] = $objectName;

                // Decrement item counter to make sure we only return $numberOfItems
                // we cannot do this earlier in the method (unlike moving the iterator forward) because we only add the
                // item here
                --$c;
            }
        } catch (\Throwable $e) {
        }

        return $folders;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFilterMethodsToDirectoryItem(array $filterMethods, $itemName, $itemIdentifier, $parentIdentifier): bool
    {
        foreach ($filterMethods as $filter) {
            if (is_callable($filter)) {
                $result = $filter($itemName, $itemIdentifier, $parentIdentifier, [], $this);
                // We have to use -1 as the „don't include“ return value, as call_user_func() will return FALSE
                // If calling the method succeeded and thus we can't use that as a return value.
                if ($result === -1) {
                    return false;
                }
                if ($result === false) {
                    throw new \RuntimeException('Could not apply file/folder name filter ' . $filter[0] . '::' . $filter[1], 1476046425);
                }
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissions($identifier): array
    {
        if ($this->bucketObjects->isBucketRootFolder($identifier)) {
            $result = ['r' => true, 'w' => $this->bucket->isWritable()];
        } else {
            $result = ['r' => true, 'w' => true];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function hash($fileIdentifier, $hashAlgorithm): string
    {
        return $this->hashIdentifier($fileIdentifier);
    }

    /**
     * {@inheritdoc}
     */
    public function fileExistsInFolder($fileName, $folderIdentifier): bool
    {
        $fileIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier) . $fileName;

        return $this->bucketObjects->fileExists($fileIdentifier);
    }

    /**
     * {@inheritdoc}
     */
    public function renameFolder($folderIdentifier, $newName): array
    {
        $sourceFolderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier);
        $destinationParentFolderIdentifier = $this->namingHelper->normalizeFolderName(dirname($folderIdentifier));
        $newName = $this->namingHelper->normalizeFolderName($newName);

        return $this->moveFolderWithinStorage($sourceFolderIdentifier, $destinationParentFolderIdentifier, $newName);
    }

    /**
     * {@inheritdoc}
     */
    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName): bool
    {
        $targetFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier);
        $newFolderName = $this->namingHelper->normalizeFolderName($newFolderName);

        $sourceFolderIdentifier = $this->namingHelper->normalizeFolderName($sourceFolderIdentifier);
        $destinationFolderIdentifier = $this->namingHelper->normalizeFolderName($targetFolderIdentifier . $newFolderName);

        /**
         * @var StorageObject $object
         */
        foreach ($this->bucketObjects->getObjects($sourceFolderIdentifier, true, true, true) as $object) {
            $filename = substr($object->name(), strlen($sourceFolderIdentifier));
            $this->bucketOperations->copyFromTo($filename, $destinationFolderIdentifier . $filename);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function folderExistsInFolder($folderName, $folderIdentifier): bool
    {
        $folderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier) . $this->namingHelper->normalizeFolderName($folderName);

        return $this->folderExists($folderIdentifier);
    }

    /**
     * FINAL.
     *
     * @param string $folderIdentifier
     *
     * @return array
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException
     *
     * @todo Improvement Mtime and Creation Time of Bucket instead of current timestamp
     *
     * Returns information about a file.
     */
    public function getFolderInfoByIdentifier($folderIdentifier): array
    {
        $folderIdentifier = $this->namingHelper->normalizeFolderName($folderIdentifier);
        if ($this->bucketObjects->isBucketRootFolder($folderIdentifier)) {
            return [
                'identifier' => '/',
                'name' => '',
                'mtime' => time(),
                'ctime' => time(),
                'storage' => $this->storageUid,
            ];
        }
        $folder = $this->bucketObjects->getFolderObject($folderIdentifier);
        if ($folder === null) {
            throw new Exception\FolderDoesNotExistException('Folder "' . $folderIdentifier . '" does not exist.', 1314516810);
        }

        $updatedAt = $folder->getUpdatedAt();
        $createdAt = $folder->getCreatedAt();
        $mtime = strtotime((string)$updatedAt) ? false : time();
        $ctime = strtotime((string)$createdAt) ? false : time();

        return [
            'identifier' => $this->getRootLevelFolder() . $folderIdentifier,
            'name' => PathUtility::basename($folderIdentifier),
            'mtime' => $mtime,
            'ctime' => $ctime,
            'storage' => $this->storageUid,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFolderInFolder($folderName, $folderIdentifier): string
    {
        return
            $this->getRootLevelFolder() .
            $this->namingHelper->normalizeFolderName(
                $this->namingHelper->normalizeFolderName($folderIdentifier) . $folderName
            );
    }

    /**
     * {@inheritdoc}
     */
    public function countFoldersInFolder($folderIdentifier, $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks));
    }

    /**
     * {@inheritdoc}
     */
    public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = false, array $folderNameFilterCallbacks = [], $sort = '', $sortRev = false): array
    {
        return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $folderNameFilterCallbacks, false, true, $recursive, $sort, $sortRev);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultFolder(): string
    {
        $identifier = '/user_upload/';
        if (!$this->folderExists($identifier)) {
            $this->createFolder($identifier);
        }

        return $identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function createFolder(
        $newFolderName,
        $parentFolderIdentifier = '',
        $recursive = false
    ): string
    {
        $parentFolderIdentifier = $this->namingHelper->normalizeFolderName($parentFolderIdentifier);
        $newFolderName = $this->namingHelper->normalizeFolderName($newFolderName);
        $newFolderIdentifier = $this->namingHelper->normalizeFolderName($parentFolderIdentifier . $newFolderName);

        /**
         * dirname() => returns
         * / => /
         * /abc => /
         * /abc/ => /
         * /abc/def => /abc
         * /abc/def/ => /abc
         * /abc/def/ghi => /abc/def
         * /abc/def/ghi/ => /abc/def.
         */
        $parentFolder =
            $this->namingHelper->normalizeFolderName(
                dirname($this->getRootLevelFolder() . $newFolderIdentifier)
            );

        if ($recursive || $this->folderExists($parentFolder)) {
            $this->bucketOperations->mkdir($newFolderIdentifier);
        }

        return $this->getRootLevelFolder() . $newFolderIdentifier;
    }
}

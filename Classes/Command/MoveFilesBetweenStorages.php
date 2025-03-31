<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\Command;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 * Based on Visol/GoogleCloudStorage project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */
use Google\Cloud\Core\ServiceBuilder;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use InvalidArgumentException;
use Nextmotion\GoogleCloudStorageDriver\Driver\StorageDriver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\Driver\AbstractDriver;
use TYPO3\CMS\Core\Resource\Driver\DriverRegistry;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class MoveFilesBetweenStorages.
 */
class MoveFilesBetweenStorages extends Command
{
    public const WARNING = 'warning';

    protected SymfonyStyle $io;

    protected array $options = [];

    protected ResourceStorage $sourceStorage;

    protected ResourceStorage $targetStorage;

    protected array $missingFiles = [];

    protected string $tableName = 'sys_file';

    protected array $hasFolders = [];

    private array $configuration = [];

    /**
     * Configure the command by defining the name, options and arguments.
     */
    protected function configure(): void
    {
        $this
            ->setDescription(
                'Moving all files between two storages',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_OPTIONAL,
                'never prompt',
                false,
            )
            ->addOption(
                'filter',
                '',
                InputArgument::OPTIONAL,
                'Filter pattern with possible wild cards, --filter="%.pdf"',
                '',
            )
            ->addOption(
                'limit',
                '',
                InputArgument::OPTIONAL,
                'Add a possible offset, limit to restrain the number of files. e.g. 0,100',
                '',
            )
            ->addOption(
                'exclude',
                '',
                InputArgument::OPTIONAL,
                'Exclude pattern, can contain comma separated values e.g. --exclude="/apps/%,/_temp/%"',
                '',
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source storage identifier',
            )
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Target storage identifier',
            )
            ->setHelp(
                'Usage: ./vendor/bin/typo3 googleCloudStorage:move 1 2',
            )
        ;
    }

    /**
     * Initializes the command after the input has been bound and before the input
     * is validated.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->options = $input->getOptions();

        $this->sourceStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject(
            $input->getArgument('source'),
        );
        $this->targetStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject(
            $input->getArgument('target'),
        );

        // Compute the absolute file name of the file to move
        $this->configuration = $this->targetStorage->getConfiguration();
    }

    /**
     * Move file.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->targetStorage->getDriverType() !== 'GoogleCloudStorageDriver') {
            throw new InvalidArgumentException(
                sprintf('Target storage must be a Google Cloud Storage. Given: "%s:%s" of type "%s"',
                    $this->targetStorage->getUid(), $this->targetStorage->getName(), $this->targetStorage->getDriverType()),
            );
        }

        $this->log('Move content from <info>"%s:%s"</info> to <info>"%s:%s"</info>.', [
            $this->sourceStorage->getUid(),
            $this->sourceStorage->getName(),
            $this->targetStorage->getUid(),
            $this->targetStorage->getName(),
        ],
        );

        if ($input->getOption('force') === false) {
            $response = $this->io->confirm("Please make sure you have a backup of the source storage and the TYPO3 database.\n Do u really want to continue?", true);
            if (!$response) {
                $this->log('Transfer <error>not</error> started.');

                return 0;
            }
        }

        // we need the source & target driver for direct access
        $storageDriver = GeneralUtility::makeInstance(StorageDriver::class, $this->targetStorage->getConfiguration());
        $storageDriver->processConfiguration();
        $storageDriver->initialize();

        $driverRegistry = GeneralUtility::makeInstance(DriverRegistry::class);
        $sourceDriverClass = $driverRegistry->getDriverClass($this->sourceStorage->getDriverType());
        $sourceDriver = GeneralUtility::makeInstance($sourceDriverClass, $this->sourceStorage->getConfiguration());
        $sourceDriver->processConfiguration();
        $sourceDriver->initialize();

        $this->transfer(
            $this->sourceStorage,
            $sourceDriver,
            $storageDriver,
            $input->getOption('filter'),
            $input->getOption('exclude'),
            $input->getOption('limit'),
        );

        return 0;
    }

    /**
     * @param string $severity can be 'warning', 'error', 'success'
     */
    protected function log(string $message = '', array $arguments = [], $severity = ''): void
    {
        $formattedMessage = vsprintf($message, $arguments);
        if ($severity) {
            $this->io->{$severity}($formattedMessage);
        } else {
            $this->io->writeln($formattedMessage);
        }
    }

    private function transfer(ResourceStorage $resourceStorage, AbstractDriver $sourceDriver, StorageDriver $storageDriver, $filter, $excludes, $limits): void
    {
        $files = $this->getFiles($resourceStorage, $filter, $excludes, $limits);

        // sys_file can contain more than one entry with the same filename.
        // maybe thats the reason: https://forge.typo3.org/issues/72975
        $alreadyMoved = [];

        foreach ($files as $file) {
            /** @var File $fileObject */
            $fileObject = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObjectByStorageAndIdentifier(
                $resourceStorage->getUid(),
                $file['identifier'],
            );
            $this->log('');

            $this->log('<info>' . $fileObject->getIdentifier() . '</info>');
            if (isset($alreadyMoved[$fileObject->getIdentifier()])) {
                $this->log('[info               ] already moved');
                // Update the storage uid
                $this->log('[database           ] update storage id in sys_file');
                $this->updateDatabase(
                    $fileObject,
                    [
                        'storage' => $this->targetStorage->getUid(),
                    ],
                );
                continue;
            }

            try {
                // Download from source to temp
                $tempFileName = GeneralUtility::tempnam('gcp-move-file-', '.transfer');
                $this->log('[source -> temp     ] download to <info>' . $tempFileName . '</info>');
                file_put_contents($tempFileName, $fileObject->getContents());

                // Create parent folder if it doesn't exists
                $parentFolder = $fileObject->getParentFolder()->getIdentifier();
                if (!$storageDriver->folderExists($parentFolder)) {
                    $this->log('[destination        ] createFolder <info>' . $parentFolder . '</info>');
                    $storageDriver->createFolder($parentFolder, '/', true);
                }

                // Upload from temp to destination
                if (!$storageDriver->fileExists($fileObject->getIdentifier())) {
                    $destinationDir = dirname($fileObject->getIdentifier());
                    $destinationFilename = basename($fileObject->getIdentifier());
                    $this->log('[temp -> destination] upload <info>' . number_format(filesize($tempFileName), 0, ',', '.') . ' Bytes</info> to <info>' . $destinationDir . '/' . $destinationFilename . '</info>');
                    $storageDriver->addFile($tempFileName, $destinationDir, $destinationFilename, true);
                } else {
                    @unlink($tempFileName);
                    $this->log('[temp -> destination] file already exists. skip upload. deleted temp file.');
                }

                // Update the storage uid
                $this->log('[database           ] update storage id in sys_file');
                $this->updateDatabase(
                    $fileObject,
                    [
                        'storage' => $this->targetStorage->getUid(),
                    ],
                );

                // Delete file from the source
                $sourceDriver->deleteFile($fileObject->getIdentifier());
                $this->log('[source             ] deleted original file.');
                $alreadyMoved[$fileObject->getIdentifier()] = true;
            } catch (InsufficientFileAccessPermissionsException) {
                $this->log('[aborted            ] InsufficientFileAccessPermissionsException.');
            }
        }
    }

    /**
     * @param $storage
     * @param $filter
     * @param $excludes
     * @param $limits
     */
    protected function getFiles($storage, $filter, $excludes, $limits): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('storage', $storage->getUid()),
                $queryBuilder->expr()->eq('missing', 0),
            )
        ;

        // Possible custom filter
        if ($filter) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->expr()->literal($filter),
                ),
            );
        }

        // Possible custom exclude
        if ($excludes) {
            $expressions = GeneralUtility::trimExplode(',', $excludes);
            foreach ($expressions as $expression) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->notLike(
                        'identifier',
                        $queryBuilder->expr()->literal($expression),
                    ),
                );
            }
        }

        // Set a possible offset, limit
        if ($limits) {
            [$offsetOrLimit, $limit] = GeneralUtility::trimExplode(
                ',',
                $limits,
                true,
            );

            if ($limit !== null) {
                $queryBuilder->setFirstResult((int)$offsetOrLimit);
                $queryBuilder->setMaxResults((int)$limit);
            } else {
                $queryBuilder->setMaxResults((int)$offsetOrLimit);
            }
        }

        return $queryBuilder->executeQuery()->fetchAll();
    }

    /**
     * @return object|QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        return $connectionPool->getQueryBuilderForTable($this->tableName);
    }

    protected function getAbsolutePath(File $file): string
    {
        // Compute the absolute file name of the file to move
        $configuration = $this->sourceStorage->getConfiguration();
        $fileRelativePath = rtrim((string)$configuration['basePath'], '/') . $file->getIdentifier();

        return GeneralUtility::getFileAbsFileName($fileRelativePath);
    }

    protected function googleCloudStorageUploadFile(File $file): bool
    {
        return (bool)$this->getBucket()->upload(
            file_get_contents($this->getAbsolutePath($file)), // $fileObject->getContents()
            [
                'name' => GooglePathUtility::normalizeGooglePath($file->getIdentifier()),
            ],
        );
    }

    /**
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getBucket(): Bucket
    {
        $bucketName = $this->getConfiguration('bucketName');
        if ($bucketName === '' || $bucketName === '0') {
            throw new Exception(
                'Missing the bucket name. Please add one in the driver configuration record.',
                1446553056,
            );
        }

        return $this->getClient()->bucket($bucketName);
    }

    public function getConfiguration(string $key): string
    {
        return isset($this->configuration[$key])
            ? (string)$this->configuration[$key]
            : '';
    }

    /**
     * Initialize the dear client.
     *
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getClient(): StorageClient
    {
        $configuredPrivateKeyFile = $this->getConfiguration('privateKeyJsonPathAndFileName');
        if ($configuredPrivateKeyFile === '' || $configuredPrivateKeyFile === '0') {
            throw new Exception(
                'Missing the Google Cloud Storage private key stored in a JSON file. Next step is to add one in the driver record.',
                1446553055,
            );
        }

        if (!str_starts_with($configuredPrivateKeyFile, '/')) {
            $privateKeyPathAndFilename = realpath(
                Environment::getPublicPath() . $configuredPrivateKeyFile,
            );
        } else {
            $privateKeyPathAndFilename = $configuredPrivateKeyFile;
        }

        if (!file_exists($privateKeyPathAndFilename)) {
            throw new Exception(
                sprintf(
                    'The Google Cloud Storage private key file "%s" does not exist. Either the file is missing or you need to adjust your settings.',
                    $privateKeyPathAndFilename,
                ),
                1446553054,
            );
        }

        $serviceBuilder = new ServiceBuilder(
            [
                'keyFilePath' => $privateKeyPathAndFilename,
            ],
        );

        return $serviceBuilder->storage();
    }

    protected function updateDatabase(File $file, array $values): int
    {
        $connection = $this->getConnection();

        return $connection->update(
            $this->tableName,
            $values,
            [
                'uid' => $file->getUid(),
            ],
        );
    }

    protected function getConnection(): \TYPO3\CMS\Core\Database\Connection
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        return $connectionPool->getConnectionForTable($this->tableName);
    }

    protected function writeLog(string $type, array $files): void
    {
        $logFileName = sprintf(
            '/tmp/%s-files-%s-%s-log',
            $type,
            getmypid(),
            uniqid(),
        );

        // Write log file
        file_put_contents($logFileName, var_export($files, true));

        // Display the message
        $this->log(
            'Pay attention, I have found %s %s files. A log file has been written at %s',
            [
                $type,
                count($files),
                $logFileName,
            ],
            self::WARNING,
        );
    }

    protected function warning(string $message = '', array $arguments = []): void
    {
        $this->log($message, $arguments, self::WARNING);
    }
}

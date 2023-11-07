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

class SimpleBucketObject
{
    public const TYPE_FILE = 0;
    public const TYPE_FOLDER = 1;

    /**
     * @var string full path of file or folder
     */
    private string $name;

    /**
     * @var string Determinated content type by google
     */
    private string $contentType;

    /**
     * @var int Filesize in byte
     */
    private int $filesize;

    /**
     * @var int Timestamp
     */
    private int $created_at;

    /**
     * @var int Timestamp
     */
    private int $updated_at;

    /**
     * @var int See TYPE_FILE OR TYPE_FOLDER
     */
    private int $type;

    /**
     * SimpleBucketObject constructor.
     *
     * @param mixed $config
     */
    public function __construct($config)
    {
        $name = $config['name'] ?? '';
        $type = $config['type'];
        $contentType = $config['contentType'] ?? 0;
        $filesize = $config['filesize'] ?? 0;
        $created_at = $config['created_at'] ?? 0;
        $updated_at = $config['updated_at'] ?? 0;

        $this->name = (string)$name;
        $this->type = (int) $type;
        $this->contentType = (string)$contentType;
        $this->filesize = (int)$filesize;
        $this->created_at = (int)$created_at;
        $this->updated_at = (int)$updated_at;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * @return int
     */
    public function getFilesize(): int
    {
        return (int)$this->filesize;
    }

    /**
     * @return int
     */
    public function getCreatedAt(): int
    {
        return $this->created_at;
    }

    /**
     * @return int
     */
    public function getUpdatedAt(): int
    {
        return $this->updated_at;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getRw()
    {
        return 'rw';
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'is_dir' => $this->isFolder(),
            'is_file' => $this->isFile(),
            'contenttype' => $this->contentType,
            'filesize' => $this->filesize,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return bool
     */
    public function isFolder(): bool
    {
        return $this->type === self::TYPE_FOLDER;
    }

    /**
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }
}

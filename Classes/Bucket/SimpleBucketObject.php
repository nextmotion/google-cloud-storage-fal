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
    const TYPE_FILE = 0;
    const TYPE_FOLDER = 1;

    /**
     * @var string full path of file or folder
     */
    private $name;

    /**
     * @var string Determinated content type by google
     */
    private $contentType;

    /**
     * @var int Filesize in byte
     */
    private $filesize;

    /**
     * @var int Timestamp
     */
    private $created_at;

    /**
     * @var int Timestamp
     */
    private $updated_at;

    /**
     * @var int See TYPE_FILE OR TYPE_FOLDER
     */
    private $type;

    /**
     * SimpleBucketObject constructor.
     *
     * @param mixed $config
     */
    public function __construct($config)
    {
        $this->name = $config['name'];
        $this->type = $config['type'];
        $this->contentType = $config['contentType'] ?? 0;
        $this->filesize = $config['filesize'] ?? 0;
        $this->created_at = $config['created_at'] ?? 0;
        $this->updated_at = $config['updated_at'] ?? 0;
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
    public function isFolder()
    {
        return $this->type == self::TYPE_FOLDER;
    }

    /**
     * @return bool
     */
    public function isFile()
    {
        return $this->type == self::TYPE_FILE;
    }
}

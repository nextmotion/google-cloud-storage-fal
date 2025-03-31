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

/**
 * Class NamingHelper.
 */
class NamingHelper
{
    private string $dirDelimiter = '/';

    public function getDirDelimiter(): string
    {
        return $this->dirDelimiter;
    }

    /**
     * Normalize directory strings.
     *
     * Paths are translated like this:
     * '/'    to ''
     * 'abc'  to 'abc/'
     * 'abc/' to 'abc/'
     * '/abc/ to 'abc/'
     *
     * @param string $folderName
     *
     * @return string Empty string for root| diretories with a trailing slash
     */
    public function normalizeFolderName($folderName): string
    {
        $folderName = trim($folderName, $this->dirDelimiter);
        if ($folderName === '.' || $folderName === '') {
            return '';
        }

        return $folderName . $this->dirDelimiter;
    }

    /**
     * Normalize file identifier strings.
     *
     * Paths are translated like this:
     * 'abc'       to 'abc'
     * '/abc'      to 'abc'
     * '/abc/def'  to 'abc/def'
     *
     * @param string|null $fileName
     */
    public function normalizeFileName($fileName): string
    {
        return trim((string)$fileName, $this->dirDelimiter);
    }
}

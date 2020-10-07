<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\Cache;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

/**
 * Class BucketCache.
 */
class BucketCache
{
    private $data = [];

    public function clear()
    {
        $this->data = [];
    }

    public function get($signature)
    {
        return $this->data[$this->getUniqueKey($signature)];
    }

    public function getUniqueKey($param)
    {
        return sha1(var_export($param, true));
    }

    public function exists($signature)
    {
        return isset($this->data[$this->getUniqueKey($signature)]);
    }

    public function set($signature, $data)
    {
        return $this->data[$this->getUniqueKey($signature)] = $data;
    }
}

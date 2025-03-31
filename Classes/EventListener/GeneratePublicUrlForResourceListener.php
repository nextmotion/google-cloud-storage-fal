<?php

declare(strict_types=1);

namespace Nextmotion\GoogleCloudStorageDriver\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "google_cloud_storage_fal" by next.motion.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Nextmotion\GoogleCloudStorageDriver\Driver\StorageDriver;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;

class GeneratePublicUrlForResourceListener
{
    public function __invoke(GeneratePublicUrlForResourceEvent $generatePublicUrlForResourceEvent): void
    {
        if (!($generatePublicUrlForResourceEvent->getDriver() instanceof StorageDriver)) {
            return;
        }

        /** @var non-empty-string $identifier */
        $identifier = $generatePublicUrlForResourceEvent->getResource()->getIdentifier();

        /** @var StorageDriver $storageDriver */
        $storageDriver = $generatePublicUrlForResourceEvent->getDriver();
        $publicUrl = $storageDriver->getPublicUrl($identifier);
        $generatePublicUrlForResourceEvent->setPublicUrl($publicUrl);
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\SyncOrder;

enum Statuses: string
{
    case NOT_REGISTERED = 'not-registered';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SYNCED = 'synced';
    case RETRY = 'retry';
    case PARTIAL = 'partial';
    case ERROR = 'error';

    /**
     * @return bool
     */
    public function canInitiateSync(): bool
    {
        // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
        return match ($this) {
            self::QUEUED,
            self::PARTIAL,
            self::RETRY => true,
            self::NOT_REGISTERED,
            self::PROCESSING,
            self::SYNCED,
            self::ERROR => false,
        };
    }
}

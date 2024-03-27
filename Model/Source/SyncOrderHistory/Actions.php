<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory;

enum Actions: string
{
    case MIGRATE = 'migrate';
    case QUEUE = 'queue';
    case PROCESS_START = 'process-start';
    case PROCESS_END = 'process-end';
}

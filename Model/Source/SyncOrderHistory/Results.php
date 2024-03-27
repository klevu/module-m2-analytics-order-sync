<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory;

enum Results: string
{
    case NOOP = 'noop';
    case SUCCESS = 'success';
    case ERROR = 'error';
}

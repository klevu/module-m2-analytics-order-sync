<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Unit\Model\Source\SyncOrder;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use PHPUnit\Framework\TestCase;

class StatusesTest extends TestCase
{
    public function testCanInitiateSync(): void
    {
        $this->assertFalse(Statuses::NOT_REGISTERED->canInitiateSync());
        $this->assertFalse(Statuses::PROCESSING->canInitiateSync());
        $this->assertFalse(Statuses::SYNCED->canInitiateSync());
        $this->assertFalse(Statuses::ERROR->canInitiateSync());

        $this->assertTrue(Statuses::QUEUED->canInitiateSync());
        $this->assertTrue(Statuses::RETRY->canInitiateSync());
        $this->assertTrue(Statuses::PARTIAL->canInitiateSync());
    }
}

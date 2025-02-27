<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Cron;

use Klevu\AnalyticsOrderSyncApi\Api\MigrateLegacyOrderSyncRecordsInterface;

class MigrateLegacyOrderSyncRecords
{
    public const JOB_CODE = 'klevu_analytics_migrate_legacy_order_sync_records';

    /**
     * @var MigrateLegacyOrderSyncRecordsInterface|mixed
     */
    private readonly MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService;

    /**
     * @param MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService
     */
    public function __construct(
        MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService,
    ) {
        $this->migrateLegacyOrderSyncRecordsService = $migrateLegacyOrderSyncRecordsService;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->migrateLegacyOrderSyncRecordsService->executeForAllStores();
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Observer\Admin\System\Config;

use Klevu\AnalyticsOrderSync\Service\Action\ScheduleMigrateLegacyOrderSyncRecordsCron;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class MigrateLegacyOrderSyncRecordsAfterIntegration implements ObserverInterface
{
    /**
     * @var ScheduleMigrateLegacyOrderSyncRecordsCron
     */
    private readonly ScheduleMigrateLegacyOrderSyncRecordsCron $scheduleMigrateLegacyOrderSyncRecordsCronAction;

    /**
     * @param ScheduleMigrateLegacyOrderSyncRecordsCron $scheduleMigrateLegacyOrderSyncRecordsCronAction
     */
    public function __construct(
        ScheduleMigrateLegacyOrderSyncRecordsCron $scheduleMigrateLegacyOrderSyncRecordsCronAction,
    ) {
        $this->scheduleMigrateLegacyOrderSyncRecordsCronAction = $scheduleMigrateLegacyOrderSyncRecordsCronAction;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(
        Observer $observer, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    ): void {
        $this->scheduleMigrateLegacyOrderSyncRecordsCronAction->execute();
    }
}

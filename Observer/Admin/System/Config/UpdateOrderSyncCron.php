<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Observer\Admin\System\Config;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ConsolidateCronConfigSettingsActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class UpdateOrderSyncCron implements ObserverInterface
{
    /**
     * @var ConsolidateCronConfigSettingsActionInterface
     */
    private readonly ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction;

    /**
     * @param ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction
     */
    public function __construct(
        ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction,
    ) {
        $this->consolidateCronConfigSettingsAction = $consolidateCronConfigSettingsAction;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $changedPaths = (array)$observer->getData('changed_paths');
        if (
            !in_array(Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY, $changedPaths, true)
            && !in_array(Constants::XML_PATH_ORDER_SYNC_CRON_EXPR, $changedPaths, true)
        ) {
            return;
        }

        $this->consolidateCronConfigSettingsAction->execute();
    }
}

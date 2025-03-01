<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Setup\Patch\Data;

use Klevu\AnalyticsOrderSync\Service\Action\ScheduleMigrateLegacyOrderSyncRecordsCron;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateLegacyOrderSyncRecords implements DataPatchInterface
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
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return $this
     */
    public function apply(): self
    {
        $this->scheduleMigrateLegacyOrderSyncRecordsCronAction->execute();

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }
}

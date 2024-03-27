<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Action;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\Options\CronFrequency;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ConsolidateCronConfigSettingsActionInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
use Magento\Framework\Data\OptionSourceInterface;

class ConsolidateCronConfigSettings implements ConsolidateCronConfigSettingsActionInterface
{
    use OptionSourceToHashTrait;

    /**
     * @var ReinitableConfigInterface
     */
    private readonly ReinitableConfigInterface $reinitableConfig;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var ConfigWriter
     */
    private readonly ConfigWriter $configWriter;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $cronFrequencySource;

    /**
     * @param ReinitableConfigInterface $reinitableConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigWriter $configWriter
     * @param OptionSourceInterface $cronFrequencySource
     */
    public function __construct(
        ReinitableConfigInterface $reinitableConfig,
        ScopeConfigInterface $scopeConfig,
        ConfigWriter $configWriter,
        OptionSourceInterface $cronFrequencySource,
    ) {
        $this->reinitableConfig = $reinitableConfig;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cronFrequencySource = $cronFrequencySource;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $cronFrequencyOptionsHash = $this->getHashForOptionSource(
            optionSource: $this->cronFrequencySource,
        );

        $syncFrequency = $this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null,
        );
        $cronExpr = $this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null,
        );

        $needToSave = [
            'frequency' => false,
            'expr' => false,
        ];
        switch (true) {
            // Set disabled when both fields are empty
            case !$cronExpr && (CronFrequency::OPTION_CUSTOM === $syncFrequency):
                $syncFrequency = CronFrequency::OPTION_DISABLED;
                $needToSave['frequency'] = true;
                $cronExpr = CronFrequency::OPTION_DISABLED;
                $needToSave['expr'] = true;
                break;

            // Custom schedule same as pre-existing option, so set frequency value
            case !$syncFrequency && ($cronFrequencyOptionsHash[$cronExpr] ?? null):
                $syncFrequency = $cronExpr;
                $needToSave['frequency'] = true;
                break;

            // Sync Frequency selected, so update cron expression
            case $syncFrequency && ($syncFrequency !== $cronExpr):
            case !$cronExpr && (CronFrequency::OPTION_DISABLED === $syncFrequency):
                $cronExpr = $syncFrequency;
                $needToSave['expr'] = true;
                break;
        }

        if (!array_filter($needToSave)) {
            return;
        }

        if ($needToSave['frequency']) {
            $this->configWriter->save(
                path: Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                value: $syncFrequency,
                scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                scopeId: 0,
            );
        }

        if ($needToSave['expr']) {
            $this->configWriter->save(
                path: Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                value: $cronExpr,
                scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                scopeId: 0,
            );
        }

        $this->reinitableConfig->reinit();
    }
}

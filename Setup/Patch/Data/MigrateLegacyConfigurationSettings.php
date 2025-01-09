<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Setup\Patch\Data;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\Options\CronFrequency;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ConsolidateCronConfigSettingsActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateLegacyConfigurationSettings implements DataPatchInterface
{
    public const XML_PATH_LEGACY_SYNC_ENABLED = 'klevu_search/product_sync/order_sync_enabled';
    public const XML_PATH_LEGACY_SYNC_FREQUENCY = 'klevu_search/product_sync/order_sync_frequency';
    public const XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM = 'klevu_search/product_sync/order_sync_frequency_custom';
    public const XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE = 'klevu_search/developer/orderip';
    public const XML_PATH_LEGACY_ORDERS_WITH_SAME_IP = 'klevu_search/notification/orders_with_same_ip';

    /**
     * @var ResourceConnection
     */
    private readonly ResourceConnection $resourceConnection;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var WriterInterface
     */
    private readonly WriterInterface $configWriter;
    /**
     * @var ConsolidateCronConfigSettingsActionInterface
     */
    private readonly ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction;
    /**
     * @var mixed[]|null
     */
    private ?array $legacyConfigSettings = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        ConsolidateCronConfigSettingsActionInterface $consolidateCronConfigSettingsAction,
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->consolidateCronConfigSettingsAction = $consolidateCronConfigSettingsAction;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
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
        $this->migrateOrderSyncEnabled();
        $this->migrateOrderSyncCronConfiguration();
        $this->migrateIpAddressAttribute();

        return $this;
    }

    /**
     * @return void
     */
    private function migrateOrderSyncEnabled(): void
    {
        $this->renameConfigValue(
            fromPath: static::XML_PATH_LEGACY_SYNC_ENABLED,
            toPath: Constants::XML_PATH_ORDER_SYNC_ENABLED,
        );
    }

    /**
     * @return void
     */
    private function migrateOrderSyncCronConfiguration(): void
    {
        $legacySettings = $this->getLegacyConfigSettings();

        $legacyCronFrequency = $legacySettings[static::XML_PATH_LEGACY_SYNC_FREQUENCY]['default'][0]
            ?? null;
        $legacyCronFrequencyCustom = $legacySettings[static::XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM]['default'][0]
            ?? null;

        if (!$legacyCronFrequency && !$legacyCronFrequencyCustom) {
            return;
        }

        $legacyCronFrequency ??= '0 2 * * *';

        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
            value: $legacyCronFrequencyCustom ? CronFrequency::OPTION_CUSTOM : $legacyCronFrequency,
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );
        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
            value: $legacyCronFrequencyCustom ?: $legacyCronFrequency,
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );
        if (method_exists($this->scopeConfig, 'clean')) {
            $this->scopeConfig->clean();
        }
        $this->consolidateCronConfigSettingsAction->execute();
    }

    /**
     * @return void
     */
    private function migrateIpAddressAttribute(): void
    {
        $this->renameConfigValue(
            fromPath: static::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
            toPath: Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
        );
    }

    /**
     * @param string $fromPath
     * @param string $toPath
     * @return void
     */
    private function renameConfigValue(
        string $fromPath,
        string $toPath,
    ): void {
        $legacyConfigSettings = $this->getLegacyConfigSettings();
        if (!($legacyConfigSettings[$fromPath] ?? null)) {
            return;
        }

        foreach ($legacyConfigSettings[$fromPath] as $scope => $scopeValues) {
            foreach ($scopeValues as $scopeId => $value) {
                $this->configWriter->save(
                    path: $toPath,
                    value: $value,
                    scope: $scope,
                    scopeId: $scopeId,
                );
            }
        }
    }

    /**
     * @return mixed[]
     */
    private function getLegacyConfigSettings(): array
    {
        if (null === $this->legacyConfigSettings) {
            $configTableName = $this->resourceConnection->getTableName('core_config_data');

            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select();
            $select->from($configTableName);
            $select->where(
                cond: 'path IN (?)',
                value: [
                    static::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
                    static::XML_PATH_LEGACY_ORDERS_WITH_SAME_IP,
                    static::XML_PATH_LEGACY_SYNC_ENABLED,
                    static::XML_PATH_LEGACY_SYNC_FREQUENCY,
                    static::XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM,
                ],
            );

            $this->legacyConfigSettings = [];
            $result = $connection->fetchAssoc($select);
            foreach ($result as $row) {
                $this->legacyConfigSettings[$row['path']][$row['scope']][$row['scope_id']] = $row['value'];
            }
        }

        return $this->legacyConfigSettings;
    }
}

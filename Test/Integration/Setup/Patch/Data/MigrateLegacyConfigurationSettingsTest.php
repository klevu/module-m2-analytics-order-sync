<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Setup\Patch\Data;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\Options\CronFrequency;
use Klevu\AnalyticsOrderSync\Setup\Patch\Data\MigrateLegacyConfigurationSettings;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method MigrateLegacyConfigurationSettings instantiateTestObject(?array $arguments = null)
 * @method MigrateLegacyConfigurationSettings instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MigrateLegacyConfigurationSettingsTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use WebsiteTrait;
    use StoreTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var ScopeConfigInterface|null
     */
    private ?ScopeConfigInterface $scopeConfig = null;
    /**
     * @var ConfigResource|null
     */
    private ?ConfigResource $configResource = null;
    /**
     * @var ConfigWriter|null
     */
    private ?ConfigWriter $configWriter = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MigrateLegacyConfigurationSettings::class;
        $this->interfaceFqcn = DataPatchInterface::class;

        $this->scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $this->configResource = $this->objectManager->get(ConfigResource::class);
        $this->configWriter = $this->objectManager->get(ConfigWriter::class);

        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);

        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
        $this->createWebsite([
            'code' => 'klevu_analytics_test_website_1',
            'key' => 'test_website_1',
        ]);
        $testWebsite = $this->websiteFixturesPool->get('test_website_1');
        $this->createStore([
            'code' => 'klevu_analytics_test_store_2',
            'key' => 'test_store_2',
            'website_id' => $testWebsite->getId(),
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    public function testGetDependencies(): void
    {
        $dependencies = MigrateLegacyConfigurationSettings::getDependencies();

        $this->assertSame([], $dependencies);
    }

    public function testGetAliases(): void
    {
        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();
        $aliases = $migrateLegacyConfigurationSettingsPatch->getAliases();

        $this->assertSame([], $aliases);
    }
    
    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncEnabled_EnabledGlobal(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
            value: '1',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncEnabled_DisabledGlobal(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
            value: '0',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncEnabled_DisabledWebsite(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
            value: '0',
            scope: ScopeInterface::SCOPE_WEBSITES,
            scopeId: 1,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncEnabled_DisabledStore(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
            value: '0',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $testStore1->getId(),
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertFalse(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncCronConfiguration_NotCustom(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY,
            value: '0 */12 * * *',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM,
            value: '',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
        $this->assertSame(
            '0 */12 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @testWith [""]
     *           ["0 *\/12 * * *"]
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncCronConfiguration_Custom(
        string $legacySyncFrequency,
    ): void {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY,
            value: $legacySyncFrequency,
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM,
            value: '*/7 2,4,5 * * *',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertSame(
            CronFrequency::OPTION_CUSTOM,
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            '*/7 2,4,5 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            CronFrequency::OPTION_CUSTOM,
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            '*/7 2,4,5 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            CronFrequency::OPTION_CUSTOM,
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            '*/7 2,4,5 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            CronFrequency::OPTION_CUSTOM,
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
        $this->assertSame(
            '*/7 2,4,5 * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @testWith [null, null]
     *           ["", null]
     *           [null, ""]
     *           ["", ""]
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateOrderSyncCronConfiguration_None(
        ?string $legacySyncFrequency,
        ?string $legacySyncFrequencyCustom,
    ): void {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        if (null !== $legacySyncFrequency) {
            $this->configWriter->save(
                path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY,
                value: $legacySyncFrequency,
                scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                scopeId: 0,
            );
        }
        if (null !== $legacySyncFrequencyCustom) {
            $this->configWriter->save(
                path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_FREQUENCY_CUSTOM,
                value: $legacySyncFrequencyCustom,
                scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                scopeId: 0,
            );
        }
        // Ensure other settings still get migrated
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_SYNC_ENABLED,
            value: '0',
            scope: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            scopeId: 0,
        );
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
        );
        
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $storeIds = [
            '1',
            $testStore1->getId(),
            $testStore2->getId(),
        ];

        $this->assertSame(
            expected: '*/5 * * * *',
            actual: $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            expected: '*/5 * * * *',
            actual: $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertFalse(
            condition: $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            expected: 'x_forwarded_for',
            actual: $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        foreach ($storeIds as $storeId) {
            $this->assertSame(
                expected: '*/5 * * * *',
                actual: $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
            $this->assertSame(
                expected: '*/5 * * * *',
                actual: $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
            $this->assertFalse(
                condition: $this->scopeConfig->isSetFlag(
                    Constants::XML_PATH_ORDER_SYNC_ENABLED,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
            $this->assertSame(
                expected: 'x_forwarded_for',
                actual: $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
        }
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateIpAddressAttribute_Global(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateIpAddressAttribute_Website(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
            scope: ScopeInterface::SCOPE_WEBSITES,
            scopeId: 1,
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testApply_MigrateIpAddressAttribute_Store(): void
    {
        $this->deleteExistingKlevuConfig();

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $this->configWriter->save(
            path: MigrateLegacyConfigurationSettings::XML_PATH_LEGACY_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $testStore1->getId(),
        );
        $this->scopeConfig->clean();

        $this->assertInitialConfig();

        $migrateLegacyConfigurationSettingsPatch = $this->instantiateTestObject();

        $migrateLegacyConfigurationSettingsPatch->apply();
        $this->scopeConfig->clean();

        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                '1',
            ),
        );
        $this->assertSame(
            'x_forwarded_for',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore1->getId(),
            ),
        );
        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeInterface::SCOPE_STORES,
                $testStore2->getId(),
            ),
        );
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function deleteExistingKlevuConfig(): void
    {
        $connection = $this->configResource->getConnection();
        $connection->delete(
            $this->configResource->getMainTable(),
            [
                'path like "klevu%"',
            ],
        );

        $this->scopeConfig->clean();
    }

    /**
     * @return void
     */
    private function assertInitialConfig(): void
    {
        $this->assertTrue(
            $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            '*/5 * * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            '*/5 * * * *',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );
        $this->assertSame(
            'remote_ip',
            $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ),
        );

        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');
        $storeIds = [
            '1',
            $testStore1->getId(),
            $testStore2->getId(),
        ];
        foreach ($storeIds as $storeId) {
            $this->assertTrue(
                $this->scopeConfig->isSetFlag(
                    Constants::XML_PATH_ORDER_SYNC_ENABLED,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );

            $this->assertSame(
                '*/5 * * * *',
                $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
            $this->assertSame(
                '*/5 * * * *',
                $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
            $this->assertSame(
                'remote_ip',
                $this->scopeConfig->getValue(
                    Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                    ScopeInterface::SCOPE_STORES,
                    $storeId,
                ),
            );
        }
    }
}

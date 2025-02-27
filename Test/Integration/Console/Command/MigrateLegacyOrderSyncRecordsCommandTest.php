<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Console\Command;

use Klevu\AnalyticsOrderSync\Console\Command\MigrateLegacyOrderSyncRecordsCommand;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Api\MigrateLegacyOrderSyncRecordsInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @method MigrateLegacyOrderSyncRecordsCommand instantiateTestObject(?array $arguments = null)
 * @magentoAppIsolation enabled
 */
class MigrateLegacyOrderSyncRecordsCommandTest extends TestCase
{
    use ObjectInstantiationTrait;
    use StoreTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var ConfigWriterInterface|null
     */
    private ?ConfigWriterInterface $configWriter = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MigrateLegacyOrderSyncRecordsCommand::class;
        // newrelic-describe-commands globs onto Console commands
        $this->expectPlugins = true;

        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->configWriter = $this->objectManager->get(ConfigWriterInterface::class);

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->storeFixturesPool->rollback();
    }

    /**
     * @testWith [["-9999"]]
     *           [["abcde"]]
     *           [["3.14"]]
     *           [["foo", "-9999"]]
     *
     * @param mixed[] $storeIds
     *
     * @return void
     */
    public function testExecute_InvalidStoreIds(
        array $storeIds,
    ): void {
        $migrateLegacyOrderSyncRecordsService = $this->getMockMigrateLegacyOrderSyncRecordsService();
        $migrateLegacyOrderSyncRecordsService->expects($this->never())
            ->method('executeForStoreId');
        $migrateLegacyOrderSyncRecordsService->expects($this->never())
            ->method('executeForAllStores');

        $migrateLegacyOrderSyncRecordsCommand = $this->instantiateTestObject(
            arguments: [
                'migrateLegacyOrderSyncRecordsService' => $migrateLegacyOrderSyncRecordsService,
            ],
        );
        $this->assertSame(
            expected: 'klevu:analytics:migrate-legacy-order-sync-records',
            actual: $migrateLegacyOrderSyncRecordsCommand->getName(),
        );

        $tester = new CommandTester(
            command: $migrateLegacyOrderSyncRecordsCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => $storeIds,
            ],
        );
        $this->assertSame(
            expected: MigrateLegacyOrderSyncRecordsCommand::INVALID,
            actual: $statusCode,
        );

        $this->assertStringContainsString(
            needle: 'All specified store ids are invalid or not enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Check analytics logs for further details',
            haystack: $tester->getDisplay(),
        );
    }

    public function testExecute_StoreIds(): void
    {
        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 1,
            scope: 'default',
            scopeId: 0,
        );

        $defaultWebsite = $this->storeManager->getWebsite();
        $websiteId = (int)$defaultWebsite->getId();

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_1',
                'key' => 'klevu_analytics_test_store_1',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_analytics_test_store_1');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeFixture1->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_2',
                'key' => 'klevu_analytics_test_store_2',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture2 = $this->storeFixturesPool->get('klevu_analytics_test_store_2');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-9876543210',
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 0,
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_3',
                'key' => 'klevu_analytics_test_store_3',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture3 = $this->storeFixturesPool->get('klevu_analytics_test_store_3');
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture3->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_4',
                'key' => 'klevu_analytics_test_store_4',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture4 = $this->storeFixturesPool->get('klevu_analytics_test_store_4');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1122334455',
            scope: 'stores',
            scopeId: $storeFixture4->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture4->getId(),
        );

        $migrateLegacyOrderSyncRecordsService = $this->getMockMigrateLegacyOrderSyncRecordsService();
        $migrateLegacyOrderSyncRecordsService->expects($this->once())
            ->method('executeForStoreId')
            ->with(
                (int)$storeFixture1->getId(),
            );
        $migrateLegacyOrderSyncRecordsService->expects($this->never())
            ->method('executeForAllStores');

        $migrateLegacyOrderSyncRecordsCommand = $this->instantiateTestObject(
            arguments: [
                'migrateLegacyOrderSyncRecordsService' => $migrateLegacyOrderSyncRecordsService,
            ],
        );
        $this->assertSame(
            expected: 'klevu:analytics:migrate-legacy-order-sync-records',
            actual: $migrateLegacyOrderSyncRecordsCommand->getName(),
        );

        $tester = new CommandTester(
            command: $migrateLegacyOrderSyncRecordsCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (int)$storeFixture1->getId(),
                    (int)$storeFixture2->getId(),
                    (int)$storeFixture3->getId(),
                ],
            ],
        );
        $this->assertSame(
            expected: MigrateLegacyOrderSyncRecordsCommand::SUCCESS,
            actual: $statusCode,
        );

        $this->assertMatchesRegularExpression(
            pattern: sprintf(
                '/Migrating legacy order sync records for store ID %s... Complete in \d+\.\d{2} seconds./',
                $storeFixture1->getId(),
            ),
            string: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Check analytics logs for further details',
            haystack: $tester->getDisplay(),
        );
    }

    public function testExecute_NoStoreIds(): void
    {
        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 1,
            scope: 'default',
            scopeId: 0,
        );

        $defaultWebsite = $this->storeManager->getWebsite();
        $websiteId = (int)$defaultWebsite->getId();

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_1',
                'key' => 'klevu_analytics_test_store_1',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_analytics_test_store_1');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeFixture1->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_2',
                'key' => 'klevu_analytics_test_store_2',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture2 = $this->storeFixturesPool->get('klevu_analytics_test_store_2');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-9876543210',
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 0,
            scope: 'stores',
            scopeId: $storeFixture2->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_3',
                'key' => 'klevu_analytics_test_store_3',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture3 = $this->storeFixturesPool->get('klevu_analytics_test_store_3');
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture3->getId(),
        );

        $this->createStore(
            storeData: [
                'code' => 'klevu_analytics_test_store_4',
                'key' => 'klevu_analytics_test_store_4',
                'website_id' => $websiteId,
                'with_sequence' => true,
            ],
        );
        $storeFixture4 = $this->storeFixturesPool->get('klevu_analytics_test_store_4');
        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1122334455',
            scope: 'stores',
            scopeId: $storeFixture4->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture4->getId(),
        );

        $migrateLegacyOrderSyncRecordsService = $this->getMockMigrateLegacyOrderSyncRecordsService();
        $migrateLegacyOrderSyncRecordsService->expects($this->never())
            ->method('executeForStoreId');
        $migrateLegacyOrderSyncRecordsService->expects($this->once())
            ->method('executeForAllStores');

        $migrateLegacyOrderSyncRecordsCommand = $this->instantiateTestObject(
            arguments: [
                'migrateLegacyOrderSyncRecordsService' => $migrateLegacyOrderSyncRecordsService,
            ],
        );
        $this->assertSame(
            expected: 'klevu:analytics:migrate-legacy-order-sync-records',
            actual: $migrateLegacyOrderSyncRecordsCommand->getName(),
        );

        $tester = new CommandTester(
            command: $migrateLegacyOrderSyncRecordsCommand,
        );

        $statusCode = $tester->execute(
            input: [],
        );
        $this->assertSame(
            expected: MigrateLegacyOrderSyncRecordsCommand::SUCCESS,
            actual: $statusCode,
        );

        $this->assertMatchesRegularExpression(
            pattern: '/Migrating legacy order sync records for all integrated stores... '
                . 'Complete in \d+\.\d{2} seconds./',
            string: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Check analytics logs for further details',
            haystack: $tester->getDisplay(),
        );
    }

    /**
     * @return MockObject
     */
    private function getMockMigrateLegacyOrderSyncRecordsService(): MockObject
    {
        return $this->getMockBuilder(MigrateLegacyOrderSyncRecordsInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

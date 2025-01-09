<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Console\Command;

use Klevu\AnalyticsApi\Api\Data\ProcessEventsResultInterface;
use Klevu\AnalyticsApi\Api\Data\ProcessEventsResultInterfaceFactory;
use Klevu\AnalyticsApi\Api\ProcessEventsServiceInterface;
use Klevu\AnalyticsApi\Model\Source\ProcessEventsResultStatuses;
use Klevu\AnalyticsOrderSync\Console\Command\SyncOrdersCommand;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method SyncOrdersCommand instantiateTestObject(?array $arguments = null)
 * @magentoAppIsolation enabled
 */
class SyncOrdersCommandTest extends TestCase
{
    use CreateOrderInStoreTrait;
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;
    use WebsiteTrait;

    private const FIXTURE_REST_AUTH_KEY = 'ABCDE12345';

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var SyncEnabledStoresProviderInterface|null
     */
    private ?SyncEnabledStoresProviderInterface $syncEnabledStoresProvider = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var ProcessEventsResultInterfaceFactory|null
     */
    private ?ProcessEventsResultInterfaceFactory $processEventsResultFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrdersCommand::class;
        // newrelic-describe-commands globs onto Console commands
        $this->expectPlugins = true;

        $this->syncEnabledStoresProvider = $this->objectManager->get(SyncEnabledStoresProviderInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->processEventsResultFactory = $this->objectManager->get(ProcessEventsResultInterfaceFactory::class);

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
        $this->orderFixtures = [];

        $this->disableKlevuForExistingStores();
        $this->deleteExistingSyncOrders();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    /**
     * @testWith [["foo"]]
     *           [["bar", "-1234"]]
     *           [["3.14"]]
     *
     * @param string[] $storeIds
     *
     * @return void
     */
    public function testExecute_AllStoreIdsInvalid(
        array $storeIds,
    ): void {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );

        $mockProcessEventsServiceQueued = $this->getMockProcessEventsService();
        $mockProcessEventsServiceQueued->expects($this->never())
            ->method('execute');
        $mockProcessEventsServiceRetry = $this->getMockProcessEventsService();
        $mockProcessEventsServiceRetry->expects($this->never())
            ->method('execute');

        $syncOrdersCommand = $this->instantiateTestObject([
            'syncOrdersServiceForRetry' => $mockProcessEventsServiceRetry,
            'syncOrdersServiceForQueued' => $mockProcessEventsServiceQueued,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => $storeIds,
            ],
            options: [],
        );
        $this->assertGreaterThan(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'Processing RETRY orders',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Processing QUEUED orders',
            haystack: $tester->getDisplay(),
        );
    }

    public function testExecute_AllStoreIdsDisabledForSync(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );

        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );

        $mockProcessEventsServiceQueued = $this->getMockProcessEventsService();
        $mockProcessEventsServiceQueued->expects($this->never())
            ->method('execute');
        $mockProcessEventsServiceRetry = $this->getMockProcessEventsService();
        $mockProcessEventsServiceRetry->expects($this->never())
            ->method('execute');

        $syncOrdersCommand = $this->instantiateTestObject([
            'syncOrdersServiceForRetry' => $mockProcessEventsServiceRetry,
            'syncOrdersServiceForQueued' => $mockProcessEventsServiceQueued,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (string)$store1->getId(),
                ],
            ],
            options: [],
        );
        $this->assertGreaterThan(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'Processing RETRY orders',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Processing QUEUED orders',
            haystack: $tester->getDisplay(),
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_AllStoreIdsDisabledForSync_IgnoreSyncEnabledFlag(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );

        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (string)$store1->getId(),
                ],
                '--ignore-sync-enabled-flag' => true,
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 1 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 1,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithStoreIds(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );

        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (string)$store1->getId(),
                ],
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 1 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 1,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
    }

    /**
     * @testWith [["foo"]]
     *           [["bar", "-1234"]]
     *           [["3.14"]]
     *
     * @param string[] $orderIds
     *
     * @return void
     */
    public function testExecute_AllOrderIdsInvalid(
        array $orderIds,
    ): void {
         $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );

        $mockProcessEventsServiceQueued = $this->getMockProcessEventsService();
        $mockProcessEventsServiceQueued->expects($this->never())
            ->method('execute');
        $mockProcessEventsServiceRetry = $this->getMockProcessEventsService();
        $mockProcessEventsServiceRetry->expects($this->never())
            ->method('execute');

        $syncOrdersCommand = $this->instantiateTestObject([
            'syncOrdersServiceForRetry' => $mockProcessEventsServiceRetry,
            'syncOrdersServiceForQueued' => $mockProcessEventsServiceQueued,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--order-id' => $orderIds,
            ],
            options: [],
        );
        $this->assertGreaterThan(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'Processing RETRY orders',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Processing QUEUED orders',
            haystack: $tester->getDisplay(),
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithOrderIds_WithPagination(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $order101 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject([
            'batchSize' => 1,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--order-id' => [
                    (string)$order101->getId(),
                    (string)$order201->getId(),
                    (string)$order202->getId(),
                ],
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 3 : Success\s+Batch 2 / 3 : Success\s+Batch 3 / 3 : No Action#', // phpcs:ignore Generic.Files.LineLength.TooLong
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 3,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithOrderIds(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $order101 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--order-id' => [
                    (string)$order101->getId(),
                    (string)$order201->getId(),
                    (string)$order202->getId(),
                ],
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 3,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithStoreIds_WithOrderIds_Mismatch(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (string)$store1->getId(),
                ],
                '--order-id' => [
                    (string)$order201->getId(),
                    (string)$order202->getId(),
                ],
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 1 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 1 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithStoreIds_WithOrderIds(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $order101 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--store-id' => [
                    (string)$store1->getId(),
                    (string)$store2->getId(),
                ],
                '--order-id' => [
                    (string)$order101->getId(),
                    (string)$order202->getId(),
                ],
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 1,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 1,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 1,
        );
    }

    public function testExecute_InfiniteLoop(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );

        $mockProcessEventsServiceForRetry = $this->getMockProcessEventsService();
        $matcher = $this->exactly(3);
        $mockProcessEventsServiceForRetry->expects($matcher)
            ->method('execute')
            ->willReturnCallback(
                callback: fn (
                    ?SearchCriteriaInterface $searchCriteria, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
                    string $via, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
                ): ProcessEventsResultInterface => (
                    match ($matcher->getInvocationCount()) {
                        1 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '2',
                                    'incrementId' => '1000000002',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Success',
                                ],
                            ],
                        ),
                        2 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '3',
                                    'incrementId' => '1000000003',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                            ],
                        ),
                        3 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '3',
                                    'incrementId' => '1000000003',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                            ],
                        ),
                    }
                ),
            );

        $mockProcessEventsServiceForQueued = $this->getMockProcessEventsService();
        $matcher = $this->exactly(3);
        $mockProcessEventsServiceForQueued->expects($matcher)
            ->method('execute')
            ->willReturnCallback(
                callback: fn (
                    ?SearchCriteriaInterface $searchCriteria, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
                    string $via, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
                ): ProcessEventsResultInterface => (
                    match ($matcher->getInvocationCount()) {
                        1 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '2',
                                    'incrementId' => '1000000002',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Success',
                                ],
                            ],
                        ),
                        2 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '3',
                                    'incrementId' => '1000000003',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                            ],
                        ),
                        3 => $this->createProcessEventsResult(
                            status: ProcessEventsResultStatuses::PARTIAL,
                            messages: [],
                            pipelineResult: [
                                [
                                    'orderId' => '1',
                                    'incrementId' => '1000000001',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                                [
                                    'orderId' => '3',
                                    'incrementId' => '1000000003',
                                    'storeId' => (int)$store1->getId(),
                                    'result' => 'Error',
                                ],
                            ],
                        ),
                    }
                ),
            );

        $syncOrdersCommand = $this->instantiateTestObject([
            'syncOrdersServiceForRetry' => $mockProcessEventsServiceForRetry,
            'syncOrdersServiceForQueued' => $mockProcessEventsServiceForQueued,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_NoSyncEnabledStores(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: false,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );

        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [],
            options: [],
        );
        $this->assertGreaterThan(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'Processing RETRY orders',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Processing QUEUED orders',
            haystack: $tester->getDisplay(),
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_NoSyncEnabledStores_IgnoreSyncEnabledFlag(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: false,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [
                '--ignore-sync-enabled-flag' => true,
            ],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 1 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 2,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute_WithPagination(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 0,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject([
            'batchSize' => 1,
        ]);
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 3 : Success\s+Batch 2 / 3 : Success\s+Batch 3 / 3 : No Action#', // phpcs:ignore Generic.Files.LineLength.TooLong
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 3 : Success\s+Batch 2 / 3 : Success\s+Batch 3 / 3 : No Action#', // phpcs:ignore Generic.Files.LineLength.TooLong
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 4,
        );
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testExecute(): void
    {
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: false,
        );
        $order201 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order202 = $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order201,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order202,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $store3 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_3',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1122334455',
            syncEnabled: true,
        );
        $order301 = $this->createOrderInStore(
            storeCode: $store3->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order302 = $this->createOrderInStore(
            storeCode: $store3->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order301,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order302,
            syncStatus: Statuses::RETRY,
            attempts: 6,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [
                Statuses::RETRY,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
                (int)$store3->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
                (int)$store3->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 4,
        );

        $this->objectManager->get(SyncEnabledStoresProviderInterface::class)
            ->clearCache();
        $syncOrdersCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $syncOrdersCommand,
        );

        $statusCode = $tester->execute(
            input: [],
            options: [],
        );
        $this->assertSame(
            expected: 0,
            actual: $statusCode,
            message: 'Status Code',
        );

        $this->assertStringNotContainsString(
            needle: 'No stores enabled for sync',
            haystack: $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            needle: 'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            haystack: $tester->getDisplay(),
        );

        $this->assertStringNotContainsString(
            needle: 'No valid order ids provided for sync',
            haystack: $tester->getDisplay(),
        );

        $this->assertMatchesRegularExpression(
            pattern: '#Processing RETRY orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );
        $this->assertMatchesRegularExpression(
            pattern: '#Processing QUEUED orders\s+Batch 1 / 2 : Success\s+Batch 2 / 2 : No Action#',
            string: $tester->getDisplay(),
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store3->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::RETRY,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store3->getId(),
            ],
            statuses: [
                Statuses::SYNCED,
            ],
            expectedCount: 4,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::RETRY,
            ],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [
                Statuses::QUEUED,
                Statuses::SYNCED,
                Statuses::ERROR,
            ],
            expectedCount: 0,
        );
    }

    /**
     * @return MockObject&ProcessEventsServiceInterface
     */
    private function getMockProcessEventsService(): MockObject
    {
        return $this->getMockBuilder(ProcessEventsServiceInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return void
     */
    private function deleteExistingSyncOrders(): void
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $connection->delete(
            table: $resourceConnection->getTableName(SyncOrderResource::TABLE),
            where: '',
        );
    }

    /**
     * @return void
     */
    private function disableKlevuForExistingStores(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 0,
        );
        ConfigFixture::setGlobal(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: '',
        );
        ConfigFixture::setGlobal(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: '',
        );

        $this->syncEnabledStoresProvider->clearCache();
    }

    /**
     * @param int[] $storeIds
     * @param Statuses[] $statuses
     * @param int $expectedCount
     *
     * @return void
     */
    private function assertSyncOrderCountForStatus(
        array $storeIds,
        ?array $statuses,
        int $expectedCount,
    ): void {
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrder::FIELD_STORE_ID,
            value: $storeIds,
            conditionType: 'in',
        );
        if (null !== $statuses) {
            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrder::FIELD_STATUS,
                value: array_map(
                    callback: static fn (Statuses $status): string => $status->value,
                    array: $statuses,
                ),
                conditionType: 'in',
            );
        }
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrders = $this->syncOrderRepository->getList(
            searchCriteria: $searchCriteria,
        );

        $this->assertSame(
            expected: $expectedCount,
            actual: $syncOrders->getTotalCount(),
            message: sprintf(
                'Sync Order Count for Store IDs %s and Statuses %s',
                implode(', ', $storeIds),
                is_array($statuses)
                    ? implode(', ', array_map(
                        callback: static fn (Statuses $status): string => $status->value,
                        array: $statuses,
                    ))
                    : '<NULL>',
            ),
        );
    }

    /**
     * @param ProcessEventsResultStatuses $status
     * @param string[] $messages
     * @param mixed $pipelineResult
     *
     * @return ProcessEventsResultInterface
     */
    private function createProcessEventsResult(
        ProcessEventsResultStatuses $status,
        array $messages,
        mixed $pipelineResult,
    ): ProcessEventsResultInterface {
        $processEventsResult = $this->processEventsResultFactory->create();

        $processEventsResult->setStatus($status);
        $processEventsResult->setMessages($messages);
        $processEventsResult->setPipelineResult($pipelineResult);

        return $processEventsResult;
    }
}

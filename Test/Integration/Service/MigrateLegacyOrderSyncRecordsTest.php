<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Service\MigrateLegacyOrderSyncRecords;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\MarkOrderActionResultInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MigrateLegacyOrderSyncRecordsInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\UpdateSyncOrderHistoryForOrderIdActionInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @magentoAppIsolation enabled
 * @method MigrateLegacyOrderSyncRecords instantiateTestObject(?array $arguments = null)
 * @method MigrateLegacyOrderSyncRecords instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MigrateLegacyOrderSyncRecordsTest extends TestCase
{
    use CreateOrderInStoreTrait;
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

    public const FIXTURE_REST_AUTH_KEY = 'ABCDE1234567890';

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line Used by traits
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resourceConnection = null;
    /**
     * @var SchemaSetupInterface|null
     */
    private ?SchemaSetupInterface $schemaSetup = null;
    /**
     * @var ConfigWriterInterface|null
     */
    private ?ConfigWriterInterface $configWriter = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var SyncOrderHistoryRepositoryInterface|null
     */
    private ?SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MigrateLegacyOrderSyncRecords::class;
        $this->interfaceFqcn = MigrateLegacyOrderSyncRecordsInterface::class;

        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->schemaSetup = $this->objectManager->get(SchemaSetupInterface::class);
        $this->configWriter = $this->objectManager->get(ConfigWriterInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);

        $this->createLegacyOrderSyncTable();

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->orderFixtures = [];

        $this->configWriter->save(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: 0,
            scope: 'default',
            scopeId: 0,
        );
        foreach ($this->storeManager->getStores() as $store) {
            $this->configWriter->save(
                path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
                value: null,
                scope: 'stores',
                scopeId: $store->getId(),
            );
        }
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();

        $this->dropLegacyOrderSyncTable();
    }

    public function testExecuteForAllStores_NoIntegratedStores(): void
    {
        $mockLogger = $this->getMockLogger();

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->never())
            ->method('execute');
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->never())
            ->method('execute');
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($this->never())
            ->method('execute');

        $defaultWebsite = $this->storeManager->getWebsite();
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $defaultWebsite,
            klevuApiKey: null,
            syncEnabled: false,
        );
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $defaultWebsite,
            klevuApiKey: null,
            syncEnabled: false,
        );

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForAllStores();
    }

    public function testExecuteForAllStores_IntegratedStores_NoLegacyOrderSyncRecords(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->never())
            ->method('execute');
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->never())
            ->method('execute');
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($this->never())
            ->method('execute');

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
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeFixture3->getId(),
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeFixture3->getId(),
        );

        $matcher = $this->exactly(6);
        $mockLogger->expects($matcher)
            ->method(LogLevel::INFO)
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher) {
                        match ($matcher->getInvocationCount()) {
                            1, 3, 5 => $this->assertSame(
                                expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                actual: $message,
                            ),
                            2, 4, 6 => $this->assertSame(
                                expected: 'Legacy order sync migration for {ordersCount} orders in store ID: {storeId} '
                                . 'completed in {timeTaken} seconds',
                                actual: $message,
                            ),
                        };

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $storeFixture1, $storeFixture2, $storeFixture3) { // phpcs:ignore Generic.Files.LineLength.TooLong
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 2 => (int)$storeFixture1->getId(),
                                3, 4 => (int)$storeFixture3->getId(),
                                5, 6 => (int)$storeFixture2->getId(), // Because stores are grouped by api key
                            },
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );
                        if (!($matcher->getInvocationCount() % 2)) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 0,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForAllStores();
    }

    public function testExecuteForAllStores_Fail(): void
    {
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
        $store1Id = (int)$storeFixture1->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $store1Id,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $store1Id,
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
        $store2Id = (int)$storeFixture2->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-98765643210',
            scope: 'stores',
            scopeId: $store2Id,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE9876543210',
            scope: 'stores',
            scopeId: $store2Id,
        );

        $orderQueue = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderQueue->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 0,
            );
        }

        $orderProcessed = $this->createOrderInStore(
            storeCode: $storeFixture2->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_3',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderProcessed->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 1,
            );
        }

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::ERROR,
                LogLevel::INFO,
            ],
        );
        $matcherError = $this->exactly(2);
        $mockLogger->expects($matcherError)
            ->method('error')
            ->with(
                $this->callback(
                    callback: function (string $message): bool {
                        $this->assertSame(
                            expected: 'Failed to migrate legacy order sync record #{orderId} for store ID: {storeId}',
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcherError, $store1Id, $store2Id, $orderQueue, $orderProcessed): bool { // phpcs:ignore Generic.Files.LineLength.TooLong
                        $this->assertIsArray($context);

                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertArrayHasKey('orderId', $context);
                        $this->assertArrayHasKey('expected_status', $context);
                        $this->assertArrayHasKey('messages', $context);

                        switch ($matcherError->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: $store1Id,
                                    actual: $context['storeId'],
                                    message: 'Store ID',
                                );
                                $this->assertSame(
                                    expected: (int)$orderQueue->getEntityId(),
                                    actual: $context['orderId'],
                                    message: 'Order ID (queued)',
                                );
                                $this->assertSame(
                                    expected: 'queued',
                                    actual: $context['expected_status'],
                                    message: 'Expected Status (queued)',
                                );
                                $this->assertSame(
                                    expected: [
                                        'Test Error for Queued',
                                    ],
                                    actual: $context['messages'],
                                    message: 'Messages (queued)',
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: $store2Id,
                                    actual: $context['storeId'],
                                    message: 'Store ID',
                                );
                                $this->assertSame(
                                    expected: (int)$orderProcessed->getEntityId(),
                                    actual: $context['orderId'],
                                    message: 'Order ID (processed])',
                                );
                                $this->assertSame(
                                    expected: 'processed',
                                    actual: $context['expected_status'],
                                    message: 'Expected Status (processed)',
                                );
                                $this->assertSame(
                                    expected: [
                                        'Test Error for Processed',
                                    ],
                                    actual: $context['messages'],
                                    message: 'Messages (processed)',
                                );
                                break;
                        }

                        return true;
                    },
                ),
            );

        $matcherInfo = $this->exactly(4);
        $mockLogger->expects($matcherInfo)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcherInfo): bool {
                        switch ($matcherInfo->getInvocationCount()) {
                            case 1:
                            case 3:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                            case 4:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcherInfo, $store1Id, $store2Id): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: match ($matcherInfo->getInvocationCount()) {
                                1, 2 => $store1Id,
                                3, 4 => $store2Id,
                            },
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (!($matcherInfo->getInvocationCount() % 2)) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 1,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->once())
            ->method('execute')
            ->with(
                (int)$orderQueue->getEntityId(),
                'Database Migration',
                [],
            )
            ->willReturn(
                value: $this->getMockMarkOrderActionResult(
                    isSuccess: false,
                    messages: [
                        'Test Error for Queued',
                    ],
                ),
            );

        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->once())
            ->method('execute')
            ->with(
                (int)$orderProcessed->getEntityId(),
                Statuses::SYNCED->value,
                'Database Migration',
                [],
            )
            ->willReturn(
                value: $this->getMockMarkOrderActionResult(
                    isSuccess: false,
                    messages: [
                        'Test Error for Processed',
                    ],
                ),
            );
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $matcherUpdate = $this->exactly(2);
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($matcherUpdate)
            ->method('execute')
            ->with(
                $this->callback(
                    callback: function (int $orderId) use ($matcherUpdate, $orderQueue, $orderProcessed): bool {
                        $this->assertSame(
                            expected: match ($matcherUpdate->getInvocationCount()) {
                                1 => (int)$orderQueue->getEntityId(),
                                2 => (int)$orderProcessed->getEntityId(),
                                default => 0,
                            },
                            actual: $orderId,
                            message: sprintf('Order ID (#%d)', $matcherUpdate->getInvocationCount()),
                        );

                        return true;
                    },
                ),
                Actions::MIGRATE,
            );

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForAllStores();
    }

    public function testExecuteForAllStores_Success(): void
    {
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
        $store1Id = (int)$storeFixture1->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $store1Id,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $store1Id,
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
        $store2Id = (int)$storeFixture2->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-98765643210',
            scope: 'stores',
            scopeId: $store2Id,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE9876543210',
            scope: 'stores',
            scopeId: $store2Id,
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );
        $matcher = $this->exactly(4);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        switch ($matcher->getInvocationCount()) {
                            case 1:
                            case 3:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                            case 4:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $store1Id, $store2Id): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 2 => $store1Id,
                                3, 4 => $store2Id,
                                default => 0,
                            },
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (!($matcher->getInvocationCount() % 2)) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: match ($matcher->getInvocationCount()) {
                                    2 => 3,
                                    4 => 1,
                                    default => 0,
                                },
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $orderQueue = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderQueue->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 0,
            );
        }

        $orderProcessed = $this->createOrderInStore(
            storeCode: $storeFixture2->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_3',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderProcessed->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 1,
            );
        }

        $orderError = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'processing',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_4',
                ],
                [
                    'sku' => 'test_product_1',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderError->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 2,
            );
        }

        $orderPartial = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
                [
                    'sku' => 'test_product_3',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderPartial->getItems() as $index => $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: $index,
            );
        }

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrder::FIELD_STORE_ID,
            value: [
                $store1Id,
                $store2Id,
            ],
            conditionType: 'in',
        );
        $syncOrderSearchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderRecordsResultBefore = $this->syncOrderRepository->getList(
            searchCriteria: $syncOrderSearchCriteria,
        );
        $this->assertEmpty($syncOrderRecordsResultBefore->getItems());

        $migrateLegacyOrderSyncRecordsService->executeForAllStores();

        $syncOrderRecordsResultAfter = $this->syncOrderRepository->getList(
            searchCriteria: $syncOrderSearchCriteria,
        );
        $syncOrderRecords = $syncOrderRecordsResultAfter->getItems();

        $this->assertCount(
            expectedCount: 4,
            haystack: $syncOrderRecords,
        );

        /** @var SyncOrderInterface $syncOrderRecord */
        foreach ($syncOrderRecords as $syncOrderRecord) {
            switch ($syncOrderRecord->getOrderId()) {
                case $orderQueue->getId():
                    $this->assertSame(
                        expected: $store1Id,
                        actual: $syncOrderRecord->getStoreId(),
                    );
                    $this->assertSame(
                        expected: Statuses::QUEUED->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 0,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderProcessed->getId():
                    $this->assertSame(
                        expected: $store2Id,
                        actual: $syncOrderRecord->getStoreId(),
                    );
                    $this->assertSame(
                        expected: Statuses::SYNCED->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderError->getId():
                    $this->assertSame(
                        expected: $store1Id,
                        actual: $syncOrderRecord->getStoreId(),
                    );
                    $this->assertSame(
                        expected: Statuses::ERROR->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderPartial->getId():
                    $this->assertSame(
                        expected: $store1Id,
                        actual: $syncOrderRecord->getStoreId(),
                    );
                    $this->assertSame(
                        expected: Statuses::PARTIAL->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;
            }

            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
                value: $syncOrderRecord->getId(),
            );
            $searchCriteria = $this->searchCriteriaBuilder->create();

            $syncOrderHistoryRecordsResult = $this->syncOrderHistoryRepository->getList(
                searchCriteria: $searchCriteria,
            );
            $syncOrderHistoryRecords = $syncOrderHistoryRecordsResult->getItems();

            $this->assertCount(
                expectedCount: 1,
                haystack: $syncOrderHistoryRecords,
            );
            /** @var SyncOrderHistoryInterface $syncOrderHistoryRecord */
            foreach ($syncOrderHistoryRecords as $syncOrderHistoryRecord) {
                $this->assertSame(
                    expected: $syncOrderRecord->getEntityId(),
                    actual: $syncOrderHistoryRecord->getSyncOrderId(),
                );
                $this->assertSame(
                    expected: Actions::MIGRATE->value,
                    actual: $syncOrderHistoryRecord->getAction(),
                );
                $this->assertSame(
                    expected: 'Database Migration',
                    actual: $syncOrderHistoryRecord->getVia(),
                );
                $this->assertSame(
                    expected: Results::SUCCESS->value,
                    actual: $syncOrderHistoryRecord->getResult(),
                );
            }
        }
    }

    public function testExecuteForStoreId_NonExistentStore(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        switch ($matcher->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: -1,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (2 === $matcher->getInvocationCount()) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 0,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->never())
            ->method('execute');
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->never())
            ->method('execute');
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($this->never())
            ->method('execute');

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForStoreId(
            storeId: -1,
        );
    }

    public function testExecuteForStoreId_NotIntegrated(): void
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $storeId = (int)$defaultStore->getId();

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        switch ($matcher->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $storeId): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: $storeId,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (2 === $matcher->getInvocationCount()) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 0,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->never())
            ->method('execute');
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->never())
            ->method('execute');
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($this->never())
            ->method('execute');

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForStoreId(
            storeId: $storeId,
        );
    }

    public function testExecuteForStoreId_Integrated_NoLegacyOrderSyncRecords(): void
    {
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
        $storeId = (int)$storeFixture1->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        switch ($matcher->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $storeId): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: $storeId,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (2 === $matcher->getInvocationCount()) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 0,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->never())
            ->method('execute');
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->never())
            ->method('execute');
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($this->never())
            ->method('execute');

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForStoreId(
            storeId: $storeId,
        );
    }

    public function testExecuteForStoreId_Fail(): void
    {
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
        $storeId = (int)$storeFixture1->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );

        $orderQueue = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderQueue->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 0,
            );
        }

        $orderProcessed = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_3',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderProcessed->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 1,
            );
        }

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::ERROR,
                LogLevel::INFO,
            ],
        );
        $matcherError = $this->exactly(2);
        $mockLogger->expects($matcherError)
            ->method('error')
            ->with(
                $this->callback(
                    callback: function (string $message): bool {
                        $this->assertSame(
                            expected: 'Failed to migrate legacy order sync record #{orderId} for store ID: {storeId}',
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcherError, $storeId, $orderQueue, $orderProcessed): bool { // phpcs:ignore Generic.Files.LineLength.TooLong
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: $storeId,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        $this->assertArrayHasKey('orderId', $context);
                        $this->assertArrayHasKey('expected_status', $context);
                        $this->assertArrayHasKey('messages', $context);

                        switch ($matcherError->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: (int)$orderQueue->getEntityId(),
                                    actual: $context['orderId'],
                                    message: 'Order ID (queued)',
                                );
                                $this->assertSame(
                                    expected: 'queued',
                                    actual: $context['expected_status'],
                                    message: 'Expected Status (queued)',
                                );
                                $this->assertSame(
                                    expected: [
                                        'Test Error for Queued',
                                    ],
                                    actual: $context['messages'],
                                    message: 'Messages (queued)',
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: (int)$orderProcessed->getEntityId(),
                                    actual: $context['orderId'],
                                    message: 'Order ID (processed])',
                                );
                                $this->assertSame(
                                    expected: 'processed',
                                    actual: $context['expected_status'],
                                    message: 'Expected Status (processed)',
                                );
                                $this->assertSame(
                                    expected: [
                                        'Test Error for Processed',
                                    ],
                                    actual: $context['messages'],
                                    message: 'Messages (processed)',
                                );
                                break;
                        }

                        return true;
                    },
                ),
            );
        $matcherInfo = $this->exactly(2);
        $mockLogger->expects($matcherInfo)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcherInfo): bool {
                        switch ($matcherInfo->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcherInfo, $storeId): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: $storeId,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (!($matcherInfo->getInvocationCount() % 2)) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 2,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $mockQueueOrderForSyncAction = $this->getMockQueueOrderForSyncAction();
        $mockQueueOrderForSyncAction->expects($this->once())
            ->method('execute')
            ->with(
                (int)$orderQueue->getEntityId(),
                'Database Migration',
                [],
            )
            ->willReturn(
                value: $this->getMockMarkOrderActionResult(
                    isSuccess: false,
                    messages: [
                        'Test Error for Queued',
                    ],
                ),
            );
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessedAction();
        $mockMarkOrderAsProcessedAction->expects($this->once())
            ->method('execute')
            ->with(
                (int)$orderProcessed->getEntityId(),
                Statuses::SYNCED->value,
                'Database Migration',
                [],
            )
            ->willReturn(
                value: $this->getMockMarkOrderActionResult(
                    isSuccess: false,
                    messages: [
                        'Test Error for Processed',
                    ],
                ),
            );
        $mockUpdateSyncOrderHistoryForOrderIdAction = $this->getMockUpdateSyncOrderHistoryForOrderIdAction();
        $matcherUpdate = $this->exactly(2);
        $mockUpdateSyncOrderHistoryForOrderIdAction->expects($matcherUpdate)
            ->method('execute')
            ->with(
                $this->callback(
                    callback: function (int $orderId) use ($matcherUpdate, $orderQueue, $orderProcessed): bool {
                        $this->assertSame(
                            expected: match ($matcherUpdate->getInvocationCount()) {
                                1 => (int)$orderQueue->getEntityId(),
                                2 => (int)$orderProcessed->getEntityId(),
                                default => 0,
                            },
                            actual: $orderId,
                            message: sprintf('Order ID (#%d)', $matcherUpdate->getInvocationCount()),
                        );

                        return true;
                    },
                ),
                Actions::MIGRATE,
            );

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
                'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
                'updateSyncOrderHistoryForOrderIdAction' => $mockUpdateSyncOrderHistoryForOrderIdAction,
            ],
        );

        $migrateLegacyOrderSyncRecordsService->executeForStoreId(
            storeId: $storeId,
        );
    }

    public function testExecuteForStoreId_Success(): void
    {
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
        $storeId = (int)$storeFixture1->getId();

        $this->configWriter->save(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );
        $this->configWriter->save(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
            scope: 'stores',
            scopeId: $storeId,
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        switch ($matcher->getInvocationCount()) {
                            case 1:
                                $this->assertSame(
                                    expected: 'Starting legacy order sync migration for store ID: {storeId}',
                                    actual: $message,
                                );
                                break;

                            case 2:
                                $this->assertSame(
                                    expected: 'Legacy order sync migration for {ordersCount} orders in store ID: '
                                        . '{storeId} completed in {timeTaken} seconds',
                                    actual: $message,
                                );
                                break;
                        }

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $storeId): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeId', $context);
                        $this->assertSame(
                            expected: $storeId,
                            actual: $context['storeId'],
                            message: 'Store ID',
                        );

                        if (!($matcher->getInvocationCount() % 2)) {
                            $this->assertArrayHasKey('ordersCount', $context);
                            $this->assertSame(
                                expected: 4,
                                actual: $context['ordersCount'],
                                message: 'Orders count',
                            );
                            $this->assertArrayHasKey('timeTaken', $context);
                            $this->assertIsNumeric($context['timeTaken']);
                        }

                        return true;
                    },
                ),
            );

        $orderQueue = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderQueue->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 0,
            );
        }

        $orderProcessed = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_3',
                ],
                [
                    'sku' => 'test_product_2',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderProcessed->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 1,
            );
        }

        $orderError = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_4',
                ],
                [
                    'sku' => 'test_product_1',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderError->getItems() as $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: 2,
            );
        }

        $orderPartial = $this->createOrderInStore(
            storeCode: $storeFixture1->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_1',
                ],
                [
                    'sku' => 'test_product_2',
                ],
                [
                    'sku' => 'test_product_3',
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );
        foreach ($orderPartial->getItems() as $index => $orderItem) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: 'ABCDE1234567890',
                ipAddress: '127.0.0.1',
                date: date('Y-m-d H:i:s'),
                idcode: 'ABCDE1234567890',
                checkoutdate: date('Y-m-d H:i:s'),
                send: $index,
            );
        }

        $migrateLegacyOrderSyncRecordsService = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrder::FIELD_STORE_ID,
            value: $storeId,
        );
        $syncOrderSearchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderRecordsResultBefore = $this->syncOrderRepository->getList(
            searchCriteria: $syncOrderSearchCriteria,
        );
        $this->assertEmpty($syncOrderRecordsResultBefore->getItems());

        $migrateLegacyOrderSyncRecordsService->executeForStoreId(
            storeId: $storeId,
        );

        $syncOrderRecordsResultAfter = $this->syncOrderRepository->getList(
            searchCriteria: $syncOrderSearchCriteria,
        );
        $syncOrderRecords = $syncOrderRecordsResultAfter->getItems();

        $this->assertCount(
            expectedCount: 4,
            haystack: $syncOrderRecords,
        );
        /** @var SyncOrderInterface $syncOrderRecord */
        foreach ($syncOrderRecords as $syncOrderRecord) {
            $this->assertSame(
                expected: $storeId,
                actual: $syncOrderRecord->getStoreId(),
            );

            switch ($syncOrderRecord->getOrderId()) {
                case $orderQueue->getId():
                    $this->assertSame(
                        expected: Statuses::QUEUED->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 0,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderProcessed->getId():
                    $this->assertSame(
                        expected: Statuses::SYNCED->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderError->getId():
                    $this->assertSame(
                        expected: Statuses::ERROR->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;

                case $orderPartial->getId():
                    $this->assertSame(
                        expected: Statuses::PARTIAL->value,
                        actual: $syncOrderRecord->getStatus(),
                    );
                    $this->assertSame(
                        expected: 1,
                        actual: $syncOrderRecord->getAttempts(),
                    );
                    break;
            }

            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
                value: $syncOrderRecord->getId(),
            );
            $searchCriteria = $this->searchCriteriaBuilder->create();

            $syncOrderHistoryRecordsResult = $this->syncOrderHistoryRepository->getList(
                searchCriteria: $searchCriteria,
            );
            $syncOrderHistoryRecords = $syncOrderHistoryRecordsResult->getItems();

            $this->assertCount(
                expectedCount: 1,
                haystack: $syncOrderHistoryRecords,
            );
            /** @var SyncOrderHistoryInterface $syncOrderHistoryRecord */
            foreach ($syncOrderHistoryRecords as $syncOrderHistoryRecord) {
                $this->assertSame(
                    expected: $syncOrderRecord->getEntityId(),
                    actual: $syncOrderHistoryRecord->getSyncOrderId(),
                );
                $this->assertSame(
                    expected: Actions::MIGRATE->value,
                    actual: $syncOrderHistoryRecord->getAction(),
                );
                $this->assertSame(
                    expected: 'Database Migration',
                    actual: $syncOrderHistoryRecord->getVia(),
                );
                $this->assertSame(
                    expected: Results::SUCCESS->value,
                    actual: $syncOrderHistoryRecord->getResult(),
                );
            }
        }
    }

    /**
     * @return void
     */
    private function createLegacyOrderSyncTable(): void
    {
        $this->dropLegacyOrderSyncTable();
        $this->schemaSetup->run(sprintf(
            <<<'SQL'
            CREATE TABLE `%s` (
                `order_item_id` int(10) unsigned NOT NULL,
                `klevu_session_id` VARCHAR(255) NOT NULL,
                `ip_address` VARCHAR(255) NOT NULL,
                `date` DATETIME NOT NULL,
                `idcode` VARCHAR(255) NOT NULL,
                `checkoutdate` VARCHAR(255) NOT NULL,
                `send` BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (`order_item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            SQL,
            $this->schemaSetup->getTable('klevu_order_sync'),
        ));
    }

    /**
     * @return void
     */
    private function dropLegacyOrderSyncTable(): void
    {
        $this->schemaSetup->run(sprintf(
            <<<'SQL'
            DROP TABLE IF EXISTS `%s`;
            SQL,
            $this->schemaSetup->getTable('klevu_order_sync'),
        ));
    }

    /**
     * @param int $orderItemId
     * @param string $klevuSessionId
     * @param string $ipAddress
     * @param string $date
     * @param string $idcode
     * @param string $checkoutdate
     * @param int $send
     * @return void
     */
    private function addLegacyOrderSyncLine(
        int $orderItemId,
        string $klevuSessionId,
        string $ipAddress,
        string $date,
        string $idcode,
        string $checkoutdate,
        int $send,
    ): void {
        $connection = $this->resourceConnection->getConnection();

        $connection->insert(
            table: $this->schemaSetup->getTable('klevu_order_sync'),
            bind: [
                'order_item_id' => $orderItemId,
                'klevu_session_id' => $klevuSessionId,
                'ip_address' => $ipAddress,
                'date' => $date,
                'idcode' => $idcode,
                'checkoutdate' => $checkoutdate,
                'send' => $send,
            ],
        );
    }

    /**
     * @param string[] $expectedLogLevels
     *
     * @return MockObject&LoggerInterface
     */
    private function getMockLogger(
        array $expectedLogLevels = [],
    ): MockObject {
        $mockLogger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $logLevels = array_diff(
            [
                LogLevel::EMERGENCY,
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::ERROR,
                LogLevel::WARNING,
                LogLevel::NOTICE,
                LogLevel::INFO,
                LogLevel::DEBUG,
            ],
            $expectedLogLevels,
        );
        foreach ($logLevels as $logLevel) {
            $mockLogger->expects($this->never())
                ->method($logLevel);
        }

        return $mockLogger;
    }

    /**
     * @return MockObject&QueueOrderForSyncActionInterface
     */
    private function getMockQueueOrderForSyncAction(): MockObject
    {
        return $this->getMockBuilder(
                className: QueueOrderForSyncActionInterface::class,
            )
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function getMockMarkOrderAsProcessedAction(): MockObject
    {
        return $this->getMockBuilder(
                className: MarkOrderAsProcessedActionInterface::class,
            )
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param bool $isSuccess
     * @param string[] $messages
     *
     * @return MockObject
     */
    private function getMockMarkOrderActionResult(
        bool $isSuccess,
        array $messages,
    ): MockObject {
        $mockMarkOrderActionResult = $this->getMockBuilder(
                className: MarkOrderActionResultInterface::class,
            )
            ->disableOriginalConstructor()
            ->getMock();

        $mockMarkOrderActionResult->method('isSuccess')
            ->willReturn($isSuccess);
        $mockMarkOrderActionResult->method('getMessages')
            ->willReturn($messages);

        return $mockMarkOrderActionResult;
    }

    /**
     * @return MockObject&UpdateSyncOrderHistoryForOrderIdActionInterface
     */
    private function getMockUpdateSyncOrderHistoryForOrderIdAction(): MockObject
    {
        return $this->getMockBuilder(
                className: UpdateSyncOrderHistoryForOrderIdActionInterface::class,
            )
            ->disableOriginalConstructor()
            ->getMock();
    }
}

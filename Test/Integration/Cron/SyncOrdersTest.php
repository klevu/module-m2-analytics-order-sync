<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Cron;

use Klevu\AnalyticsApi\Api\Data\ProcessEventsResultInterface;
use Klevu\AnalyticsApi\Api\ProcessEventsServiceInterface;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Cron\SyncOrders;
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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method SyncOrders instantiateTestObject(?array $arguments = null)
 */
class SyncOrdersTest extends TestCase
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
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrders::class;

        $this->syncEnabledStoresProvider = $this->objectManager->get(SyncEnabledStoresProviderInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);

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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::NOT_REGISTERED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
                (int)$store2->getId(),
            ],
            statuses: null,
            expectedCount: 0,
        );

        $mockProcessEventsServiceQueued = $this->getMockProcessEventsService();
        $mockProcessEventsServiceQueued->expects($this->never())
            ->method('execute');
        $mockProcessEventsServiceRetry = $this->getMockProcessEventsService();
        $mockProcessEventsServiceRetry->expects($this->never())
            ->method('execute');

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
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1 => 'Starting order sync via cron',
                                2 => 'Order sync cron complete: no configured stores found',
                                default => '',
                            },
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher): bool {
                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeIds', $context);
                        $this->assertSame(
                            expected: [],
                            actual: $context['storeIds'],
                        );

                        switch ($matcher->getInvocationCount()) {
                            case 2:
                                $this->assertArrayHasKey('timeTaken', $context);
                                $this->assertIsNumeric($context['timeTaken']);
                                break;
                        }

                        return true;
                    },
                ),
            );

        $syncOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'processEventsService' => $mockProcessEventsServiceQueued,
            'processEventsServiceForRetry' => $mockProcessEventsServiceRetry,
        ]);
        $syncOrdersCron->execute();
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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order103 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order103,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $order104 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order104,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [Statuses::QUEUED],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [Statuses::RETRY],
            expectedCount: 2,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: false,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: null,
            expectedCount: 0,
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
                LogLevel::DEBUG,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1 => 'Starting order sync via cron',
                                2 => 'Order sync cron complete in {timeTaken} seconds',
                                default => '',
                            },
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $store1): bool {
                        $store1Id = (int)$store1->getId();

                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeIds', $context);
                        $this->assertSame(
                            expected: [
                                $store1Id => $store1Id,
                            ],
                            actual: $context['storeIds'],
                        );

                        switch ($matcher->getInvocationCount()) {
                            case 2:
                                $this->assertArrayHasKey('timeTaken', $context);
                                $this->assertIsNumeric($context['timeTaken']);
                                break;
                        }

                        return true;
                    },
                ),
            );
        $matcher = $this->exactly(12);
        $mockLogger->expects($matcher)
            ->method('debug')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 7 => 'Starting order sync for {status} via cron',
                                2, 4, 8, 10 => 'Syncing orders ({status}) for page {currentPage}',
                                3, 5, 9, 11 => 'Order sync ({status}) complete for page {currentPage}',
                                6, 12 => 'Order sync for {status} via cron complete in {timeTaken} seconds. '
                                    . '{totalPages} pages processed',
                                default => '',
                            },
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $store1): bool {
                        $store1Id = (int)$store1->getId();

                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('status', $context);
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 2, 3, 4, 5, 6 => Statuses::RETRY->value,
                                7, 8, 9, 10, 11, 12 => Statuses::QUEUED->value,
                            },
                            actual: $context['status'],
                        );
                        $this->assertArrayHasKey('storeIds', $context);
                        $this->assertSame(
                            expected: [
                                $store1Id => $store1Id,
                            ],
                            actual: $context['storeIds'],
                        );

                        switch ($matcher->getInvocationCount()) {
                            case 2:
                            case 3:
                            case 8:
                            case 9:
                                $this->assertArrayHasKey('currentPage', $context);
                                $this->assertSame(
                                    expected: 1,
                                    actual: $context['currentPage'],
                                );
                                break;

                            case 5:
                            case 11:
                                $this->assertArrayHasKey('result', $context);
                                $this->assertInstanceOf(
                                    expected: ProcessEventsResultInterface::class,
                                    actual: $context['result'],
                                );
                                // cascade
                            case 4:
                            case 10:
                                $this->assertArrayHasKey('currentPage', $context);
                                $this->assertSame(
                                    expected: 2,
                                    actual: $context['currentPage'],
                                );
                                break;

                            case 6:
                            case 12:
                                $this->assertArrayHasKey('timeTaken', $context);
                                $this->assertIsNumeric($context['timeTaken']);

                                $this->assertArrayHasKey('totalPages', $context);
                                $this->assertSame(
                                    expected: 2,
                                    actual: $context['totalPages'],
                                );
                                break;
                        }

                        return true;
                    },
                ),
            );

        $syncOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'pageSize' => 1,
        ]);
        $syncOrdersCron->execute();

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
            statuses: [Statuses::SYNCED],
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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
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
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order103 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order103,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $order104 = $this->createOrderInStore(
            storeCode: $store1->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order104,
            syncStatus: Statuses::RETRY,
            attempts: 2,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [Statuses::QUEUED],
            expectedCount: 2,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store1->getId(),
            ],
            statuses: [Statuses::RETRY],
            expectedCount: 2,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-98765443210',
            syncEnabled: true,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'processing',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrderInStore(
            storeCode: $store2->getCode(),
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'SKU-' . rand(1, 1000) . microtime(true),
                    'price' => 10.0,
                    'qty_ordered' => 1,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [Statuses::QUEUED],
            expectedCount: 3,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [Statuses::RETRY],
            expectedCount: 0,
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::INFO,
                LogLevel::DEBUG,
            ],
        );
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1 => 'Starting order sync via cron',
                                2 => 'Order sync cron complete in {timeTaken} seconds',
                                default => '',
                            },
                            actual: $message,
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $store1, $store2): bool {
                        $store1Id = (int)$store1->getId();
                        $store2Id = (int)$store2->getId();

                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('storeIds', $context);
                        $this->assertSame(
                            expected: [
                                $store1Id => $store1Id,
                                $store2Id => $store2Id,
                            ],
                            actual: $context['storeIds'],
                        );

                        switch ($matcher->getInvocationCount()) {
                            case 2:
                                $this->assertArrayHasKey('timeTaken', $context);
                                $this->assertIsNumeric($context['timeTaken']);
                                break;
                        }

                        return true;
                    },
                ),
            );
        $matcher = $this->exactly(12);
        $mockLogger->expects($matcher)
            ->method('debug')
            ->with(
                $this->callback(
                    callback: function (string $message) use ($matcher): bool {
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 7 => 'Starting order sync for {status} via cron',
                                2, 4, // We log that we're syncing before we find we have no results on last page
                                8, 10 => 'Syncing orders ({status}) for page {currentPage}',
                                3, 5,
                                9, 11 => 'Order sync ({status}) complete for page {currentPage}',
                                6, 12 => 'Order sync for {status} via cron complete in {timeTaken} seconds. '
                                    . '{totalPages} pages processed',
                                default => '',
                            },
                            actual: $message,
                            message: sprintf('Invocation %d', $matcher->getInvocationCount()),
                        );

                        return true;
                    },
                ),
                $this->callback(
                    callback: function (?array $context) use ($matcher, $store1, $store2): bool {
                        $store1Id = (int)$store1->getId();
                        $store2Id = (int)$store2->getId();

                        $this->assertIsArray($context);
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('status', $context);
                        $this->assertSame(
                            expected: match ($matcher->getInvocationCount()) {
                                1, 2, 3, 4, 5, 6 => Statuses::RETRY->value,
                                7, 8, 9, 10, 11, 12 => Statuses::QUEUED->value,
                            },
                            actual: $context['status'],
                        );
                        $this->assertArrayHasKey('storeIds', $context);
                        $this->assertSame(
                            expected: [
                                $store1Id => $store1Id,
                                $store2Id => $store2Id,
                            ],
                            actual: $context['storeIds'],
                        );

                        switch ($matcher->getInvocationCount()) {
                            case 2:
                            case 3:
                            case 8:
                            case 9:
                                $this->assertArrayHasKey('currentPage', $context);
                                $this->assertSame(
                                    expected: 1,
                                    actual: $context['currentPage'],
                                );
                                break;
                            case 5:
                            case 11:
                                $this->assertArrayHasKey('result', $context);
                                $this->assertInstanceOf(
                                    expected: ProcessEventsResultInterface::class,
                                    actual: $context['result'],
                                );
                                // cascade
                            case 4:
                            case 10:
                                $this->assertArrayHasKey('currentPage', $context);
                                $this->assertSame(
                                    expected: 2,
                                    actual: $context['currentPage'],
                                );
                                break;

                            case 6:
                            case 12:
                                $this->assertArrayHasKey('timeTaken', $context);
                                $this->assertIsNumeric($context['timeTaken']);

                                $this->assertArrayHasKey('totalPages', $context);
                                $this->assertSame(
                                    expected: 2,
                                    actual: $context['totalPages'],
                                );
                                break;
                        }

                        return true;
                    },
                ),
            );

        $this->syncEnabledStoresProvider->clearCache();
        $syncOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $syncOrdersCron->execute();

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
            ],
            statuses: [Statuses::SYNCED],
            expectedCount: 4,
        );
        $this->assertSyncOrderCountForStatus(
            storeIds: [
                (int)$store2->getId(),
            ],
            statuses: [Statuses::SYNCED],
            expectedCount: 3,
        );
    }

    /**
     * @param string[] $expectedLogLevels
     *
     * @return MockObject&LoggerInterface
     */
    private function getMockLogger(array $expectedLogLevels = []): MockObject
    {
        $mockLogger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $notExpectedLogLevels = array_diff(
            [
                'emergency',
                'alert',
                'critical',
                'error',
                'warning',
                'notice',
                'info',
                'debug',
            ],
            $expectedLogLevels,
        );
        foreach ($notExpectedLogLevels as $notExpectedLogLevel) {
            $mockLogger->expects($this->never())
                ->method($notExpectedLogLevel);
        }

        return $mockLogger;
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
        );
    }
}

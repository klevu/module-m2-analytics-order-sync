<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Whitespaces.DuplicateSpaces.DuplicateSpaces

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service;

// phpcs:ignore SlevomatCodingStandard.Namespaces.UseOnlyWhitelistedNamespaces.NonFullyQualified
use GuzzleHttp\Client as GuzzleHttpClient;
use Klevu\Analytics\Service\ProcessEvents;
use Klevu\Analytics\Test\Integration\Service\ProcessEventsTest;
use Klevu\AnalyticsApi\Api\Data\ProcessEventsResultInterface;
use Klevu\AnalyticsApi\Api\ProcessEventsServiceInterface;
use Klevu\AnalyticsApi\Model\Source\ProcessEventsResultStatuses;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessingActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Klevu\Configuration\Service\Provider\ScopeProviderInterface;
use Klevu\PhpSDK\Service\Analytics\CollectService;
use Klevu\Pipelines\ObjectManager\Container;
use Klevu\Pipelines\ObjectManager\ObjectManagerInterface as PipelinesObjectManagerInterface;
use Klevu\PlatformPipelines\ObjectManager\Container as PlatformPipelinesContainer;
use Klevu\TestFixtures\Catalog\ConfigurableProductBuilder;
use Klevu\TestFixtures\Catalog\GroupedProductBuilder;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TddWizard\Fixtures\Catalog\ProductBuilder;
use TddWizard\Fixtures\Checkout\CartBuilder;
use TddWizard\Fixtures\Core\ConfigFixture;
use TddWizard\Fixtures\Sales\InvoiceBuilder;
use TddWizard\Fixtures\Sales\OrderBuilder;
use TddWizard\Fixtures\Sales\ShipmentBuilder;

/**
 * @method ProcessEventsServiceInterface instantiateTestObject(?array $arguments = null)
 * @see ProcessEventsTest
 * @group smoke
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 */
class ProcessOrderEventsTest extends TestCase
{
    use ObjectInstantiationTrait {
        getExpectedFqcns as trait_getExpectedFqcns;
    }
    use TestImplementsInterfaceTrait;
    use OrderTrait;
    use StoreTrait;
    use WebsiteTrait;

    private const PROVIDER_VIRTUAL_TYPE = 'Klevu\AnalyticsOrderSync\Service\ProcessOrderEvents';
    private const FIXTURE_REST_AUTH_KEY = 'ABCDE12345';

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SerializerInterface|null
     */
    private ?SerializerInterface $serializer = null;
    /**
     * @var SyncEnabledStoresProviderInterface|null
     */
    private ?SyncEnabledStoresProviderInterface $syncEnabledStoresProvider = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var SyncOrderHistoryRepositoryInterface|null
     */
    private ?SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository = null;
    /**
     * @var SortOrderBuilder|null
     */
    private ?SortOrderBuilder $sortOrderBuilder = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var (ClientInterface&MockObject)|null
     */
    private ?ClientInterface $mockHttpClient = null;
    /**
     * @var array|string[]
     */
    private ?array $systemConfig = [];
    /**
     * @var string[]|null
     */
    private ?array $storeFixtures = []; // @phpstan-ignore-line Used by traits
    /**
     * @var OrderInterface[]|null
     */
    private ?array $orderFixtures = []; // @phpstan-ignore-line Used by traits

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        // VirtualType
        $this->implementationFqcn = self::PROVIDER_VIRTUAL_TYPE;
        $this->interfaceFqcn = ProcessEventsServiceInterface::class;

        /** @var ScopeProviderInterface $scopeProvider */
        $scopeProvider = $this->objectManager->get(ScopeProviderInterface::class);
        $scopeProvider->unsetCurrentScope();

        $this->serializer = $this->objectManager->get(JsonSerializer::class);
        $this->syncEnabledStoresProvider = $this->objectManager->get(SyncEnabledStoresProviderInterface::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);
        $this->sortOrderBuilder = $this->objectManager->get(SortOrderBuilder::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);

        $this->mockHttpClient = $this->getMockBuilder(ClientInterface::class)->getMock();

        Container::setInstance(
            container: $this->objectManager->get(PlatformPipelinesContainer::class),
        );
        $pipelinesContainer = Container::getInstance();
        if ($pipelinesContainer instanceof PipelinesObjectManagerInterface) {
            $pipelinesContainer->addSharedInstance(
                identifier: CollectService::class,
                instance: new CollectService(
                    httpClient: $this->mockHttpClient,
                ),
            );
        }
        if ($this->objectManager instanceof ObjectManager) {
            $this->objectManager->addSharedInstance(
                instance: $this->mockHttpClient,
                className: GuzzleHttpClient::class,
                forPreference: true,
            );
        }

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
        $this->orderFixtures = [];

        $this->disableKlevuForExistingStores();
        $this->deleteExistingSyncOrders();

        $this->systemConfig = [
            Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE => 'remote_ip',
        ];
        $this->setSystemConfig();
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
        $this->websiteFixturesPool->rollback();
    }

    // -- Test Cases

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   𐄂  |  𐄂  |  𐄂
     */
    public function testExecute_NotIntegrated_NotSyncEnabled_NotOrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: null,
            syncEnabled: false,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   𐄂  |  𐄂  |  ✓
     */
    public function testExecute_NotIntegrated_NotSyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: null,
            syncEnabled: false,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   𐄂  |  ✓  |  𐄂
     */
    public function testExecute_NotIntegrated_SyncEnabled_NotOrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: null,
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   𐄂  |  ✓  |  ✓
     * @group wip
     */
    public function testExecute_NotIntegrated_SyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: null,
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1,1.2.3.4',
                'x_forwarded_for' => '172.0.0.1,1.2.3.4',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   𐄂✓ |  ✓  |  ✓
     * @group wip
     */
    public function testExecute_MixedIntegrated_SyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: null,
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $notIntegratedOrder = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1, 1.2.3.4',
                'x_forwarded_for' => '172.0.0.1, 1.2.3.4',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_2',
            excludedOrderStatuses: [],
        );
        $integratedOrder = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_2',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1,1.2.3.4',
                'x_forwarded_for' => '172.0.0.1,4.5.2.3',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->once();
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $integratedOrder,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-9876543210',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $integratedOrder->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $integratedOrder->getEntityId(),
                    'incrementId' => $integratedOrder->getIncrementId(),
                    'storeId' => $integratedOrder->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $notIntegratedOrder,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $integratedOrder,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   ✓  |  𐄂  |  𐄂
     */
    public function testExecute_Integrated_NotSyncEnabled_NotOrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   ✓  |  𐄂  |  ✓
     */
    public function testExecute_Integrated_NotSyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->once();
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether a store is integrated or sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   ✓  |  𐄂✓ |  ✓
     */
    public function testExecute_Integrated_MixedSyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $notSyncEnabledOrder = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_2',
            excludedOrderStatuses: [],
        );
        $syncEnabledOrder = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_2',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->exactly(2);
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $notSyncEnabledOrder,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                )
                                ),
                                $notSyncEnabledOrder->getItems(),
                            ),
                        ],
                        [
                            'order' => $syncEnabledOrder,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $syncEnabledOrder->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether a store is integrated or sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $notSyncEnabledOrder->getEntityId(),
                    'incrementId' => $notSyncEnabledOrder->getIncrementId(),
                    'storeId' => $notSyncEnabledOrder->getStoreId(),
                    'result' => 'Success',
                ],
                [
                    'orderId' => $syncEnabledOrder->getEntityId(),
                    'incrementId' => $syncEnabledOrder->getIncrementId(),
                    'storeId' => $syncEnabledOrder->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $notSyncEnabledOrder,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $syncEnabledOrder,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   ✓  |  ✓  |  𐄂
     */
    public function testExecute_Integrated_SyncEnabled_NotOrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
    }

    /**
     *  Int | En. | Ord
     * -----+-----+-----
     *   ✓  |  ✓  |  ✓
     */
    public function testExecute_Integrated_SyncEnabled_OrdersToProcess(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_2',
            excludedOrderStatuses: [],
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
            storeCode: 'klevu_analytics_test_store_2',
        );

        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_2',
            status: 'processing',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order3 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'closed',
            orderData: [
                'remote_ip' => '127.0.0.1',
                'x_forwarded_for' => '172.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::RETRY,
        );

        $invocationRule = $this->exactly(3);
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order1,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order1->getItems(),
                            ),
                        ],
                        [
                            'order' => $order2,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-9876543210',
                                    Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE => 'x_forwarded_for',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order2->getItems(),
                            ),
                        ],
                        [
                            'order' => $order3,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order3->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order1->getEntityId(),
                    'incrementId' => $order1->getIncrementId(),
                    'storeId' => $order1->getStoreId(),
                    'result' => 'Success',
                ],
                [
                    'orderId' => $order2->getEntityId(),
                    'incrementId' => $order2->getIncrementId(),
                    'storeId' => $order2->getStoreId(),
                    'result' => 'Success',
                ],
                [
                    'orderId' => $order3->getEntityId(),
                    'incrementId' => $order3->getIncrementId(),
                    'storeId' => $order3->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order3,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     * @testWith [400]
     *           [404]
     */
    public function testExecute_Api4xxErrorResponse(int $statusCode): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $this->getMockHttpResponse(statusCode: $statusCode),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether a store is integrated or sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Fail',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_HttpClientThrowsException(): array
    {
        return [
            [
                RequestExceptionInterface::class,
            ],
            [
                ClientExceptionInterface::class,
            ],
            [
                NetworkExceptionInterface::class,
            ],
        ];
    }

    /**
     * @testWith [500]
     *           [502]
     *           [503]
     */
    public function testExecute_Api5xxErrorResponse(int $statusCode): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $this->getMockHttpResponse(statusCode: $statusCode),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether a store is integrated or sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Fail',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    /**
     * @dataProvider dataProvider_testExecute_HttpClientThrowsException
     *
     * @param class-string $exceptionClass
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function testExecute_HttpClientThrowsException(string $exceptionClass): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        /** @var MockObject&\Throwable $requestException */
        $requestException = $this->getMockBuilder($exceptionClass)
            ->getMock();
        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willThrowException($requestException);

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        // The service doesn't care about whether a store is integrated or sync is enabled; that is the responsibility
        //  of the calling code to pass the correct search criteria to exclude disabled stores
        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Fail',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_FailureAfterMaximumAttempts(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $this->createOrUpdateSyncOrderRecord(
            order: $order,
            syncStatus: Statuses::RETRY,
            attempts: 5,
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS,
            value: 5,
            storeCode: 'klevu_analytics_test_store_1',
        );

        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $this->getMockHttpResponse(statusCode: 400),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Fail',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::ERROR,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_SelectedOrders_BySearchCriteria(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: $order1->getEntityId(),
            conditionType: 'nin',
        );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: $this->searchCriteriaBuilder->create(),
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order2->getEntityId(),
                    'incrementId' => $order2->getIncrementId(),
                    'storeId' => $order2->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_SelectedOrders_ByKlevuSyncStatus(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::PROCESSING,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::SYNCED,
        );

        $this->mockHttpClient->expects($this->never())
            ->method('sendRequest');

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::NOOP,
            expectedPipelineResult: [],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::PROCESSING,
            expectedSyncOrderHistory: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [],
        );
    }

    public function testExecute_SelectedOrders_ByExcludedOrderStatus(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [
                'pending',
                'complete',
            ],
        );
        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'processing',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order3 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'complete',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $this->mockHttpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order2->getEntityId(),
                    'incrementId' => $order2->getIncrementId(),
                    'storeId' => $order2->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order3,
            syncStatus: Statuses::QUEUED,
            expectedSyncOrderHistory: [],
        );
    }

    public function testExecute_MultipleOrders_AllOrdersFail(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_1_1_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_1_2_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
                ],
            orderItemsData: [
                [
                    'sku' => 'test_product_2_1_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_2_2_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->exactly(2);
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order1,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order1->getItems(),
                            ),
                        ],
                        [
                            'order' => $order2,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order2->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 400),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order1->getEntityId(),
                    'incrementId' => $order1->getIncrementId(),
                    'storeId' => $order1->getStoreId(),
                    'result' => 'Fail',
                ],
                [
                    'orderId' => $order2->getEntityId(),
                    'incrementId' => $order2->getIncrementId(),
                    'storeId' => $order2->getStoreId(),
                    'result' => 'Fail',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_MultipleOrders_SomeOrdersFail(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );
        $order1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_1_1_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_1_2_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $order2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_2_1_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_2_2_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->exactly(2);
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order1,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order1->getItems(),
                            ),
                        ],
                        [
                            'order' => $order2,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => array_map(
                                fn (OrderItemInterface $orderItem): array => (
                                    $this->getExpectedEventDataForOrderItem(
                                        orderItem: $orderItem,
                                        parentItem: $orderItem->getParentItem(),
                                    )
                                ),
                                $order2->getItems(),
                            ),
                        ],
                    ],
                ),
            )->willReturnCallback(
                fn () => match ($invocationRule->getInvocationCount()) {
                    1 => $this->getMockHttpResponse(statusCode: 400),
                    default => $this->getMockHttpResponse(statusCode: 200),
                },
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order1->getEntityId(),
                    'incrementId' => $order1->getIncrementId(),
                    'storeId' => $order1->getStoreId(),
                    'result' => 'Fail',
                ],
                [
                    'orderId' => $order2->getEntityId(),
                    'incrementId' => $order2->getIncrementId(),
                    'storeId' => $order2->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order1,
            syncStatus: Statuses::RETRY,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::QUEUE,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order2,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_ProductType_Configurable(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $attributeCode = 'color_' . date('YmdHis');
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_configurable_' . rand(1, 99999),
                    'name' => 'Configurable Product',
                    'product_type' => Configurable::TYPE_CODE,
                    'options' => [
                        $attributeCode => 'blue',
                    ],
                    'qty' => 2.0,
                    'configuration' => [
                        'configurable_attribute_codes' => [$attributeCode],
                        'variants' => [
                            [
                                $attributeCode => 'red',
                                'name' => 'Variant Product (Red)',
                                'price' => 100.0,
                            ],
                            [
                                $attributeCode => 'blue',
                                'name' => 'Variant Product (Blue)',
                                'price' => 200.0,
                            ],
                        ],
                    ],
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->once();
        /** @var OrderItemInterface $orderItem */
        $orderItem = current(
            array_filter(
                $order->getItems(),
                static fn (OrderItemInterface $orderItem): bool => (
                    Configurable::TYPE_CODE !== $orderItem->getProductType()
                ),
            ),
        );
        $parentOrderItem = $orderItem->getParentItem();

        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => [
                                [
                                    'order_id' => $order->getEntityId(),
                                    'order_line_id' => $orderItem->getItemId(),
                                    'item_name' => 'Configurable Product',
                                    'item_id' => $parentOrderItem->getProductId()
                                        . '-'
                                        . $orderItem->getProductId(),
                                    'item_group_id' => $parentOrderItem->getProductId(),
                                    'item_variant_id' => $orderItem->getProductId(),
                                    'unit_price' => '200.00',
                                    'currency' => 'USD',
                                    'units' => 2,
                                ],
                            ],
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_ProductType_Bundle(): void
    {
        $this->markTestIncomplete();
    }

    public function testExecute_ProductType_Grouped(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $parentSku = 'test_product_grouped_' . rand(1, 99999);
        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => $parentSku,
                    'name' => 'Grouped Product',
                    'product_type' => Grouped::TYPE_CODE,
                    'options' => [
                        $parentSku . '_lp1' => 2.0,
                        $parentSku . '_lp2' => 0.0,
                        $parentSku . '_lp3' => 1.0,
                    ],
                    'configuration' => [
                        'product_links' => [
                            [
                                'sku' => $parentSku . '_lp1',
                                'name' => 'Linked Product 1',
                                'price' => 100.0,
                            ],
                            [
                                'sku' => $parentSku . '_lp2',
                                'name' => 'Linked Product 2',
                                'price' => 500.0,
                            ],
                            [
                                'sku' => $parentSku . '_lp3',
                                'name' => 'Linked Product 3',
                                'price' => 75.0,
                            ],
                        ],
                    ],
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->once();
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => [
                                function (OrderInterface $order) use ($parentSku): array {
                                    /** @var OrderItemInterface $firstOrderItem */
                                    $firstOrderItem = current(
                                        array_filter(
                                            $order->getItems(),
                                            static fn (OrderItemInterface $orderItem): bool => (
                                                Grouped::TYPE_CODE === $orderItem->getProductType()
                                            ),
                                        ),
                                    );
                                    /** @var ProductRepositoryInterface $productRepository */
                                    $productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
                                    $groupedProduct = $productRepository->get($parentSku);

                                    return [
                                        'order_id' => $order->getEntityId(),
                                        'order_line_id' => $firstOrderItem->getItemId() . '-consolidated-grouped',
                                        'item_name' => 'Grouped Product',
                                        'item_id' => (string)$groupedProduct->getId(),
                                        'item_group_id' => (string)$groupedProduct->getId(),
                                        'item_variant_id' => (string)$groupedProduct->getId(),
                                        'unit_price' => '275.00',
                                        'currency' => 'USD',
                                        'units' => 1,
                                    ];
                                },
                            ],
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_ProductType_Virtual(): void
    {
        $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $this->setExcludedOrderStatusesForStore(
            storeCode: 'klevu_analytics_test_store_1',
            excludedOrderStatuses: [],
        );

        $order = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'remote_ip' => '127.0.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_virtual_' . rand(1, 99999),
                    'name' => 'Test Virtual',
                    'product_type' => ProductType::TYPE_VIRTUAL,
                    'price' => 125.55,
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );

        $invocationRule = $this->once();
        $orderItem = current($order->getItems());
        $this->mockHttpClient->expects($invocationRule)
            ->method('sendRequest')
            ->with(
                $this->httpPayloadCallback(
                    invocationRule: $invocationRule,
                    fixtures: [
                        [
                            'order' => $order,
                            'config' => array_merge(
                                $this->systemConfig,
                                [
                                    ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY => 'klevu-1234567890',
                                ],
                            ),
                            'expected_items' => [
                                [
                                    'order_id' => $order->getEntityId(),
                                    'order_line_id' => $orderItem->getItemId(),
                                    'item_name' => 'Test Virtual',
                                    'item_id' => $orderItem->getProductId(),
                                    'item_group_id' => $orderItem->getProductId(),
                                    'item_variant_id' => $orderItem->getProductId(),
                                    'unit_price' => '125.55',
                                    'currency' => 'USD',
                                    'units' => 1,
                                ],
                            ],
                        ],
                    ],
                ),
            )->willReturn(
                $this->getMockHttpResponse(statusCode: 200),
            );

        $processOrderEventsService = $this->instantiateTestObject();
        $result = $processOrderEventsService->execute(
            searchCriteria: null,
            via: 'PHPUnit: Execute Test',
        );

        $this->assertProcessEventsResult(
            result: $result,
            expectedStatus: ProcessEventsResultStatuses::SUCCESS,
            expectedPipelineResult: [
                [
                    'orderId' => $order->getEntityId(),
                    'incrementId' => $order->getIncrementId(),
                    'storeId' => $order->getStoreId(),
                    'result' => 'Success',
                ],
            ],
            expectedMessages: [],
        );
        $this->assertSyncOrderAndHistoryForOrder(
            order: $order,
            syncStatus: Statuses::SYNCED,
            expectedSyncOrderHistory: [
                [
                    'action' => Actions::PROCESS_START,
                    'result' => Results::SUCCESS,
                ],
                [
                    'action' => Actions::PROCESS_END,
                    'result' => Results::SUCCESS,
                ],
            ],
        );
    }

    public function testExecute_ProductType_Downloadable(): void
    {
        $this->markTestIncomplete();
    }

    public function testExecute_ProductType_GiftCard(): void
    {
        $this->markTestIncomplete();
    }

    // -- Prerequisites

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
     * @param string $storeCode
     * @param string[] $excludedOrderStatuses
     * @return void
     */
    private function setExcludedOrderStatusesForStore(
        string $storeCode,
        array $excludedOrderStatuses,
    ): void {
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: implode(',', $excludedOrderStatuses),
            storeCode: $storeCode,
        );
    }

    /**
     * @return void
     */
    private function setSystemConfig(): void
    {
        foreach ($this->systemConfig as $path => $value) {
            ConfigFixture::setGlobal(
                path: $path,
                value: $value,
            );
        }
    }

    // -- Fixtures and Mocks

    /**
     * @param string $storeCode
     * @param WebsiteInterface $website
     * @param string|null $klevuApiKey
     * @param bool $syncEnabled
     * @return StoreInterface
     * @throws \Exception
     */
    private function createStoreFixture(
        string $storeCode,
        WebsiteInterface $website,
        ?string $klevuApiKey,
        bool $syncEnabled,
    ): StoreInterface {
        try {
            $store = $this->storeManager->getStore($storeCode);
            $this->storeFixturesPool->add($store);
        } catch (NoSuchEntityException) {
            $this->createStore([
                'code' => $storeCode,
                'key' => $storeCode,
                'website_id' => (int)$website->getId(),
                'with_sequence' => true,
            ]);
            $storeFixture = $this->storeFixturesPool->get($storeCode);
            $store = $storeFixture->get();
        }

        if ($klevuApiKey) {
            ConfigFixture::setForStore(
                path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
                value: $klevuApiKey,
                storeCode: $storeCode,
            );
            ConfigFixture::setForStore(
                path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
                value: self::FIXTURE_REST_AUTH_KEY,
                storeCode: $storeCode,
            );
        }
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: (int)$syncEnabled,
            storeCode: $storeCode,
        );

        return $store;
    }

    /**
     * @param string $storeCode
     * @param string $status
     * @param mixed[] $orderData
     * @param mixed[] $orderItemsData
     * @param Statuses $syncStatus
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @todo Alternative product types
     */
    private function createOrderInStore(
        string $storeCode,
        string $status,
        array $orderData,
        array $orderItemsData,
        Statuses $syncStatus,
    ): OrderInterface {
        $store = $this->storeManager->getStore($storeCode);
        $this->storeManager->setCurrentStore($store);

        $orderBuilder = OrderBuilder::anOrder();
        $orderBuilder = $orderBuilder->withProducts(
            ...array_map(
                static function (array $orderItemData): ProductBuilder {
                    switch ($orderItemData['product_type'] ?? ProductType::TYPE_SIMPLE) {
                        case ProductType::TYPE_SIMPLE:
                            $return = ProductBuilder::aSimpleProduct();
                            $return = $return->withData($orderItemData);
                            $return = $return->withSku($orderItemData['sku']);
                            break;

                        case ProductType::TYPE_VIRTUAL:
                            $return = ProductBuilder::aVirtualProduct();
                            $return = $return->withData($orderItemData);
                            $return = $return->withSku($orderItemData['sku']);
                            break;

                        case Grouped::TYPE_CODE:
                            $return = GroupedProductBuilder::aGroupedProduct()
                                ->withSku($orderItemData['sku']);
                            if (isset($orderItemData['name'])) {
                                $return = $return->withName($orderItemData['name']);
                            }

                            $configuration = $orderItemData['configuration'];
                            foreach ($configuration['product_links'] ?? [] as $linkedProductData) {
                                $linkedProductBuilder = ProductBuilder::aSimpleProduct();
                                $linkedProductBuilder = $linkedProductBuilder->withData($linkedProductData);

                                $linkedProductBuilder = $linkedProductBuilder->withSku(
                                    sku: $linkedProductData['sku'] ?? ($orderItemData['sku'] . '_lp' . rand(0, 99)),
                                );
                                $linkedProductBuilder = $linkedProductBuilder->withPrice(
                                    price: $linkedProductData['price'] ?? 100.00,
                                );

                                $return = $return->withLinkedProduct($linkedProductBuilder);
                            }
                            break;

                        case Configurable::TYPE_CODE:
                            $return = ConfigurableProductBuilder::aConfigurableProduct()
                                ->withSku($orderItemData['sku']);
                            if (isset($orderItemData['name'])) {
                                $return = $return->withName($orderItemData['name']);
                            }

                            $configuration = $orderItemData['configuration'];
                            foreach ($configuration['configurable_attribute_codes'] ?? [] as $attributeCode) {
                                $return = $return->withConfigurableAttribute($attributeCode);
                            }
                            foreach ($configuration['variants'] ?? [] as $variantProductData) {
                                $variantProductBuilder = ProductBuilder::aSimpleProduct();
                                $variantProductBuilder = $variantProductBuilder->withData($variantProductData);

                                $variantProductBuilder = $variantProductBuilder->withSku(
                                    sku: $variantProductData['sku'] ?? ($orderItemData['sku'] . '_v' . rand(0, 99)),
                                );
                                $variantProductBuilder = $variantProductBuilder->withPrice(
                                    price: $variantProductData['price'] ?? 100.00,
                                );

                                $return = $return->withVariant($variantProductBuilder);
                            }
                            break;

                        default:
                            throw new \LogicException(sprintf(
                                'Unsupported product type %s',
                                $orderItemData['product_type'] ?? null,
                            ));
                    }

                    return $return;
                },
                $orderItemsData,
            ),
        );

        $cartBuilder = CartBuilder::forCurrentSession();
        foreach ($orderItemsData as $orderItem) {
            $cartBuilder = match ($orderItem['product_type'] ?? 'simple') {
                Grouped::TYPE_CODE => $cartBuilder->withGroupedProduct(
                    sku: $orderItem['sku'],
                    options: $orderItem['options'] ?? [],
                    qty: $orderItem['qty'] ?? 1,
                ),
                Configurable::TYPE_CODE => $cartBuilder->withConfigurableProduct(
                    sku: $orderItem['sku'],
                    options: $orderItem['options'] ?? [],
                    qty: $orderItem['qty'] ?? 1,
                ),
                default => $cartBuilder->withSimpleProduct(
                    sku: $orderItem['sku'],
                    qty: $orderItem['qty'] ?? 1.0,
                ),
            };
        }

        $orderBuilder = $orderBuilder->withCart($cartBuilder);

        $order = $orderBuilder->build();
        foreach ($orderData as $key => $value) {
            $order->setDataUsingMethod($key, $value);
        }

        switch ($status) {
            case 'processing':
                $this->invoiceOrder($order);
                break;

            case 'complete':
                $this->invoiceOrder($order);
                $this->shipOrder($order);
                break;
        }

        $this->orderFixtures[$order->getEntityId()] = $order;

        $this->createOrUpdateSyncOrderRecord(
            order: $order,
            syncStatus: $syncStatus,
        );

        return $order;
    }

    /**
     * @param OrderInterface|Order $order
     * @return ShipmentInterface
     */
    private function shipOrder(
        OrderInterface $order,
    ): ShipmentInterface {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        $shipmentBuilder = ShipmentBuilder::forOrder($order);

        return $shipmentBuilder->build();
    }

    /**
     * @param OrderInterface|Order $order
     * @return InvoiceInterface
     */
    private function invoiceOrder(
        OrderInterface $order,
    ): InvoiceInterface {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        $invoiceBuilder = InvoiceBuilder::forOrder($order);

        return $invoiceBuilder->build();
    }

    /**
     * @param OrderInterface $order
     * @param Statuses $syncStatus
     * @param int|null $attempts
     * @return void
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    private function createOrUpdateSyncOrderRecord(
        OrderInterface $order,
        Statuses $syncStatus,
        ?int $attempts = null,
    ): void {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        switch ($syncStatus) {
            case Statuses::QUEUED:
            case Statuses::RETRY:
            case Statuses::PARTIAL:
                /** @var QueueOrderForSyncActionInterface $queueOrderForSyncAction */
                $queueOrderForSyncAction = $this->objectManager->get(QueueOrderForSyncActionInterface::class);
                $queueOrderForSyncAction->execute(
                    orderId: (int)$order->getId(),
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            case Statuses::PROCESSING:
                /** @var MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction */
                $markOrderAsProcessingAction = $this->objectManager->get(MarkOrderAsProcessingActionInterface::class);
                $markOrderAsProcessingAction->execute(
                    orderId: (int)$order->getId(),
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            case Statuses::SYNCED:
            case Statuses::ERROR:
                /** @var MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction */
                $markOrderAsProcessedAction = $this->objectManager->get(MarkOrderAsProcessedActionInterface::class);
                $markOrderAsProcessedAction->execute(
                    orderId: (int)$order->getId(),
                    resultStatus: $syncStatus->value,
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            default:
                break;
        }

        if (null !== $attempts) {
            $syncOrder = $this->syncOrderRepository->getByOrderId(
                orderId: (int)$order->getEntityId(),
            );
            $syncOrder->setAttempts($attempts);
            $this->syncOrderRepository->save($syncOrder);
        }
    }

    // -- Assertions

    /**
     * @param ProcessEventsResultInterface $result
     * @param ProcessEventsResultStatuses $expectedStatus
     * @param mixed $expectedPipelineResult
     * @param string[] $expectedMessages
     * @return bool
     */
    private function assertProcessEventsResult(
        ProcessEventsResultInterface $result,
        ProcessEventsResultStatuses $expectedStatus,
        mixed $expectedPipelineResult,
        array $expectedMessages,
    ): bool {
        $messages = $result->getMessages();
        asort($messages);
        asort($expectedMessages);
        $this->assertSame(
            expected: $expectedMessages,
            actual: $messages,
        );

        $this->assertEquals(
            expected: $expectedStatus,
            actual: $result->getStatus(),
            message: 'Status',
        );

        if (is_object($expectedPipelineResult)) {
            $this->assertEquals(
                expected: $expectedPipelineResult,
                actual: $result->getPipelineResult(),
                message: 'Pipeline Result (object)',
            );
        } else {
            $this->assertSame(
                expected: $expectedPipelineResult,
                actual: $result->getPipelineResult(),
                message: 'Pipeline Result',
            );
        }

        return true;
    }

    /**
     * @param OrderInterface $order
     * @param Statuses $syncStatus
     * @param array<int|string, array<int|string, Actions>> $expectedSyncOrderHistory
     * @return void
     */
    private function assertSyncOrderAndHistoryForOrder(
        OrderInterface $order,
        Statuses $syncStatus,
        array $expectedSyncOrderHistory,
    ): void {
        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId(
                orderId: (int)$order->getEntityId(),
            );
        } catch (NoSuchEntityException) {
            $this->fail(
                sprintf('No SyncOrder found for order #%s', $order->getEntityId()),
            );
        }

        $this->assertSame(
            expected: (int)$order->getStoreId(),
            actual: $syncOrder->getStoreId(),
            message: 'Store ID',
        );
        $this->assertSame(
            expected: $syncStatus->value,
            actual: $syncOrder->getStatus(),
            message: 'Sync Status',
        );

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_VIA,
            value: 'PHPUnit: Execute Test',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->sortOrderBuilder->setField(SyncOrderHistory::FIELD_ENTITY_ID);
        $this->sortOrderBuilder->setAscendingDirection();
        $this->searchCriteriaBuilder->addSortOrder(
            sortOrder: $this->sortOrderBuilder->create(),
        );

        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $this->assertSame(
            expected: count($expectedSyncOrderHistory),
            actual: $syncOrderHistoryResult->getTotalCount(),
            message: 'Total sync order history items created',
        );

        /** @var SyncOrderHistoryInterface[] $syncOrderHistoryItems */
        $syncOrderHistoryItems = array_values(
            array: $syncOrderHistoryResult->getItems(),
        );
        foreach ($syncOrderHistoryItems as $itemIndex => $syncOrderHistoryItem) {
            $expectedSyncOrderHistoryItem = $expectedSyncOrderHistory[$itemIndex] ?? [];
            $this->assertSame(
                expected: $expectedSyncOrderHistoryItem['action']->value,
                actual: $syncOrderHistoryItem->getAction(),
                message: sprintf('Action [%d]', $itemIndex),
            );
            $this->assertSame(
                expected: $expectedSyncOrderHistoryItem['result']->value,
                actual: $syncOrderHistoryItem->getResult(),
                message: sprintf('Result [%d]', $itemIndex),
            );
        }
    }

    // -- Mocks

    /**
     * @param int $statusCode
     * @return MockObject&ResponseInterface
     */
    private function getMockHttpResponse(
        int $statusCode,
    ): ResponseInterface&MockObject {
        $mockStream = $this->getMockBuilder(StreamInterface::class)->getMock();
        $mockStream->method('getContents')->willReturn('');

        $mockHttpResponse = $this->getMockBuilder(ResponseInterface::class)->getMock();
        $mockHttpResponse->method('getBody')->willReturn($mockStream);
        $mockHttpResponse->method('getStatusCode')->willReturn($statusCode);
        $mockHttpResponse->method('getHeaders')->willReturn([]);

        return $mockHttpResponse;
    }

    /**
     * @param InvocationOrder $invocationRule
     * @param mixed[] $fixtures
     * @return Callback<RequestInterface>
     */
    private function httpPayloadCallback(
        InvocationOrder $invocationRule,
        array $fixtures,
    ): Callback {
        return $this->callback(
            function (RequestInterface $request) use ($invocationRule, $fixtures): bool {
                $fixture = $fixtures[$invocationRule->getInvocationCount() - 1];
                /** @var OrderInterface $order */
                $order = $fixture['order'];
                /** @var mixed[] $config */
                $config = $fixture['config'];

                $requestBody = $request->getBody();
                $requestPayload = $this->serializer->unserialize(
                    string: $requestBody->getContents(),
                );

                $this->assertIsArray($requestPayload);
                $this->assertCount(
                    expectedCount: 1,
                    haystack: $requestPayload,
                );

                $event = current($requestPayload);
                $this->assertIsArray($event);

                $this->assertArrayHasKey('event', $event);
                $this->assertSame('order_purchase', $event['event']);

                $this->assertArrayHasKey('event_apikey', $event);
                $this->assertSame(
                    expected: $config[ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY],
                    actual: $event['event_apikey'],
                );

                $this->assertArrayHasKey('event_version', $event);
                $this->assertSame('1.0.0', $event['event_version']);

                $this->assertArrayHasKey('user_profile', $event);
                $this->assertIsArray($event['user_profile']);
                $this->assertArrayHasKey('ip_address', $event['user_profile']);

                $expectedIp = 'x_forwarded_for' === $config[Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE]
                    ? $order->getXForwardedFor()
                    : $order->getRemoteIp();
                $expectedIp = current(
                    array_map('trim', explode(',', $expectedIp)),
                );
                $this->assertSame(
                    expected: $expectedIp,
                    actual: $event['user_profile']['ip_address'],
                );
                $this->assertArrayHasKey('email', $event['user_profile']);
                $this->assertMatchesRegularExpression(
                    pattern: '/^cep-[0-9a-f]{64}$/',
                    string: $event['user_profile']['email'],
                );

                $this->assertArrayHasKey('event_data', $event);
                $this->assertIsArray($event['event_data']);
                $this->assertArrayHasKey('items', $event['event_data']);
                $this->assertIsArray($event['event_data']['items']);

                $expectedItems = $fixture['expected_items'] ?? null;
                if (null !== $expectedItems) {
                    $this->assertCount(
                        expectedCount: count($expectedItems),
                        haystack: $event['event_data']['items'],
                        message: 'Expected count of event data items',
                    );
                    foreach ($event['event_data']['items'] as $i => $eventItem) {
                        $this->assertSame(
                            expected: is_callable($expectedItems[$i])
                                ? $expectedItems[$i]($order)
                                : $expectedItems[$i],
                            actual: $eventItem,
                            message: sprintf('Event item #%d', $i),
                        );
                    }
                }

                return true;
            },
        );
    }

    /**
     * @param OrderItemInterface $orderItem
     * @param OrderItemInterface|null $parentItem
     * @return mixed[]
     */
    private function getExpectedEventDataForOrderItem(
        OrderItemInterface $orderItem,
        ?OrderItemInterface $parentItem,
    ): array {
        $return = [
            'order_id' => $orderItem->getOrderId(),
            'order_line_id' => $orderItem->getItemId(),
            'item_name' => null,
            'item_id' => null,
            'item_group_id' => null,
            'item_variant_id' => $orderItem->getProductId(),
            'unit_price' => null,
            'currency' => method_exists($orderItem, 'getOrder')
                ? $orderItem->getOrder()->getBaseCurrencyCode()
                : null,
            'units' => (int)$orderItem->getQtyOrdered(),
        ];

        switch (true) {
            case 'configurable' === $parentItem?->getProductType():
                $return['item_name'] = $parentItem->getName();
                $return['item_id'] = $parentItem->getProductId() . '-' . $orderItem->getProductId();
                $return['item_group_id'] = $parentItem->getProductId();
                $return['unit_price'] = $parentItem->getPriceInclTax();
                break;

            default:
                $return['item_name'] = $orderItem->getName();
                $return['item_id'] = $orderItem->getProductId();
                $return['item_group_id'] = $orderItem->getProductId();
                $return['unit_price'] = $orderItem->getPriceInclTax();
                break;
        }
        $return['unit_price'] = number_format(
            num: $return['unit_price'],
            decimals: 2,
            thousands_separator: '',
        );

        return $return;
    }

    // -- Misc

    /**
     * @return string[]
     */
    private function getExpectedFqcns(): array // @phpstan-ignore-line Used in traits
    {
        $expectedFqcns = $this->trait_getExpectedFqcns();
        $expectedFqcns[] = ProcessEvents::class;

        return $expectedFqcns;
    }
}

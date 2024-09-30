<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Service\Provider\MagentoOrderIdsProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\MagentoOrderIdsProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Sales\InvoiceBuilder;
use TddWizard\Fixtures\Sales\ShipmentBuilder;

/**
 * @method MagentoOrderIdsProvider instantiateTestObject(?array $arguments = null)
 * @method MagentoOrderIdsProvider instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MagentoOrderIdsProviderTest extends TestCase
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
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var OrderRepositoryInterface|null
     */
    private ?OrderRepositoryInterface $orderRepository = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MagentoOrderIdsProvider::class;
        $this->interfaceFqcn = MagentoOrderIdsProviderInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->orderFixtures = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetByCriteria_OrderId(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getOrderFixture(false);
        }
        $allOrderIds = array_map(
            static fn (OrderInterface $order): int => (int)$order->getEntityId(),
            array_values($this->orderFixtures),
        );

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: $allOrderIds,
            conditionType: 'in',
        );
        $this->searchCriteriaBuilder->setPageSize(2);
        $this->searchCriteriaBuilder->setCurrentPage(1);

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $this->assertSame(
            expected: array_slice(
                array: $allOrderIds,
                offset: 0,
                length: 2,
            ),
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetByCriteria_OrderStatus(): void
    {
        $orders = [];

        for ($i = 0; $i < 5; $i++) {
            $orders[] = $this->getOrderFixture();
        }
        /** @var array<OrderInterface&Order> $orders */
        InvoiceBuilder::forOrder($orders[0])->build();
        InvoiceBuilder::forOrder($orders[1])->build();
        ShipmentBuilder::forOrder($orders[1])->build();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_status',
            value: ['processing', 'complete'],
            conditionType: 'in',
        );

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $searchCriteria = $this->searchCriteriaBuilder->create();

        $expectedResult = [
            (int)$orders[0]->getEntityId(),
            (int)$orders[1]->getEntityId(),
        ];
        sort($expectedResult);
        $actualResult = $magentoOrderIdsProvider->getByCriteria(
            $searchCriteria,
        );
        sort($actualResult);

        $this->assertEquals(
            expected: $expectedResult,
            actual: $actualResult,
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testGetByCriteria_StoreId(): void
    {
        $testStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $testStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: false,
        );

        $this->initOrderSyncEnabled(false);
        for ($i = 0; $i < 5; $i++) {
            $this->createOrderInStore(
                storeCode: 'klevu_analytics_test_store_1',
                status: 'pending',
                orderData: [],
                orderItemsData: [
                    [
                        'sku' => 'test_product_' . rand(1, 99999),
                    ],
                ],
                syncStatus: Statuses::NOT_REGISTERED,
            );
        }
        $allOrderIds = array_map(
            static fn (OrderInterface $order): int => (int)$order->getEntityId(),
            array_values($this->orderFixtures),
        );

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'store_id',
            value: $testStore1->getId(),
            conditionType: 'eq',
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $this->assertSame(
            expected: $allOrderIds,
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );

        $this->searchCriteriaBuilder->addFilter(
            field: 'store_id',
            value: (int)$testStore2->getId(),
            conditionType: 'eq',
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $this->assertSame(
            expected: [],
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetByCriteria_SyncStatus(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $this->getOrderFixture();
        }
        $syncOrder = $this->syncOrderRepository->getByOrderId(
            (int)$orders[1]->getEntityId(),
        );
        $syncOrder->setStatus('processing');
        $this->syncOrderRepository->save($syncOrder);

        $syncOrder = $this->syncOrderRepository->getByOrderId(
            (int)$orders[3]->getEntityId(),
        );
        $syncOrder->setStatus('error');
        $this->syncOrderRepository->save($syncOrder);

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_status',
            value: ['queued', 'retry'],
            conditionType: 'nin',
        );

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $this->assertSame(
            expected: [
                (int)$orders[1]->getEntityId(),
                (int)$orders[3]->getEntityId(),
            ],
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetByCriteria_SyncAttempts(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $this->getOrderFixture();
        }
        $syncOrder = $this->syncOrderRepository->getByOrderId(
            (int)$orders[2]->getEntityId(),
        );
        $syncOrder->setAttempts(3);
        $this->syncOrderRepository->save($syncOrder);

        $syncOrder = $this->syncOrderRepository->getByOrderId(
            (int)$orders[4]->getEntityId(),
        );
        $syncOrder->setAttempts(6);
        $this->syncOrderRepository->save($syncOrder);

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_attempts',
            value: 3,
            conditionType: 'gteq',
        );

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $this->assertSame(
            expected: [
                (int)$orders[2]->getEntityId(),
                (int)$orders[4]->getEntityId(),
            ],
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );
    }
}

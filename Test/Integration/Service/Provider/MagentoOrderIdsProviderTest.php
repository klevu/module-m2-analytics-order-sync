<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Service\Provider\MagentoOrderIdsProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
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
use Magento\Sales\Model\Order;
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
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
    use OrderTrait;
    use StoreTrait;

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
        $this->assertEquals(
            expected: [
                (int)$orders[0]->getEntityId(),
                (int)$orders[1]->getEntityId(),
            ],
            actual: $magentoOrderIdsProvider->getByCriteria(
                $searchCriteria,
            ),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testGetByCriteria_StoreId(): void
    {
        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
        $testStore = $this->storeFixturesPool->get('test_store_1');

        for ($i = 0; $i < 5; $i++) {
            $this->getOrderFixture(false);
        }
        $allOrderIds = array_map(
            static fn (OrderInterface $order): int => (int)$order->getEntityId(),
            array_values($this->orderFixtures),
        );

        $magentoOrderIdsProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'store_id',
            value: 1,
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
            value: (int)$testStore->getId(),
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
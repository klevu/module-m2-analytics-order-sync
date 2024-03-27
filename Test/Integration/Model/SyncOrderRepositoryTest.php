<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model;

use Klevu\AnalyticsApi\Model\AndFilterBuilder;
use Klevu\AnalyticsOrderSync\Model\SyncOrderRepository;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Sales\InvoiceBuilder;
use TddWizard\Fixtures\Sales\ShipmentBuilder;

/**
 * @method SyncOrderRepository instantiateTestObject(?array $arguments = null)
 * @method SyncOrderRepository instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncOrderRepositoryTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var AndFilterBuilder|null
     */
    private ?AndFilterBuilder $filterBuilder = null;
    /**
     * @var FilterGroupBuilder|null
     */
    private ?FilterGroupBuilder $filterGroupBuilder = null;
    /**
     * @var SyncOrderInterfaceFactory|null
     */
    private ?SyncOrderInterfaceFactory $syncOrderFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrderRepository::class;
        $this->interfaceFqcn = SyncOrderRepositoryInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->filterBuilder = $this->objectManager->get(AndFilterBuilder::class);
        $this->filterGroupBuilder = $this->objectManager->get(FilterGroupBuilder::class);
        $this->syncOrderFactory = $this->objectManager->get(SyncOrderInterfaceFactory::class);

        $this->orderFixtures = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
    }

    public function testGetById_NotExists(): void
    {
        $syncOrderRepository = $this->instantiateTestObject();

        $this->expectException(NoSuchEntityException::class);
        $syncOrderRepository->getById(9999999999);
    }

    public function testGetByOrderId_NotExists(): void
    {
        $syncOrderRepository = $this->instantiateTestObject();

        $this->expectException(NoSuchEntityException::class);
        $syncOrderRepository->getByOrderId(9999999999);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetByOrderId_Exists(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrder = $syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );

        $this->assertInstanceOf(SyncOrderInterface::class, $syncOrder);
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertGreaterThan(0, $syncOrder->getEntityId());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetById_Exists(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrderFromOrder = $syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );
        $syncOrder = $syncOrderRepository->getById(
            $syncOrderFromOrder->getEntityId(),
        );

        $this->assertSame($syncOrderFromOrder->getEntityId(), $syncOrder->getEntityId());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testDelete(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();
        $syncOrder = $syncOrderRepository->getByOrderId((int)$order->getEntityId());

        $syncOrderRepository->delete($syncOrder);

        $this->expectException(NoSuchEntityException::class);
        $syncOrderRepository->getByOrderId((int)$order->getEntityId());
    }

    public function testDeleteById_NotExists(): void
    {
        $syncOrderRepository = $this->instantiateTestObject();

        $this->expectException(NoSuchEntityException::class);
        $syncOrderRepository->deleteById(-1);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testDeleteById_Exists(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();
        $syncOrder = $syncOrderRepository->getByOrderId((int)$order->getEntityId());

        $syncOrderRepository->deleteById($syncOrder->getEntityId());

        $this->expectException(NoSuchEntityException::class);
        $syncOrderRepository->getByOrderId((int)$order->getEntityId());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testSave_Create(): void
    {
        $order = $this->getOrderFixture();
        if (!method_exists($order, 'getId')) {
            throw new \LogicException(sprintf(
                'Order object of type %s does not contain method getId()',
                $order::class,
            ));
        }

        $syncOrderRepository = $this->instantiateTestObject();

        $originalSyncOrder = $syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $originalSyncOrderId = $originalSyncOrder->getEntityId();
        $this->assertSame('queued', $originalSyncOrder->getStatus());
        $this->assertSame(0, $originalSyncOrder->getAttempts());

        $syncOrderRepository->delete($originalSyncOrder);

        $syncOrder = $this->syncOrderFactory->create();
        $syncOrder->setOrderId((int)$order->getId());
        $syncOrder->setStoreId((int)$order->getStoreId());
        $syncOrder->setStatus('retry');
        $syncOrder->setAttempts(5);

        $this->assertNull($syncOrder->getEntityId());

        $syncOrder = $syncOrderRepository->save($syncOrder);

        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertNotSame($originalSyncOrderId, $syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('retry', $syncOrder->getStatus());
        $this->assertSame(5, $syncOrder->getAttempts());
    }

    public function testSave_Create_ForNonExistentOrder(): void
    {
        $syncOrder = $this->syncOrderFactory->create();
        $syncOrder->setOrderId(999999999);
        $syncOrder->setStoreId(1);
        $syncOrder->setStatus('queued');
        $syncOrder->setAttempts(0);

        $syncOrderRepository = $this->instantiateTestObject();

        $this->expectException(CouldNotSaveException::class);
        $syncOrderRepository->save($syncOrder);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testSave_Update(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrder = $syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $originalSyncOrderId = $syncOrder->getEntityId();
        $this->assertSame('queued', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());

        $syncOrder->setStatus('retry');
        $syncOrder->setAttempts(5);

        $syncOrder = $syncOrderRepository->save($syncOrder);

        $this->assertSame($originalSyncOrderId, $syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('retry', $syncOrder->getStatus());
        $this->assertSame(5, $syncOrder->getAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testSave_Update_Invalid(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrder = $syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertSame('queued', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());

        $syncOrder->setStatus('retry');
        $syncOrder->setAttempts(5);
        $syncOrder->setStoreId(9999999);

        $this->expectException(CouldNotSaveException::class);
        $syncOrderRepository->save($syncOrder);
    }

    public function testGetList_NoResults(): void
    {
        $this->searchCriteriaBuilder->addFilter(
            field: 'entity_id',
            value: 0,
            conditionType: 'lt',
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrdersResult = $syncOrderRepository->getList($searchCriteria);
        $this->assertEquals(0, $syncOrdersResult->getTotalCount());
        $this->assertEmpty($syncOrdersResult->getItems());
        $this->assertSame($searchCriteria, $syncOrdersResult->getSearchCriteria());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetList_ComplexSearchCriteria(): void
    {
        $this->loadComplexSearchCriteriaFixtures();

        /**
         * attempts > 100
         * AND
         * (sync_status = "queued" OR sync_status = "retry")
         * AND
         * (
         *     (order_status = "processing" AND store_id = 1)
         *      OR
         *     order_status = "complete"
         * )
         */
        $attemptsFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                field: 'attempts',
                conditionType: 'gt',
                value: 100,
            ),
        ]);
        $syncStatusFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                field: 'sync_status',
                conditionType: 'eq',
                value: 'queued',
            ),
            $this->createFilter(
                field: 'status',
                conditionType: 'in',
                value: ['retry'],
            ),
        ]);
        $andFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                filters: [
                    $this->createFilter(
                        field: 'order_status',
                        conditionType: 'eq',
                        value: 'processing',
                    ),
                    $this->createFilter(
                        field: 'store_id',
                        conditionType: 'eq',
                        value: 1,
                    ),
                ],
            ),
            $this->createFilter(
                field: 'order_status',
                conditionType: 'eq',
                value: 'complete',
            ),
        ]);

        $this->searchCriteriaBuilder->setPageSize(1);
        $this->searchCriteriaBuilder->setCurrentPage(1);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $searchCriteria->setFilterGroups([
            $attemptsFilterGroup,
            $syncStatusFilterGroup,
            $andFilterGroup,
        ]);

        $syncOrderRepository = $this->instantiateTestObject();
        $syncOrdersResults = $syncOrderRepository->getList($searchCriteria);

        $this->assertSame(2, $syncOrdersResults->getTotalCount());
        $this->assertCount(1, $syncOrdersResults->getItems());
        $this->assertSame($searchCriteria, $syncOrdersResults->getSearchCriteria());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetList_PageExceedsBounds(): void
    {
        $this->loadComplexSearchCriteriaFixtures();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: array_map(
                static fn (OrderInterface $order): int => (int)$order->getEntityId(),
                $this->orderFixtures,
            ),
            conditionType: 'in',
        );
        $this->searchCriteriaBuilder->setPageSize(1);
        $this->searchCriteriaBuilder->setCurrentPage(100);
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderRepository = $this->instantiateTestObject();
        $syncOrderResults = $syncOrderRepository->getList($searchCriteria);

        $this->assertSame(5, $syncOrderResults->getTotalCount());
        $this->assertEmpty($syncOrderResults->getItems());
        $this->assertSame($searchCriteria, $syncOrderResults->getSearchCriteria());
    }

    public function testClearCache(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderRepository = $this->instantiateTestObject();

        $syncOrderByOrderId = $syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );
        $this->assertSame(0, $syncOrderByOrderId->getAttempts());
        $syncOrderByOrderId->setAttempts(15);

        $syncOrderByOrderIdReloadedFromCache = $syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );
        // Object saved to cache by reference so changes above will be reflected
        $this->assertSame(15, $syncOrderByOrderIdReloadedFromCache->getAttempts());

        $syncOrderById = $syncOrderRepository->getById(
            $syncOrderByOrderId->getEntityId(),
        );
        // By Id and By Order Id have separate caches
        $this->assertSame(0, $syncOrderById->getAttempts());
        $syncOrderById->setAttempts(20);

        $syncOrderByIdReloadedFromCache = $syncOrderRepository->getById(
            $syncOrderById->getEntityId(),
        );
        // Cached object updated by reference
        $this->assertSame(20, $syncOrderByIdReloadedFromCache->getAttempts());

        $syncOrderRepository->clearCache();

        $syncOrderByOrderReloadedFromDb = $syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );
        // Object retrieved from DB following cache clear. No save, no attempts value update
        $this->assertSame(0, $syncOrderByOrderReloadedFromDb->getAttempts());

        $syncOrderByIdReloadedFromDb = $syncOrderRepository->getById(
            $syncOrderById->getEntityId(),
        );
        // Object retrieved from DB following cache clear. No save, no attempts value update
        $this->assertSame(0, $syncOrderByIdReloadedFromDb->getAttempts());
    }

    /**
     * @return void
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     * @throws LocalizedException
     */
    private function loadComplexSearchCriteriaFixtures(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $this->getOrderFixture();
        }
        /** @var array<Order&OrderInterface> $orders */
        InvoiceBuilder::forOrder($orders[0])->build();
        InvoiceBuilder::forOrder($orders[1])->build();
        ShipmentBuilder::forOrder($orders[1])->build();

        $orderIds = array_map(
            static fn (OrderInterface $order): int => (int)$order->getEntityId(),
            $orders,
        );

        $syncOrderRepository = $this->instantiateTestObject();
        $syncOrders = array_map(
            static fn (int $orderId): SyncOrderInterface => $syncOrderRepository->getByOrderId($orderId),
            $orderIds,
        );
        $syncOrders[0]->setAttempts(110);
        $syncOrders[1]->setAttempts(120);
        $syncOrders[1]->setStatus('retry');
        $syncOrders[2]->setAttempts(150);
        $syncOrders[3]->setAttempts(100);
        $syncOrders[4]->setAttempts(200);
        $syncOrders[4]->setStatus('error');
        array_walk(
            $syncOrders,
            static function (SyncOrderInterface $syncOrder) use ($syncOrderRepository): void {
                $syncOrderRepository->save($syncOrder);
            },
        );
    }

    /**
     * @param string|null $field
     * @param string|null $conditionType
     * @param mixed $value
     * @param Filter[]|null $filters
     * @return Filter
     */
    private function createFilter(
        ?string $field = null,
        ?string $conditionType = null,
        mixed $value = null,
        ?array $filters = null,
    ): Filter {
        if (null !== $field) {
            $this->filterBuilder->setField($field);
        }
        if (null !== $conditionType) {
            $this->filterBuilder->setConditionType($conditionType);
        }
        if (null !== $value) {
            $this->filterBuilder->setValue($value);
        }
        if (null !== $filters) {
            $this->filterBuilder->setFilters($filters);
        }

        return $this->filterBuilder->create();
    }

    /**
     * @param Filter[] $filters
     * @return FilterGroup
     */
    private function createFilterGroup(
        array $filters,
    ): FilterGroup {
        /** @var Filter $filter */
        foreach ($filters as $filter) {
            $this->filterGroupBuilder->addFilter($filter);
        }

        $filterGroup = $this->filterGroupBuilder->create();
        if (!($filterGroup instanceof FilterGroup)) {
            throw new \LogicException(sprintf(
                'Filter Group Builder of type %s should return %s, received %s',
                $this->filterGroupBuilder::class,
                FilterGroup::class,
                $filterGroup::class,
            ));
        }

        return $filterGroup;
    }
}

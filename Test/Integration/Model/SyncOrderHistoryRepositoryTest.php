<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model;

use Klevu\AnalyticsApi\Model\AndFilterBuilder;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistoryRepository;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
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
 * @method SyncOrderHistoryRepository instantiateTestObject(?array $arguments = null)
 * @method SyncOrderHistoryRepository instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncOrderHistoryRepositoryTest extends TestCase
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
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var SyncOrderHistoryInterfaceFactory|null
     */
    private ?SyncOrderHistoryInterfaceFactory $syncOrderHistoryFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrderHistoryRepository::class;
        $this->interfaceFqcn = SyncOrderHistoryRepositoryInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->filterBuilder = $this->objectManager->get(AndFilterBuilder::class);
        $this->filterGroupBuilder = $this->objectManager->get(FilterGroupBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryFactory = $this->objectManager->get(SyncOrderHistoryInterfaceFactory::class);

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
        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->expectException(NoSuchEntityException::class);
        $syncOrderHistoryRepository->getById(9999999999);
    }

    public function testGetById_Exists(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistoryFromList */
        $syncOrderHistoryFromList = current($syncOrderHistoryResults->getItems());

        $syncOrderHistory = $syncOrderHistoryRepository->getById(
            $syncOrderHistoryFromList->getEntityId(),
        );

        $this->assertSame(
            $syncOrderHistoryFromList->getEntityId(),
            $syncOrderHistory->getEntityId(),
        );
    }

    public function testDelete(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $syncOrderHistoryId = $syncOrderHistory->getEntityId();

        $syncOrderHistoryRepository->delete($syncOrderHistory);

        $this->expectException(NoSuchEntityException::class);
        $syncOrderHistoryRepository->getById($syncOrderHistoryId);
    }

    public function testDeleteById_NotExists(): void
    {
        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->expectException(NoSuchEntityException::class);
        $syncOrderHistoryRepository->deleteById(-1);
    }

    public function testDeleteById_Exists(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $syncOrderHistoryId = $syncOrderHistory->getEntityId();

        $syncOrderHistoryRepository->deleteById($syncOrderHistoryId);

        $this->expectException(NoSuchEntityException::class);
        $syncOrderHistoryRepository->getById($syncOrderHistoryId);
    }

    public function testSave_Create(): void
    {
        $order = $this->getOrderFixture();
        if (!method_exists($order, 'getId')) {
            throw new \LogicException(sprintf(
                'Order object of type %s does not contain method getId()',
                $order::class,
            ));
        }

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getId());

        $syncOrderHistory = $this->syncOrderHistoryFactory->create();
        $syncOrderHistory->setSyncOrderId($syncOrder->getEntityId());
        $syncOrderHistory->setTimestamp('2000-01-01 00:00:00');
        $syncOrderHistory->setAction(Actions::PROCESS_START->value);
        $syncOrderHistory->setVia('PHPUnit');
        $syncOrderHistory->setResult(Results::NOOP->value);
        $syncOrderHistory->setAdditionalInformation(['foo' => 'bar']);

        $syncOrderHistoryRepository = $this->instantiateTestObject();
        $syncOrderHistory = $syncOrderHistoryRepository->save($syncOrderHistory);
        $syncOrderHistoryId = $syncOrderHistory->getEntityId();

        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame('2000-01-01 00:00:00', $syncOrderHistory->getTimestamp());
        $this->assertSame('process-start', $syncOrderHistory->getAction());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $this->assertSame('noop', $syncOrderHistory->getResult());
        $this->assertSame(['foo' => 'bar'], $syncOrderHistory->getAdditionalInformation());

        $syncOrderHistory = $syncOrderHistoryRepository->getById($syncOrderHistoryId);

        $this->assertSame($syncOrderHistoryId, $syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame('2000-01-01 00:00:00', $syncOrderHistory->getTimestamp());
        $this->assertSame('process-start', $syncOrderHistory->getAction());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $this->assertSame('noop', $syncOrderHistory->getResult());
        $this->assertSame(['foo' => 'bar'], $syncOrderHistory->getAdditionalInformation());
    }

    public function testSave_Create_ForNonExistentSyncOrder(): void
    {
        $syncOrderHistory = $this->syncOrderHistoryFactory->create();
        $syncOrderHistory->setSyncOrderId(-1);
        $syncOrderHistory->setTimestamp('2000-01-01 00:00:00');
        $syncOrderHistory->setAction(Actions::PROCESS_START->value);
        $syncOrderHistory->setVia('PHPUnit');
        $syncOrderHistory->setResult(Results::NOOP->value);
        $syncOrderHistory->setAdditionalInformation(['foo' => 'bar']);

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->expectException(CouldNotSaveException::class);
        $syncOrderHistoryRepository->save($syncOrderHistory);
    }

    public function testSave_CreateFromSyncOrder(): void
    {
        $order = $this->getOrderFixture();
        if (!method_exists($order, 'getId')) {
            throw new \LogicException(sprintf(
                'Order object of type %s does not contain method getId()',
                $order::class,
            ));
        }

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getId());

        $syncOrderHistory = $this->syncOrderHistoryFactory->createFromSyncOrder(
            syncOrder: $syncOrder,
            action: Actions::PROCESS_START->value,
            via: 'PHPUnit',
            result: Results::NOOP->value,
            additionalInformation: ['foo' => 'bar'],
        );

        $syncOrderHistoryRepository = $this->instantiateTestObject();
        $syncOrderHistory = $syncOrderHistoryRepository->save($syncOrderHistory);

        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistory->getTimestamp()));
        $this->assertSame('process-start', $syncOrderHistory->getAction());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $this->assertSame('noop', $syncOrderHistory->getResult());
        $expectedAdditionalInformation = [
            'foo' => 'bar',
            'order_id' => $syncOrder->getOrderId(),
            'store_id' => $syncOrder->getStoreId(),
        ];
        $this->assertSame($expectedAdditionalInformation, $syncOrderHistory->getAdditionalInformation());
    }

    public function testSave_CreateFromSyncOrder_UnsavedSyncOrder(): void
    {
        $syncOrderFactory = $this->objectManager->get(SyncOrderInterfaceFactory::class);
        $syncOrder = $syncOrderFactory->create();

        $syncOrderHistory = $this->syncOrderHistoryFactory->createFromSyncOrder(
            syncOrder: $syncOrder,
            action: Actions::PROCESS_START->value,
            via: 'PHPUnit',
            result: Results::NOOP->value,
            additionalInformation: ['foo' => 'bar'],
        );

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->expectException(CouldNotSaveException::class);
        $syncOrderHistoryRepository->save($syncOrderHistory);
    }

    public function testSave_Update(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $syncOrderHistoryId = $syncOrderHistory->getEntityId();

        $syncOrderHistory->setVia('PHPUnit: Updated');
        $additionalInformation = $syncOrderHistory->getAdditionalInformation();
        $additionalInformation['updatedBy'] = __METHOD__;
        $syncOrderHistory->setAdditionalInformation($additionalInformation);

        $syncOrderHistoryRepository->save($syncOrderHistory);
        $syncOrderHistoryRepository->clearCache();

        $syncOrderHistoryReloaded = $syncOrderHistoryRepository->getById($syncOrderHistoryId);

        $this->assertSame($syncOrderHistoryId, $syncOrderHistoryReloaded->getEntityId());
        $this->assertSame($syncOrderHistory->getSyncOrderId(), $syncOrderHistoryReloaded->getSyncOrderId());
        $this->assertSame($syncOrderHistory->getTimestamp(), $syncOrderHistoryReloaded->getTimestamp());
        $this->assertSame($syncOrderHistory->getAction(), $syncOrderHistoryReloaded->getAction());
        $this->assertSame('PHPUnit: Updated', $syncOrderHistoryReloaded->getVia());
        $this->assertSame($syncOrderHistory->getResult(), $syncOrderHistoryReloaded->getResult());
        $additionalInformationReloaded = $syncOrderHistoryReloaded->getAdditionalInformation();
        $this->assertIsArray($additionalInformationReloaded);
        $this->assertArrayHasKey('updatedBy', $additionalInformationReloaded);
        $this->assertSame(__METHOD__, $additionalInformationReloaded['updatedBy']);
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testSave_Update_Invalid(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());

        $syncOrderHistory->setSyncOrderId(-1);
        $syncOrderHistory->setVia('PHPUnit: Updated');
        $additionalInformation = $syncOrderHistory->getAdditionalInformation();
        $additionalInformation['updatedBy'] = __METHOD__;
        $syncOrderHistory->setAdditionalInformation($additionalInformation);

        $this->expectException(CouldNotSaveException::class);
        $syncOrderHistoryRepository->save($syncOrderHistory);
    }

    public function testGetList_NoResults(): void
    {
        $this->searchCriteriaBuilder->addFilter(
            field: 'entity_id',
            value: 0,
            conditionType: 'lt',
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $syncOrderHistoryResult = $syncOrderHistoryRepository->getList($searchCriteria);
        $this->assertEquals(0, $syncOrderHistoryResult->getTotalCount());
        $this->assertEmpty($syncOrderHistoryResult->getItems());
        $this->assertSame($searchCriteria, $syncOrderHistoryResult->getSearchCriteria());
    }

    public function testGetList_ComplexSearchCriteria(): void
    {
        $this->loadComplexSearchCriteriaFixtures();

        /**
         * order_id IN (...)
         * AND
         * (sync_status = "queued" OR action = "queue")
         * AND
         * (
         *      timestamp >= ...
         *      OR
         *      (via LIKE "Cron%" AND "result" != "success")
         * )
         */
        $orderIdFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                field: 'order_id',
                conditionType: 'in',
                value: array_map(
                    static fn (OrderInterface $order): int => (int)$order->getEntityId(),
                    $this->orderFixtures,
                ),
            ),
        ]);
        $syncStatusFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                field: 'sync_status',
                conditionType: 'eq',
                value: 'queued',
            ),
            $this->createFilter(
                field: 'action',
                conditionType: 'eq',
                value: 'queue',
            ),
        ]);
        $andFilterGroup = $this->createFilterGroup([
            $this->createFilter(
                field: 'timestamp',
                conditionType: 'gteq',
                value: date('Y-m-d H:i:s', time() - 60),
            ),
            $this->createFilter(
                filters: [
                    $this->createFilter(
                        field: 'via',
                        conditionType: 'like',
                        value: 'Cron%',
                    ),
                    $this->createFilter(
                        field: 'result',
                        conditionType: 'neq',
                        value: 'success',
                    ),
                ],
            ),
        ]);

        $this->searchCriteriaBuilder->setPageSize(1);
        $this->searchCriteriaBuilder->setCurrentPage(1);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $searchCriteria->setFilterGroups([
            $orderIdFilterGroup,
            $syncStatusFilterGroup,
            $andFilterGroup,
        ]);

        $syncOrderHistoryRepository = $this->instantiateTestObject();
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList($searchCriteria);

        $this->assertSame(5, $syncOrderHistoryResults->getTotalCount());
        $this->assertCount(1, $syncOrderHistoryResults->getItems());
        $this->assertSame($searchCriteria, $syncOrderHistoryResults->getSearchCriteria());
    }

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

        $syncOrderHistoryRepository = $this->instantiateTestObject();
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList($searchCriteria);

        $this->assertGreaterThanOrEqual(
            count($this->orderFixtures),
            $syncOrderHistoryResults->getTotalCount(),
        );
        $this->assertEmpty($syncOrderHistoryResults->getItems());
        $this->assertSame($searchCriteria, $syncOrderHistoryResults->getSearchCriteria());
    }

    public function testClearCache(): void
    {
        $order = $this->getOrderFixture();

        $syncOrderHistoryRepository = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: (int)$order->getEntityId(),
            conditionType: 'eq',
        );
        $syncOrderHistoryResults = $syncOrderHistoryRepository->getList(
            $this->searchCriteriaBuilder->create(),
        );
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistoryFromList */
        $syncOrderHistoryFromList = current($syncOrderHistoryResults->getItems());
        $this->assertSame('queue', $syncOrderHistoryFromList->getAction());

        $syncOrderHistoryFromList->setAction('test-list');

        $syncOrderHistory = $syncOrderHistoryRepository->getById(
            $syncOrderHistoryFromList->getEntityId(),
        );
        // Items retrieved from list are _not_ cached internally
        $this->assertSame('queue', $syncOrderHistory->getAction());
        $syncOrderHistory->setAction('test');

        $syncOrderHistoryReloadedFromCache = $syncOrderHistoryRepository->getById(
            $syncOrderHistory->getEntityId(),
        );
        // Cached object updated by reference
        $this->assertSame('test', $syncOrderHistoryReloadedFromCache->getAction());

        $syncOrderHistoryRepository->clearCache();

        $syncOrderHistoryReloadedFromDb = $syncOrderHistoryRepository->getById(
            $syncOrderHistory->getEntityId(),
        );
        // Object retrieved from DB following cache clear. No save, no updated action
        $this->assertSame('queue', $syncOrderHistoryReloadedFromDb->getAction());
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

        $syncOrders = array_map(
            fn (int $orderId): SyncOrderInterface => $this->syncOrderRepository->getByOrderId($orderId),
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
            function (SyncOrderInterface $syncOrder): void {
                $this->syncOrderRepository->save($syncOrder);
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

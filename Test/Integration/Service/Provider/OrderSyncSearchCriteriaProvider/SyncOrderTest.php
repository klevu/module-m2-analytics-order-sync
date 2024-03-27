<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider\OrderSyncSearchCriteriaProvider;

use Klevu\AnalyticsApi\Model\AndFilter;
use Klevu\AnalyticsOrderSync\Exception\InvalidSearchCriteriaException;
use Klevu\AnalyticsOrderSync\Service\Provider\OrderSyncSearchCriteriaProvider;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\OrderSyncSearchCriteriaProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method OrderSyncSearchCriteriaProvider instantiateTestObject(?array $arguments = null)
 */
class SyncOrderTest extends TestCase
{
    use ObjectInstantiationTrait {
        getExpectedFqcns as trait_getExpectedFqcns;
    }
    use TestImplementsInterfaceTrait;
    use StoreTrait;

    private const PROVIDER_VIRTUAL_TYPE = 'Klevu\AnalyticsOrderSync\Service\Provider\OrderSyncSearchCriteriaProvider\SyncOrder'; // phpcs:ignore Generic.Files.LineLength.TooLong

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var OrderStatusCollectionFactory|null
     */
    private ?OrderStatusCollectionFactory $orderStatusCollectionFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = self::PROVIDER_VIRTUAL_TYPE;
        $this->interfaceFqcn = OrderSyncSearchCriteriaProviderInterface::class;

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->orderStatusCollectionFactory = $this->objectManager->get(OrderStatusCollectionFactory::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->storeFixturesPool->rollback();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetSearchCriteria_NoArguments_NoExcludedStatuses(): void
    {
        $this->createStoreFixtures(1);
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria();

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);
        $this->assertEmpty($searchCriteria->getFilterGroups());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetSearchCriteria_NoArguments_WithExcludedStatuses(): void
    {
        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $existingStores = $storeManager->getStores();

        $this->createStoreFixtures(1);
        $testStore = $this->storeFixturesPool->get('test_store_1');

        $this->setExcludedStatusesConfig(
            stores: [
                'default' => '',
                $testStore->getCode() => 'pending',
            ],
        );

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria();

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(
            1 + count($existingStores),
            $filters,
        );

        ksort($existingStores);
        usort(
            array: $filters,
            callback: static fn (Filter $filterA, Filter $filterB): int => match (true) {
                ($filterA instanceof AndFilter) && (count($filterA->getFilters()) > 0) => 1,
                ($filterB instanceof AndFilter) && (count($filterB->getFilters()) > 0) => -1,
                (
                    ('store_id' === $filterA->getField())
                    && ('store_id' === $filterB->getField())
                ) => $filterA->getValue() <=> $filterB->getValue(),
                default => 0,
            },
        );

        foreach ($existingStores as $existingStore) {
            /** @var Filter $defaultStoreFilter */
            $defaultStoreFilter = array_shift($filters);
            $this->assertSame('store_id', $defaultStoreFilter->getField());
            $this->assertSame('eq', $defaultStoreFilter->getConditionType());
            $this->assertEquals((int)$existingStore->getId(), $defaultStoreFilter->getValue());
        }

        /** @var AndFilter $testStoreFilter */
        $testStoreFilter = array_shift($filters);
        $this->assertEmpty($testStoreFilter->getField());
        $this->assertEmpty($testStoreFilter->getValue());

        $testStoreChildFilters = $testStoreFilter->getFilters();
        $this->assertCount(2, $testStoreChildFilters);

        $testStoreStoreIdFilter = array_shift($testStoreChildFilters);
        $this->assertSame('store_id', $testStoreStoreIdFilter->getField());
        $this->assertSame('eq', $testStoreStoreIdFilter->getConditionType());
        $this->assertEquals($testStore->getId(), $testStoreStoreIdFilter->getValue());

        $testStoreStatusFilter = array_shift($testStoreChildFilters);
        $this->assertSame('order_status', $testStoreStatusFilter->getField());
        $this->assertSame('in', $testStoreStatusFilter->getConditionType());
        $this->assertIsArray($testStoreStatusFilter->getValue());
        $this->assertNotEmpty($testStoreStatusFilter->getValue());
        $this->assertNotContains('pending', $testStoreStatusFilter->getValue());
    }

    public function testGetSearchCriteria_InvalidCurrentPage(): void
    {
        $provider = $this->instantiateTestObject();

        $this->expectException(InvalidSearchCriteriaException::class);
        $provider->getSearchCriteria(
            currentPage: -1,
        );
    }

    public function testGetSearchCriteria_InvalidPageSize(): void
    {
        $provider = $this->instantiateTestObject();

        $this->expectException(InvalidSearchCriteriaException::class);
        $provider->getSearchCriteria(
            pageSize: -1,
        );
    }

    public function testGetSearchCriteria_Pagination(): void
    {
        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            currentPage: 10,
            pageSize: 1,
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertSame(10, $searchCriteria->getCurrentPage());
        $this->assertSame(1, $searchCriteria->getPageSize());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetSearchCriteria_OrderIds(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            orderIds: [1, 2, 3],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        $filter = current($filters);
        $this->assertSame('order_id', $filter->getField());
        $this->assertSame('in', $filter->getConditionType());
        $this->assertSame([1, 2, 3], $filter->getValue());
    }

    public function testGetSearchCriteria_EmptyOrderIds(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            orderIds: [],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        $filter = current($filters);
        $this->assertSame('order_id', $filter->getField());
        $this->assertSame('in', $filter->getConditionType());
        $this->assertSame([-1], $filter->getValue());
    }

    public function testGetSearchCriteria_SyncStatuses(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            syncStatuses: ['queued', 'retry'],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        $filter = current($filters);
        $this->assertSame('status', $filter->getField());
        $this->assertSame('in', $filter->getConditionType());
        $this->assertSame(['queued', 'retry'], $filter->getValue());
    }

    public function testGetSearchCriteria_EmptySyncStatuses(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            syncStatuses: [],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        $filter = current($filters);
        $this->assertSame('status', $filter->getField());
        $this->assertSame('in', $filter->getConditionType());
        $this->assertSame([''], $filter->getValue());
    }

    public function testGetSearchCriteria_SyncStatuses_OnlyNotRegistered(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            syncStatuses: ['not-registered'],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        $filter = current($filters);
        $this->assertSame('status', $filter->getField());
        $this->assertSame('null', $filter->getConditionType());
        $this->assertEmpty($filter->getValue());
    }

    public function testGetSearchCriteria_SyncStatuses_WithNotRegistered(): void
    {
        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            syncStatuses: ['queued', 'retry', 'not-registered'],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(2, $filters);

        $notRegisteredFilter = array_shift($filters);
        $this->assertSame('status', $notRegisteredFilter->getField());
        $this->assertSame('null', $notRegisteredFilter->getConditionType());
        $this->assertEmpty($notRegisteredFilter->getValue());

        $registeredFilter = array_shift($filters);
        $this->assertSame('status', $registeredFilter->getField());
        $this->assertSame('in', $registeredFilter->getConditionType());
        $this->assertSame(['queued', 'retry'], $registeredFilter->getValue());
    }

    public function testGetSearchCriteria_StoreIds_NoExcludedStatuses(): void
    {
        $this->createStoreFixtures(2);
        $testStore1 = $this->storeFixturesPool->get('test_store_1');

        $this->setExcludedStatusesConfig();

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            storeIds: [
                1,
                (int)$testStore1->getId(),
            ],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        /** @var Filter $filter */
        $filter = current($filters);
        $this->assertSame('store_id', $filter->getField());
        $this->assertSame('in', $filter->getConditionType());
        $this->assertIsArray($filter->getValue());
        $this->assertCount(2, $filter->getValue());
        $this->assertContains(1, $filter->getValue());
        $this->assertContains($testStore1->getId(), $filter->getValue());
    }

    public function testGetSearchCriteria_StoreIds_WithExcludedStatuses(): void
    {
        $this->createStoreFixtures(2);
        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');

        $this->setExcludedStatusesConfig(
            global: 'fraud',
            stores: [
                'default' => '',
                $testStore1->getCode() => 'pending,holded',
                $testStore2->getCode() => 'processing',
            ],
        );

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            storeIds: [
                1,
                (int)$testStore1->getId(),
            ],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(2, $filters);

        /** @var Filter $defaultStoreFilter */
        $defaultStoreFilter = array_shift($filters);
        $this->assertSame('store_id', $defaultStoreFilter->getField());
        $this->assertSame('eq', $defaultStoreFilter->getConditionType());
        $this->assertEquals(1, $defaultStoreFilter->getValue());

        /** @var AndFilter $testStoreFilter */
        $testStoreFilter = array_shift($filters);
        $this->assertEmpty($testStoreFilter->getField());
        $this->assertEmpty($testStoreFilter->getValue());

        $testStoreChildFilters = $testStoreFilter->getFilters();
        $this->assertCount(2, $testStoreChildFilters);

        $testStoreStoreIdFilter = array_shift($testStoreChildFilters);
        $this->assertSame('store_id', $testStoreStoreIdFilter->getField());
        $this->assertSame('eq', $testStoreStoreIdFilter->getConditionType());
        $this->assertEquals($testStore1->getId(), $testStoreStoreIdFilter->getValue());

        $testStoreStatusFilter = array_shift($testStoreChildFilters);
        $this->assertSame('order_status', $testStoreStatusFilter->getField());
        $this->assertSame('in', $testStoreStatusFilter->getConditionType());
        $this->assertIsArray($testStoreStatusFilter->getValue());
        $this->assertNotEmpty($testStoreStatusFilter->getValue());
        $this->assertNotContains('pending', $testStoreStatusFilter->getValue());
        $this->assertNotContains('holded', $testStoreStatusFilter->getValue());
    }

    public function testGetSearchCriteria_StoreIds_AllStatusesExcluded(): void
    {
        $this->createStoreFixtures(2);
        $testStore1 = $this->storeFixturesPool->get('test_store_1');
        $testStore2 = $this->storeFixturesPool->get('test_store_2');

        $this->setExcludedStatusesConfig(
            global: 'fraud',
            stores: [
                'default' => implode(',', $this->getAllOrderStatuses()),
                $testStore1->getCode() => 'pending,holded',
                $testStore2->getCode() => 'processing',
            ],
        );

        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            storeIds: [
                1,
                (int)$testStore2->getId(),
            ],
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertDefaultPagination($searchCriteria);

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(1, $filterGroups);

        $filterGroup = current($filterGroups);
        $filters = $filterGroup->getFilters();
        $this->assertCount(1, $filters);

        /** @var AndFilter $testStoreFilter */
        $testStoreFilter = current($filters);
        $this->assertEmpty($testStoreFilter->getField());
        $this->assertEmpty($testStoreFilter->getValue());

        $testStoreChildFilters = $testStoreFilter->getFilters();
        $this->assertCount(2, $testStoreChildFilters);

        $testStoreStoreIdFilter = array_shift($testStoreChildFilters);
        $this->assertSame('store_id', $testStoreStoreIdFilter->getField());
        $this->assertSame('eq', $testStoreStoreIdFilter->getConditionType());
        $this->assertEquals($testStore2->getId(), $testStoreStoreIdFilter->getValue());

        $testStoreStatusFilter = array_shift($testStoreChildFilters);
        $this->assertSame('order_status', $testStoreStatusFilter->getField());
        $this->assertSame('in', $testStoreStatusFilter->getConditionType());
        $this->assertIsArray($testStoreStatusFilter->getValue());
        $this->assertNotEmpty($testStoreStatusFilter->getValue());
        $this->assertNotContains('processing', $testStoreStatusFilter->getValue());
    }

    public function testGetSearchCriteria_CombinationFilters(): void
    {
        $provider = $this->instantiateTestObject();

        $searchCriteria = $provider->getSearchCriteria(
            orderIds: [1, 2, 3],
            syncStatuses: ['queued', 'not-registered'],
            storeIds: [42, 999],
            currentPage: 10,
            pageSize: 15,
        );

        $this->assertDefaultSortOrder($searchCriteria);
        $this->assertSame(10, $searchCriteria->getCurrentPage());
        $this->assertSame(15, $searchCriteria->getPageSize());

        $filterGroups = $searchCriteria->getFilterGroups();
        $this->assertCount(3, $filterGroups);

        $orderIdsFilterGroup = array_shift($filterGroups);
        /** @var Filter[] $orderIdsFilters */
        $orderIdsFilters = $orderIdsFilterGroup->getFilters();
        $this->assertCount(1, $orderIdsFilters);
        $orderIdsFilter = current($orderIdsFilters);
        $this->assertSame('order_id', $orderIdsFilter->getField());
        $this->assertSame('in', $orderIdsFilter->getConditionType());
        $this->assertSame([1, 2, 3], $orderIdsFilter->getValue());

        $syncStatusesFilterGroup = array_shift($filterGroups);
        /** @var Filter[] $syncStatusesFilters */
        $syncStatusesFilters = $syncStatusesFilterGroup->getFilters();
        $this->assertCount(2, $syncStatusesFilters);
        $notRegisteredFilter = array_shift($syncStatusesFilters);
        $this->assertSame('status', $notRegisteredFilter->getField());
        $this->assertSame('null', $notRegisteredFilter->getConditionType());
        $this->assertEmpty($notRegisteredFilter->getValue());
        $statusesFilter = array_shift($syncStatusesFilters);
        $this->assertSame('status', $statusesFilter->getField());
        $this->assertSame('in', $statusesFilter->getConditionType());
        $this->assertSame(['queued'], $statusesFilter->getValue());

        $storeIdsFilterGroup = array_shift($filterGroups);
        /** @var Filter[] $storeIdsFilters */
        $storeIdsFilters = $storeIdsFilterGroup->getFilters();
        $this->assertCount(1, $storeIdsFilters);
        $storeIdsFilter = current($storeIdsFilters);
        $this->assertSame('store_id', $storeIdsFilter->getField());
        $this->assertSame('in', $storeIdsFilter->getConditionType());
        $this->assertSame([42, 999], $storeIdsFilter->getValue());
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return void
     */
    private function assertDefaultSortOrder(SearchCriteriaInterface $searchCriteria): void
    {
        $sortOrders = $searchCriteria->getSortOrders();
        $this->assertCount(1, $sortOrders, json_encode($sortOrders));

        /** @var SortOrder $sortOrder */
        $sortOrder = current($sortOrders);
        $this->assertSame('order_id', $sortOrder->getField());
        $this->assertSame('ASC', $sortOrder->getDirection());
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return void
     */
    private function assertDefaultPagination(SearchCriteriaInterface $searchCriteria): void
    {
        $this->assertSame(1, $searchCriteria->getCurrentPage());
        $this->assertSame(250, $searchCriteria->getPageSize());
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function createStoreFixtures(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->createStore([
                'code' => 'klevu_analytics_test_store_' . $i,
                'key' => 'test_store_' . $i,
            ]);
        }
    }

    /**
     * @param string $global
     * @param mixed[] $stores
     * @return void
     */
    private function setExcludedStatusesConfig(
        string $global = '',
        array $stores = ['default' => ''],
    ): void {
        ConfigFixture::setGlobal(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: $global,
        );
        foreach ($stores as $storeCode => $value) {
            ConfigFixture::setForStore(
                path: 'klevu/analytics_order_sync/exclude_status_from_sync',
                value: $value,
                storeCode: $storeCode,
            );
        }
    }

    /**
     * @return string[]
     */
    private function getAllOrderStatuses(): array
    {
        $collection = $this->orderStatusCollectionFactory->create();

        return array_keys($collection->toOptionHash());
    }

    /**
     * @return string[]
     */
    private function getExpectedFqcns(): array // @phpstan-ignore-line Used in trait
    {
        $expectedFqcns = $this->trait_getExpectedFqcns();
        $expectedFqcns[] = OrderSyncSearchCriteriaProvider::class;

        return $expectedFqcns;
    }
}

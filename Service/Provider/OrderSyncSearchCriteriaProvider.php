<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsApi\Model\AndFilterBuilder;
use Klevu\AnalyticsOrderSync\Exception\InvalidSearchCriteriaException;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\OrderSyncSearchCriteriaProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\PermittedOrderStatusProviderInterface;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderSyncSearchCriteriaProvider implements OrderSyncSearchCriteriaProviderInterface
{
    /**
     * @var StoreManagerInterface
     */
    private readonly StoreManagerInterface $storeManager;
    /**
     * @var SortOrderBuilder
     */
    private readonly SortOrderBuilder $sortOrderBuilder;
    /**
     * @var AndFilterBuilder
     */
    private readonly AndFilterBuilder $filterBuilder;
    /**
     * @var FilterGroupBuilder
     */
    private readonly FilterGroupBuilder $filterGroupBuilder;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var OrderStatusCollectionFactory
     */
    private readonly OrderStatusCollectionFactory $orderStatusCollectionFactory;
    /**
     * @var PermittedOrderStatusProviderInterface
     */
    private readonly PermittedOrderStatusProviderInterface $permittedOrderStatusProvider;
    /**
     * @var string
     */
    private readonly string $orderIdFieldName;
    /**
     * @var string
     */
    private readonly string $orderStatusFieldName;
    /**
     * @var string
     */
    private readonly string $syncStatusFieldName;
    /**
     * @var string
     */
    private readonly string $storeIdFieldName;
    /**
     * @var string[]|null
     */
    private ?array $allOrderStatuses = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param SortOrderBuilder $sortOrderBuilder
     * @param AndFilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderStatusCollectionFactory $orderStatusCollectionFactory
     * @param PermittedOrderStatusProviderInterface $permittedOrderStatusProvider
     * @param string $orderIdFieldName
     * @param string $orderStatusFieldName
     * @param string $syncStatusFieldName
     * @param string $storeIdFieldName
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        SortOrderBuilder $sortOrderBuilder,
        AndFilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderStatusCollectionFactory $orderStatusCollectionFactory,
        PermittedOrderStatusProviderInterface $permittedOrderStatusProvider,
        string $orderIdFieldName,
        string $orderStatusFieldName,
        string $syncStatusFieldName,
        string $storeIdFieldName,
    ) {
        $this->storeManager = $storeManager;
        $this->sortOrderBuilder = $sortOrderBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderStatusCollectionFactory = $orderStatusCollectionFactory;
        $this->permittedOrderStatusProvider = $permittedOrderStatusProvider;
        $this->orderIdFieldName = $orderIdFieldName;
        $this->orderStatusFieldName = $orderStatusFieldName;
        $this->syncStatusFieldName = $syncStatusFieldName;
        $this->storeIdFieldName = $storeIdFieldName;
    }

    /**
     * @param int[]|null $orderIds
     * @param string[]|null $syncStatuses
     * @param int[]|null $storeIds
     * @param int $currentPage
     * @param int $pageSize
     * @return SearchCriteriaInterface
     * @throws InvalidSearchCriteriaException
     */
    public function getSearchCriteria(
        ?array $orderIds = null,
        ?array $syncStatuses = null,
        ?array $storeIds = null,
        int $currentPage = 1,
        int $pageSize = 250,
    ): SearchCriteriaInterface {
        $this->searchCriteriaBuilder->create(); // Clear any partially generated criteria

        $filterGroups = array_filter([
            $this->getFilterGroupForOrderIds($orderIds),
            $this->getFilterGroupForSyncStatuses($syncStatuses),
            $this->getFilterGroupForStoreIds($storeIds),
        ]);

        if ($filterGroups) {
            $this->searchCriteriaBuilder->setFilterGroups($filterGroups);
        }

        $this->sortOrderBuilder->setField($this->orderIdFieldName);
        $this->sortOrderBuilder->setAscendingDirection();
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->create(),
        );

        if ($currentPage <= 0) {
            throw new InvalidSearchCriteriaException(
                phrase: __('Current Page must be greater than 0'),
            );
        }
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);
        if ($pageSize <= 0) {
            throw new InvalidSearchCriteriaException(
                phrase: __('Page Size must be greater than 0'),
            );
        }
        $this->searchCriteriaBuilder->setPageSize($pageSize);

        return $this->searchCriteriaBuilder->create();
    }

    /**
     * @param int[]|null $orderIds
     * @return FilterGroup|null
     */
    private function getFilterGroupForOrderIds(
        ?array $orderIds,
    ): ?FilterGroup {
        if (null === $orderIds) {
            return null;
        }

        if (!$orderIds) {
            $orderIds = [-1]; // Add a dummy value to prevent invalid queries
        }

        $this->filterBuilder->setField($this->orderIdFieldName);
        $this->filterBuilder->setConditionType('in');
        $this->filterBuilder->setValue(
            array_map('intval', $orderIds),
        );

        $this->filterGroupBuilder->addFilter(
            $this->filterBuilder->create(),
        );

        $filterGroup = $this->filterGroupBuilder->create();
        if (!($filterGroup instanceof FilterGroup)) {
            throw new \LogicException(sprintf(
                'Filter Group Builder of type %s expected to return type %s; actually returned %s',
                $this->filterGroupBuilder::class,
                FilterGroup::class,
                get_debug_type($filterGroup),
            ));
        }

        return $filterGroup;
    }

    /**
     * @param string[]|null $syncStatuses
     * @return FilterGroup|null
     */
    private function getFilterGroupForSyncStatuses(
        ?array $syncStatuses,
    ): ?FilterGroup {
        if (null === $syncStatuses) {
            return null;
        }

        if (!$syncStatuses) {
            $syncStatuses = [''];
        }

        $notRegisteredIndex = array_search(
            needle: Statuses::NOT_REGISTERED->value,
            haystack: $syncStatuses,
            strict: true,
        );
        if (false !== $notRegisteredIndex) {
            unset($syncStatuses[$notRegisteredIndex]);

            $this->filterBuilder->setField($this->syncStatusFieldName);
            $this->filterBuilder->setConditionType('null');

            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->create(),
            );
        }

        if ($syncStatuses) {
            $this->filterBuilder->setField($this->syncStatusFieldName);
            $this->filterBuilder->setConditionType('in');
            $this->filterBuilder->setValue($syncStatuses);

            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->create(),
            );
        }

        $filterGroup = $this->filterGroupBuilder->create();
        if (!($filterGroup instanceof FilterGroup)) {
            throw new \LogicException(sprintf(
                'Filter Group Builder of type %s expected to return type %s; actually returned %s',
                $this->filterGroupBuilder::class,
                FilterGroup::class,
                get_debug_type($filterGroup),
            ));
        }

        return $filterGroup;
    }

    /**
     * @param int[]|null $storeIds
     * @return FilterGroup|null
     */
    private function getFilterGroupForStoreIds(
        ?array $storeIds,
    ): ?FilterGroup {
        $allOrderStatuses = $this->getAllOrderStatuses();
        $allStoreIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->storeManager->getStores(withDefault: false),
        );
        $storeToStatuses = [];
        foreach ($allStoreIds as $storeId) {
            if (null !== $storeIds && !in_array($storeId, $storeIds, true)) {
                // This store should not be included in sync
                continue;
            }

            $permittedOrderStatusesForStore = $this->permittedOrderStatusProvider->getForStore($storeId);
            // No statuses excluded for store
            if (!array_diff($allOrderStatuses, $permittedOrderStatusesForStore)) {
                $storeToStatuses[$storeId] = null;
            } else {
                $storeToStatuses[$storeId] = $permittedOrderStatusesForStore;
            }
        }

        $storeIdsWithExclusions = array_keys(
            array_filter(
                $storeToStatuses,
                static fn (?array $statuses): bool => null !== $statuses,
            ),
        );
        if (null === $storeIds && !$storeIdsWithExclusions) {
            // Store filter not applicable - requested storeIds is null and no stores have exclusions
            return null;
        }

        if (!$storeIdsWithExclusions) {
            // Stores have been specified, but all statuses are included
            if (!$storeIds) {
                $storeIds = [-1]; // Add a dummy value to prevent invalid queries
            }

            $this->filterBuilder->setField($this->storeIdFieldName);
            $this->filterBuilder->setConditionType('in');
            $this->filterBuilder->setValue(
                array_map('intval', $storeIds),
            );

            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->create(),
            );

            $filterGroup = $this->filterGroupBuilder->create();
            if (!($filterGroup instanceof FilterGroup)) {
                throw new \LogicException(sprintf(
                    'Filter Group Builder of type %s expected to return type %s; actually returned %s',
                    $this->filterGroupBuilder::class,
                    FilterGroup::class,
                    get_debug_type($filterGroup),
                ));
            }

            return $filterGroup;
        }

        foreach ($storeToStatuses as $storeId => $permittedStatuses) {
            switch (true) {
                // Store has no status exclusions
                case null === $permittedStatuses:
                    $this->filterBuilder->setField($this->storeIdFieldName);
                    $this->filterBuilder->setConditionType('eq');
                    $this->filterBuilder->setValue((int)$storeId);

                    // (... OR (storeId = x))
                    $this->filterGroupBuilder->addFilter(
                        $this->filterBuilder->create(),
                    );
                    break;

                case $permittedStatuses:
                    $this->filterBuilder->setField($this->storeIdFieldName);
                    $this->filterBuilder->setConditionType('eq');
                    $this->filterBuilder->setValue((int)$storeId);
                    $storeIdFilter = $this->filterBuilder->create();

                    $this->filterBuilder->setField($this->orderStatusFieldName);
                    $this->filterBuilder->setConditionType('in');
                    $this->filterBuilder->setValue($permittedStatuses);
                    $statusFilter = $this->filterBuilder->create();

                    $this->filterBuilder->setFilters([
                        $storeIdFilter,
                        $statusFilter,
                    ]);

                    $this->filterGroupBuilder->addFilter(
                        $this->filterBuilder->create(),
                    );
                    break;

                default:
                    // Store has status exclusions for every status.
                    // Don't add a store filter at all
                    break;
            }
        }

        $filterGroup = $this->filterGroupBuilder->create();
        if (!($filterGroup instanceof FilterGroup)) {
            throw new \LogicException(sprintf(
                'Filter Group Builder of type %s expected to return type %s; actually returned %s',
                $this->filterGroupBuilder::class,
                FilterGroup::class,
                get_debug_type($filterGroup),
            ));
        }

        return $filterGroup;
    }

    /**
     * @return string[]
     */
    private function getAllOrderStatuses(): array
    {
        if (null === $this->allOrderStatuses) {
            /** @var OrderStatusCollection $orderStatusCollection */
            $orderStatusCollection = $this->orderStatusCollectionFactory->create();
            $this->allOrderStatuses = array_keys($orderStatusCollection->toOptionHash());
        }

        return $this->allOrderStatuses;
    }
}

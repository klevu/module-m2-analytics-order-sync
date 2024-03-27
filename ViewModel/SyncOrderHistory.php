<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\ViewModel;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class SyncOrderHistory implements ArgumentInterface
{
    use OptionSourceToHashTrait;

    /**
     * @var SortOrderBuilder
     */
    private readonly SortOrderBuilder $sortOrderBuilder;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var SyncOrderHistoryRepositoryInterface
     */
    private readonly SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $actionOptions;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $resultOptions;

    /**
     * @param SortOrderBuilder $sortOrderBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository
     * @param OptionSourceInterface $actionOptions
     * @param OptionSourceInterface $resultOptions
     */
    public function __construct(
        SortOrderBuilder $sortOrderBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository,
        OptionSourceInterface $actionOptions,
        OptionSourceInterface $resultOptions,
    ) {
        $this->sortOrderBuilder = $sortOrderBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderHistoryRepository = $syncOrderHistoryRepository;
        $this->actionOptions = $actionOptions;
        $this->resultOptions = $resultOptions;
    }

    /**
     * @param int $syncOrderId
     * @return SyncOrderHistoryInterface[]
     */
    public function getSyncOrderHistoryRecordsForSyncOrderId(
        int $syncOrderId,
    ): array {
        // SyncOrderHistoryRepository implements internal caching, so we don't need to here
        $syncOrderHistoryRecords = [];
        if ($syncOrderId) {
            $searchCriteria = $this->getSearchCriteria(
                syncOrderId: $syncOrderId,
            );
            $searchResult = $this->syncOrderHistoryRepository->getList(
                searchCriteria: $searchCriteria,
            );

            /** @var SyncOrderHistoryInterface[] $syncOrderHistoryRecords */
            $syncOrderHistoryRecords = $searchResult->getItems();
        }

        return $syncOrderHistoryRecords;
    }

    /**
     * @param string $action
     * @return Phrase
     */
    public function getActionForDisplay(string $action): Phrase
    {
        $options = $this->getHashForOptionSource(
            $this->actionOptions,
        );

        return $options[$action] ?? __($action);
    }

    /**
     * @param string $result
     * @return Phrase
     */
    public function getResultForDisplay(string $result): Phrase
    {
        $options = $this->getHashForOptionSource(
            $this->resultOptions,
        );

        return $options[$result] ?? __($result);
    }

    /**
     * @param mixed[]|null $additionalInformation
     * @return string
     */
    public function getAdditionalInformationForDisplay(?array $additionalInformation): string
    {
        if (!array_filter($additionalInformation ?? [])) {
            return '';
        }

        // Not using serializer as it doesn't support pretty printing
        return json_encode($additionalInformation, JSON_PRETTY_PRINT);
    }

    /**
     * @param int $syncOrderId
     * @return SearchCriteriaInterface
     */
    private function getSearchCriteria(
        int $syncOrderId,
    ): SearchCriteriaInterface {
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID,
            value: $syncOrderId,
            conditionType: 'eq',
        );

        $this->sortOrderBuilder->setField(SyncOrderHistoryModel::FIELD_TIMESTAMP);
        $this->sortOrderBuilder->setDescendingDirection();
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->create(),
        );

        $this->sortOrderBuilder->setField(SyncOrderHistoryModel::FIELD_ENTITY_ID);
        $this->sortOrderBuilder->setDescendingDirection();
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->create(),
        );

        return $this->searchCriteriaBuilder->create();
    }
}

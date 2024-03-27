<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\RemoveSyncedOrderHistoryResultInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\RemoveSyncedOrderHistoryResultInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\RemoveSyncedOrderHistoryServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

class RemoveSyncedOrderHistory implements RemoveSyncedOrderHistoryServiceInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var SyncOrderHistoryRepositoryInterface
     */
    private readonly SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository;
    /**
     * @var RemoveSyncedOrderHistoryResultInterfaceFactory
     */
    private readonly RemoveSyncedOrderHistoryResultInterfaceFactory $removeSyncedOrderHistoryResultFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository
     * @param RemoveSyncedOrderHistoryResultInterfaceFactory $removeSyncedOrderHistoryResult
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository,
        RemoveSyncedOrderHistoryResultInterfaceFactory $removeSyncedOrderHistoryResult,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderHistoryRepository = $syncOrderHistoryRepository;
        $this->removeSyncedOrderHistoryResultFactory = $removeSyncedOrderHistoryResult;
    }

    /**
     * @param string $syncStatus
     * @param int[]|null $storeIds
     * @param int|null $thresholdInDays
     * @return RemoveSyncedOrderHistoryResultInterface
     * @throws \Exception
     */
    public function execute(
        string $syncStatus,
        ?array $storeIds = null,
        ?int $thresholdInDays = null,
    ): RemoveSyncedOrderHistoryResultInterface {
        /** @var RemoveSyncedOrderHistoryResultInterface $result */
        $result = $this->removeSyncedOrderHistoryResultFactory->create();

        try {
            $syncStatus = $this->getSyncStatusFromArgument($syncStatus);
            $storeIds = $this->getStoreIdsFromArgument($storeIds);
            $thresholdInDays = $this->getThresholdInDaysFromArgument(
                thresholdInDays: $thresholdInDays,
                syncStatus: $syncStatus,
            );
        } catch (\InvalidArgumentException $exception) {
            $result->setIsSuccess(false);
            $result->addMessage(
                message: $exception->getMessage(),
            );

            return $result;
        }

        $searchCriteria = $this->getSearchCriteria(
            syncStatus: $syncStatus,
            storeIds: $storeIds,
            thresholdInDays: $thresholdInDays,
        );
        $historyItemsToDelete = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $searchCriteria,
        );

        $successCount = 0;
        $errorCount = 0;
        /** @var SyncOrderHistoryInterface $historyItem */
        foreach ($historyItemsToDelete->getItems() as $historyItem) {
            try {
                $this->syncOrderHistoryRepository->delete($historyItem);
                $successCount++;
            } catch (LocalizedException $exception) {
                $result->addMessage(sprintf(
                    'Failed to remove sync history item #%s: %s',
                    $historyItem->getEntityId(),
                    $exception->getMessage(),
                ));
                $errorCount++;
            }
        }

        $result->setSuccessCount($successCount);
        $result->setErrorCount($errorCount);
        $result->setIsSuccess($successCount && !$errorCount);

        return $result;
    }

    /**
     * @param string $syncStatus
     * @return string
     * @throws \InvalidArgumentException
     */
    private function getSyncStatusFromArgument(
        string $syncStatus,
    ): string {
        $syncStatus = trim($syncStatus);

        $validSyncStatuses = [
            Statuses::SYNCED->value,
            Statuses::ERROR->value,
        ];
        if (!in_array($syncStatus, $validSyncStatuses, true)) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'syncStatus argument must be one of %s; received "%s"',
                    implode(', ', $validSyncStatuses),
                    $syncStatus,
                ),
            );
        }

        return $syncStatus;
    }

    /**
     * @param mixed[]|null $storeIds
     * @return int[]|null
     * @throws \InvalidArgumentException
     */
    private function getStoreIdsFromArgument(
        ?array $storeIds,
    ): ?array {
        if (null === $storeIds) {
            return null;
        }

        $filteredStoreIds = array_filter(
            $storeIds,
            static fn (mixed $storeId): bool => (
                is_int($storeId) || (is_string($storeId) && ctype_digit($storeId))
            ),
        );

        if ($storeIds !== $filteredStoreIds) {
            throw new \InvalidArgumentException(
                message: 'StoreIds argument must be null or array of integers',
            );
        }

        return array_map('intval', $storeIds);
    }

    /**
     * @param int|null $thresholdInDays
     * @param string $syncStatus
     * @return int
     * @throws \InvalidArgumentException
     */
    private function getThresholdInDaysFromArgument(
        ?int $thresholdInDays,
        string $syncStatus,
    ): int {
        if (null === $thresholdInDays) {
            $xmlPath = match ($syncStatus) {
                Statuses::SYNCED->value => Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
                Statuses::ERROR->value => Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
                default => throw new \InvalidArgumentException(
                    sprintf('Unexpected syncStatus "%s"', $syncStatus),
                ),
            };

            $thresholdInDays = (int)$this->scopeConfig->getValue(
                $xmlPath,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            );
        }

        if ($thresholdInDays <= 0) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'ThresholdInDays value must be greater than 0; Received "%s"',
                    $thresholdInDays,
                ),
            );
        }

        return $thresholdInDays;
    }

    /**
     * @param string $syncStatus
     * @param int[]|null $storeIds
     * @param int $thresholdInDays
     * @return SearchCriteriaInterface
     * @throws \Exception
     */
    private function getSearchCriteria(
        string $syncStatus,
        ?array $storeIds,
        int $thresholdInDays,
    ): SearchCriteriaInterface {
        // Sync Status
        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_status',
            value: $syncStatus,
            conditionType: 'eq',
        );

        // Stores
        if (null !== $storeIds) {
            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrder::FIELD_STORE_ID,
                value: $storeIds,
                conditionType: 'in',
            );
        }

        // Time threshold
        $now = new \DateTime('now');
        $diff = new \DateInterval(
            sprintf('P%dD', $thresholdInDays),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_TIMESTAMP,
            value: $now->sub($diff)->format('Y-m-d H:i:s'),
            conditionType: 'lteq',
        );

        return $this->searchCriteriaBuilder->create();
    }
}

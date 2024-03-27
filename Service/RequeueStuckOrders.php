<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSyncApi\Api\Data\RequeueStuckOrdersResultInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\RequeueStuckOrdersResultInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\RequeueStuckOrdersServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

class RequeueStuckOrders implements RequeueStuckOrdersServiceInterface
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
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;
    /**
     * @var RequeueStuckOrdersResultInterfaceFactory
     */
    private readonly RequeueStuckOrdersResultInterfaceFactory $requeueStuckOrdersResultFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     * @param RequeueStuckOrdersResultInterfaceFactory $requeueStuckOrdersResultFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderRepositoryInterface $syncOrderRepository,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
        RequeueStuckOrdersResultInterfaceFactory $requeueStuckOrdersResultFactory,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderRepository = $syncOrderRepository;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
        $this->requeueStuckOrdersResultFactory = $requeueStuckOrdersResultFactory;
    }

    /**
     * @param int[]|null $storeIds
     * @param int|null $thresholdInMinutes
     * @param string $via
     * @return RequeueStuckOrdersResultInterface
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function execute(
        ?array $storeIds = null,
        ?int $thresholdInMinutes = null,
        string $via = 'Requeue Stuck Orders service',
    ): RequeueStuckOrdersResultInterface {
        /** @var RequeueStuckOrdersResultInterface $result */
        $result = $this->requeueStuckOrdersResultFactory->create();

        try {
            $storeIds = $this->getStoreIdsFromArgument($storeIds);
            $thresholdInMinutes = $this->getThresholdInMinutesFromArgument($thresholdInMinutes);
        } catch (\InvalidArgumentException $exception) {
            $result->setIsSuccess(false);
            $result->addMessage(
                message: $exception->getMessage(),
            );

            return $result;
        }

        $searchCriteria = $this->getSearchCriteria(
            storeIds: $storeIds,
            thresholdInMinutes: $thresholdInMinutes,
        );
        $stuckSyncOrders = $this->syncOrderRepository->getList(
            searchCriteria: $searchCriteria,
        );

        $successCount = 0;
        $errorCount = 0;
        /** @var SyncOrderInterface $syncOrder */
        foreach ($stuckSyncOrders->getItems() as $syncOrder) {
            try {
                $this->queueOrderForSyncAction->execute(
                    orderId: $syncOrder->getOrderId(),
                    via: $via,
                    additionalInformation: [
                        'reason' => (string)__(
                            'Order found processing after %1 minute threshold',
                            $thresholdInMinutes,
                        ),
                    ],
                );
                $successCount++;
            } catch (LocalizedException $exception) {
                $result->addMessage(sprintf(
                    'Failed to requeue stuck order #%s: %s',
                    $syncOrder->getOrderId(),
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
     * @param int|null $thresholdInMinutes
     * @return int
     * @throws \InvalidArgumentException
     */
    private function getThresholdInMinutesFromArgument(
        ?int $thresholdInMinutes,
    ): int {
        if (null === $thresholdInMinutes) {
            $thresholdInMinutes = (int)$this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            );
        }

        if ($thresholdInMinutes <= 0) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'ThresholdInMinutes value must be greater than 0; Received "%s"',
                    $thresholdInMinutes,
                ),
            );
        }

        return $thresholdInMinutes;
    }

    /**
     * @param int[]|null $storeIds
     * @param int $thresholdInMinutes
     * @return SearchCriteriaInterface
     * @throws \Exception
     */
    private function getSearchCriteria(
        ?array $storeIds,
        int $thresholdInMinutes,
    ): SearchCriteriaInterface {
        // Status
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrder::FIELD_STATUS,
            value: Statuses::PROCESSING->value,
        );

        // Stores
        if (null !== $storeIds) {
            // Note, there is an intentional differentiation made between
            //  null, where the callee has omitted the store IDs argument and
            //  [], where the callee is providing a list of stores which happens to be empty
            // Where the param is omitted, all stores should be considered
            //  while an empty array should return no results.
            // [-1] is used instead of an empty array to prevent a SQL error
            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrder::FIELD_STORE_ID,
                value: $storeIds ?: [-1],
                conditionType: 'in',
            );
        }

        // Time threshold
        $now = new \DateTime('now');
        $diff = new \DateInterval(
            sprintf('PT%dM', $thresholdInMinutes),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: 'last_history_timestamp',
            value: $now->sub($diff)->format('Y-m-d H:i:s'),
            conditionType: 'lteq',
        );

        return $this->searchCriteriaBuilder->create();
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Action;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\UpdateSyncOrderHistoryForOrderIdActionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class UpdateSyncOrderHistoryForOrderId implements UpdateSyncOrderHistoryForOrderIdActionInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var SyncOrderHistoryRepositoryInterface
     */
    private readonly SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository;
    /**
     * @var bool
     */
    private readonly bool $throwOnException;

    /**
     * @param LoggerInterface $logger
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository
     * @param bool $throwOnException
     */
    public function __construct(
        LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository,
        bool $throwOnException = false,
    ) {
        $this->logger = $logger;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderHistoryRepository = $syncOrderHistoryRepository;
        $this->throwOnException = $throwOnException;
    }
    
    /**
     * @param int $orderId
     * @param Actions|null $action
     * @param string|null $via
     * @param string|null $result
     * @param string[]|null $additionalInformation
     *
     * @return void
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(
        int $orderId,
        ?Actions $action = null,
        ?string $via = null,
        ?string $result = null,
        ?array $additionalInformation = null,
    ): void {
        $syncOrderHistoryItems = $this->getSyncOrderHistoryItemsForOrderId($orderId);
        
        foreach ($syncOrderHistoryItems as $syncOrderHistoryItem) {
            if (null !== $action) {
                $syncOrderHistoryItem->setAction(
                    action: $action->value,
                );
            }
            if (null !== $via) {
                $syncOrderHistoryItem->setVia(
                    via: $via,
                );
            }
            if (null !== $result) {
                $syncOrderHistoryItem->setResult(
                    result: $result,
                );
            }
            if (null !== $additionalInformation) {
                $syncOrderHistoryItem->setAdditionalInformation(
                    additionalInformation: $additionalInformation,
                );
            }
            
            try {
                $this->syncOrderHistoryRepository->save(
                    syncOrderHistory: $syncOrderHistoryItem,
                );
            } catch (\Exception $exception) {
                $this->logger->warning(
                    message: 'Could not update syncOrderHistory action to {newAction} for item #{syncOrderHistoryId}',
                    context: [
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                        'method' => __METHOD__,
                        'newAction' => Actions::MIGRATE->value,
                        'syncOrderId' => $syncOrderHistoryItem->getSyncOrderId(),
                        'syncOrderHistoryId' => $syncOrderHistoryItem->getEntityId(),
                    ],
                );

                if ($this->throwOnException) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param int $orderId
     *
     * @return SyncOrderHistoryInterface[]
     */
    private function getSyncOrderHistoryItemsForOrderId(int $orderId): array
    {
        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: $orderId,
        );
        $this->searchCriteriaBuilder->addFilter(
            field: 'action',
            value: Actions::MIGRATE->value,
            conditionType: 'neq',
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        
        return $syncOrderHistoryResult->getItems();
    }
}

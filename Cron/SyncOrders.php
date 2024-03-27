<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Cron;

use Klevu\AnalyticsApi\Api\ProcessEventsServiceInterface;
use Klevu\AnalyticsApi\Model\Source\ProcessEventsResultStatuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\OrderSyncSearchCriteriaProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class SyncOrders
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SyncEnabledStoresProviderInterface
     */
    private readonly SyncEnabledStoresProviderInterface $syncEnabledStoresProvider;
    /**
     * @var OrderSyncSearchCriteriaProviderInterface
     */
    private readonly OrderSyncSearchCriteriaProviderInterface $orderSyncSearchCriteriaProvider;
    /**
     * @var ProcessEventsServiceInterface
     */
    private readonly ProcessEventsServiceInterface $processEventsService;

    /**
     * @param LoggerInterface $logger
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param OrderSyncSearchCriteriaProviderInterface $orderSyncSearchCriteriaProvider
     * @param ProcessEventsServiceInterface $processEventsService
     */
    public function __construct(
        LoggerInterface $logger,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        OrderSyncSearchCriteriaProviderInterface $orderSyncSearchCriteriaProvider,
        ProcessEventsServiceInterface $processEventsService,
    ) {
        $this->logger = $logger;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->orderSyncSearchCriteriaProvider = $orderSyncSearchCriteriaProvider;
        $this->processEventsService = $processEventsService;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->logger->info('Starting order sync cron');

        $storeIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->syncEnabledStoresProvider->get(),
        );
        if (!$storeIds) {
            $this->logger->info('Order sync cron complete: no configured stores found');

            return;
        }

        $currentPage = 1;
        do {
            $searchCriteria = $this->orderSyncSearchCriteriaProvider->getSearchCriteria(
                syncStatuses: [
                    Statuses::QUEUED->value,
                    Statuses::RETRY->value,
                ],
                storeIds: $storeIds,
                currentPage: $currentPage,
            );

            $this->logger->debug(
                message: 'Syncing orders page {currentPage}',
                context: [
                    'currentPage' => $currentPage,
                ],
            );
            $result = $this->processEventsService->execute(
                searchCriteria: $searchCriteria,
                via: (string)__('Cron: %1', 'klevu_analytics_sync_orders'),
            );
            $this->logger->debug(
                message: 'Order sync complete for page {currentPage}',
                context: [
                    'currentPage' => $currentPage,
                    'result' => $result,
                ],
            );

            $currentPage++;
            break;
        } while (ProcessEventsResultStatuses::NOOP !== $result->getStatus());

        $this->logger->info('Order sync cron complete');
    }
}

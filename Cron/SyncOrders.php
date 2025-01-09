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
use Klevu\Pipelines\Exception\ExtractionExceptionInterface;
use Klevu\Pipelines\Exception\TransformationExceptionInterface;
use Klevu\Pipelines\Exception\ValidationExceptionInterface;
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
     * @var ProcessEventsServiceInterface|null
     */
    private readonly ?ProcessEventsServiceInterface $processEventsServiceForRetry;
    /**
     * @var int
     */
    private readonly int $batchSize;

    /**
     * @param LoggerInterface $logger
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param OrderSyncSearchCriteriaProviderInterface $orderSyncSearchCriteriaProvider
     * @param ProcessEventsServiceInterface $processEventsService
     * @param ProcessEventsServiceInterface|null $processEventsServiceForRetry
     * @param int $batchSize
     */
    public function __construct(
        LoggerInterface $logger,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        OrderSyncSearchCriteriaProviderInterface $orderSyncSearchCriteriaProvider,
        ProcessEventsServiceInterface $processEventsService,
        ?ProcessEventsServiceInterface $processEventsServiceForRetry = null, // nullable for backward compatibility
        int $batchSize = 250,
    ) {
        $this->logger = $logger;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->orderSyncSearchCriteriaProvider = $orderSyncSearchCriteriaProvider;
        $this->processEventsService = $processEventsService;
        $this->processEventsServiceForRetry = $processEventsServiceForRetry;
        $this->batchSize = $batchSize;
    }

    /**
     * @return void
     * @throws ExtractionExceptionInterface
     * @throws TransformationExceptionInterface
     * @throws ValidationExceptionInterface
     */
    public function execute(): void
    {
        $startTime = microtime(true);
        $storeIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->syncEnabledStoresProvider->get(),
        );

        $this->logger->info(
            message: 'Starting order sync via cron',
            context: [
                'method' => __METHOD__,
                'storeIds' => $storeIds,
            ],
        );

        if (!$storeIds) {
            $this->logger->info(
                message: 'Order sync cron complete: no configured stores found',
                context: [
                    'method' => __METHOD__,
                    'storeIds' => $storeIds,
                    'timeTaken' => number_format(
                        num: microtime(true) - $startTime,
                        decimals: 2,
                    ),
                ],
            );

            return;
        }

        $this->executeSync(
            status: Statuses::RETRY,
            storeIds: $storeIds,
        );
        $this->executeSync(
            status: Statuses::QUEUED,
            storeIds: $storeIds,
        );

        $this->logger->info(
            message: 'Order sync cron complete in {timeTaken} seconds',
            context: [
                'method' => __METHOD__,
                'storeIds' => $storeIds,
                'timeTaken' => number_format(
                    num: microtime(true) - $startTime,
                    decimals: 2,
                ),
            ],
        );
    }

    /**
     * @param Statuses $status
     * @param int[] $storeIds
     *
     * @return void
     * @throws ExtractionExceptionInterface
     * @throws TransformationExceptionInterface
     * @throws ValidationExceptionInterface
     */
    private function executeSync(
        Statuses $status,
        array $storeIds,
    ): void {
        $startTime = microtime(true);
        $this->logger->debug(
            message: 'Starting order sync for {status} via cron',
            context: [
                'method' => __METHOD__,
                'status' => $status->value,
                'storeIds' => $storeIds,
            ],
        );

        $processEventsService = match ($status) {
            Statuses::RETRY => $this->processEventsServiceForRetry,
            Statuses::QUEUED => $this->processEventsService,
            default => null,
        };
        if (!$processEventsService) {
            $this->logger->error(
                message: 'No configured process events service found for order sync status {status}',
                context: [
                    'method' => __METHOD__,
                    'status' => $status->value,
                    'storeIds' => $storeIds,
                ],
            );

            return;
        }

        $currentPage = 1;
        do {
            $searchCriteria = $this->orderSyncSearchCriteriaProvider->getSearchCriteria(
                syncStatuses: [
                    $status->value,
                ],
                storeIds: $storeIds,
                currentPage: $currentPage,
                pageSize: $this->batchSize,
            );

            $this->logger->debug(
                message: 'Syncing orders ({status}) for page {currentPage}',
                context: [
                    'method' => __METHOD__,
                    'status' => $status->value,
                    'storeIds' => $storeIds,
                    'currentPage' => $currentPage,
                ],
            );
            $result = $processEventsService->execute(
                searchCriteria: $searchCriteria,
                via: (string)__('Cron: %1', 'klevu_analytics_sync_orders'),
            );
            $this->logger->debug(
                message: 'Order sync ({status}) complete for page {currentPage}',
                context: [
                    'method' => __METHOD__,
                    'status' => $status->value,
                    'storeIds' => $storeIds,
                    'currentPage' => $currentPage,
                    'result' => $result,
                ],
            );

            $currentPage++;
        } while (ProcessEventsResultStatuses::NOOP !== $result->getStatus());

        $this->logger->debug(
            message: 'Order sync for {status} via cron complete in {timeTaken} seconds. {totalPages} pages processed',
            context: [
                'method' => __METHOD__,
                'status' => $status->value,
                'storeIds' => $storeIds,
                'timeTaken' => number_format(
                    num: microtime(true) - $startTime,
                    decimals: 2,
                ),
                'totalPages' => $currentPage - 1,
            ],
        );
    }
}

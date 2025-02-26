<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MigrateLegacyOrderSyncRecordsInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\UpdateSyncOrderHistoryForOrderIdActionInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\LegacyDataProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncStatusForLegacyOrderItemsProviderInterface;
use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Psr\Log\LoggerInterface;

class MigrateLegacyOrderSyncRecords implements MigrateLegacyOrderSyncRecordsInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var StoresProviderInterface
     */
    private readonly StoresProviderInterface $storesProvider;
    /**
     * @var LegacyDataProviderInterface
     */
    private readonly LegacyDataProviderInterface $legacyDataProvider;
    /**
     * @var SyncStatusForLegacyOrderItemsProviderInterface
     */
    private readonly SyncStatusForLegacyOrderItemsProviderInterface $syncStatusForLegacyOrderItemsProvider;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;
    /**
     * @var MarkOrderAsProcessedActionInterface
     */
    private readonly MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction;
    /**
     * @var UpdateSyncOrderHistoryForOrderIdActionInterface
     */
    private readonly UpdateSyncOrderHistoryForOrderIdActionInterface $updateSyncOrderHistoryForOrderIdAction;

    /**
     * @param LoggerInterface $logger
     * @param StoresProviderInterface $storesProvider
     * @param LegacyDataProviderInterface $legacyDataProvider
     * @param SyncStatusForLegacyOrderItemsProviderInterface $syncStatusForLegacyOrderItemsProvider
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     * @param MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction
     * @param UpdateSyncOrderHistoryForOrderIdActionInterface $updateSyncOrderHistoryForOrderIdAction
     */
    public function __construct(
        LoggerInterface $logger,
        StoresProviderInterface $storesProvider,
        LegacyDataProviderInterface $legacyDataProvider,
        SyncStatusForLegacyOrderItemsProviderInterface $syncStatusForLegacyOrderItemsProvider,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
        MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction,
        UpdateSyncOrderHistoryForOrderIdActionInterface $updateSyncOrderHistoryForOrderIdAction,
    ) {
        $this->logger = $logger;
        $this->storesProvider = $storesProvider;
        $this->legacyDataProvider = $legacyDataProvider;
        $this->syncStatusForLegacyOrderItemsProvider = $syncStatusForLegacyOrderItemsProvider;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
        $this->markOrderAsProcessedAction = $markOrderAsProcessedAction;
        $this->updateSyncOrderHistoryForOrderIdAction = $updateSyncOrderHistoryForOrderIdAction;
    }

    /**
     * @param int $storeId
     *
     * @return void
     */
    public function executeForStoreId(int $storeId): void
    {
        $startTime = microtime(true);
        $this->logger->info(
            message: 'Starting legacy order sync migration for store ID: {storeId}',
            context: [
                'method' => __METHOD__,
                'storeId' => $storeId,
            ],
        );

        $legacyData = $this->legacyDataProvider->getForStoreId($storeId);
        $groupedLegacyData = $this->groupLegacyDataByOrderId($legacyData);

        array_walk(
            array: $groupedLegacyData,
            callback: [$this, 'processOrderItems'],
            arg: $storeId,
        );

        $endTime = microtime(true);
        $this->logger->info(
            message: 'Legacy order sync migration for {ordersCount} orders in store ID: {storeId} '
                . 'completed in {timeTaken} seconds',
            context: [
                'method' => __METHOD__,
                'storeId' => $storeId,
                'ordersCount' => count($groupedLegacyData),
                'timeTaken' => number_format($endTime - $startTime, 2),
            ],
        );
    }

    /**
     * @return void
     */
    public function executeForAllStores(): void
    {
        $storesByApiKey = $this->storesProvider->getAllIntegratedStores();
        foreach ($storesByApiKey as $stores) {
            foreach ($stores as $store) {
                $this->executeForStoreId(
                    storeId: (int)$store->getId(),
                );
            }
        }
    }

    /**
     * @param iterable<mixed[]> $legacyData
     *
     * @return array<string, int|numeric-string>
     */
    private function groupLegacyDataByOrderId(iterable $legacyData): array
    {
        $groupedData = [];
        foreach ($legacyData as $row) {
            if (null === ($row['order_id'] ?? null)) {
                continue;
            }

            $orderId = (int)$row['order_id'];

            $groupedData[$orderId] ??= [];
            $groupedData[$orderId][] = [
                'order_item_id' => $row['order_item_id'] ?? 0,
                'send' => $row['send'] ?? 0,
            ];
        }

        return $groupedData;
    }

    /**
     * @param array<string, int|numeric-string> $orderItems
     * @param int $orderId
     * @param int $storeId
     *
     * @return void
     */
    private function processOrderItems(
        array $orderItems,
        int $orderId,
        int $storeId,
    ): void {
        $newStatus = $this->syncStatusForLegacyOrderItemsProvider->get($orderItems);

        if ($newStatus->canInitiateSync() && Statuses::PARTIAL !== $newStatus) {
            $result = $this->queueOrderForSyncAction->execute(
                orderId: $orderId,
                via: 'Database Migration',
                additionalInformation: [],
            );
        } else {
            $result = $this->markOrderAsProcessedAction->execute(
                orderId: $orderId,
                resultStatus: $newStatus->value,
                via: 'Database Migration',
                additionalInformation: [],
            );
        }

        if (!$result->isSuccess()) {
            $this->logger->error(
                message: 'Failed to migrate legacy order sync record #{orderId} for store ID: {storeId}',
                context: [
                    'method' => __METHOD__,
                    'orderId' => $orderId,
                    'storeId' => $storeId,
                    'expected_status' => $newStatus->canInitiateSync()
                        ? 'queued'
                        : 'processed',
                    'messages' => $result->getMessages(),
                ],
            );
        }

        $this->updateSyncOrderHistoryForOrderIdAction->execute(
            orderId: $orderId,
            action: Actions::MIGRATE,
        );
    }
}

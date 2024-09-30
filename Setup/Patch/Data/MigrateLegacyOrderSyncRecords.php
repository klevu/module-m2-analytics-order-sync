<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Setup\Patch\Data;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class MigrateLegacyOrderSyncRecords implements DataPatchInterface
{
    private const LEGACY_DB_TABLE_NAME = 'klevu_order_sync';

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ResourceConnection
     */
    private readonly ResourceConnection $resourceConnection;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;
    /**
     * @var MarkOrderAsProcessedActionInterface
     */
    private readonly MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var SyncOrderHistoryRepositoryInterface
     */
    private readonly SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository;

    /**
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     * @param MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
        MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository,
    ) {
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
        $this->markOrderAsProcessedAction = $markOrderAsProcessedAction;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderHistoryRepository = $syncOrderHistoryRepository;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return $this
     */
    public function apply(): self
    {
        $legacyDataToMigrate = $this->getLegacyDataToMigrate();

        $recordsWithoutOrderId = array_filter(
            $legacyDataToMigrate,
            static fn (array $record): bool => !($record['order_id'] ?? null),
        );
        foreach ($recordsWithoutOrderId as $recordWithoutOrderId) {
            $this->logger->error(
                message: 'Cannot migrate order item #{orderItemId} for analytics sync: associated order does not exist',
                context: [
                    'method' => __METHOD__,
                    'orderItemId' => $recordWithoutOrderId['order_item_id'] ?? '',
                    'record' => $recordWithoutOrderId,
                ],
            );
        }

        $uniqueOrderIds = array_unique(array_column(
            array: $legacyDataToMigrate,
            column_key: 'order_id',
        ));
        $uniqueOrderIds = array_filter($uniqueOrderIds);
        if (!$uniqueOrderIds) {
            return $this;
        }

        // Iterate and action
        foreach ($uniqueOrderIds as $orderId) {
            $orderItems = array_filter(
                $legacyDataToMigrate,
                static fn (array $row): bool => $row['order_id'] === $orderId,
            );
            $newStatus = $this->getStatusForOrderItems($orderItems);

            if ($newStatus->canInitiateSync() && Statuses::PARTIAL !== $newStatus) {
                $result = $this->queueOrderForSyncAction->execute(
                    orderId: (int)$orderId,
                    via: 'Database Migration',
                    additionalInformation: [],
                );
            } else {
                $result = $this->markOrderAsProcessedAction->execute(
                    orderId: (int)$orderId,
                    resultStatus: $newStatus->value,
                    via: 'Database Migration',
                    additionalInformation: [],
                );
            }

            if (!$result->isSuccess()) {
                $this->logger->error(
                    message: 'Failed to migrate legacy order sync record #{orderId}',
                    context: [
                        'method' => __METHOD__,
                        'orderId' => $orderId,
                        'expected_status' => $newStatus->canInitiateSync()
                            ? 'queued'
                            : 'processed',
                        'messages' => $result->getMessages(),
                    ],
                );
            }

            $this->updateSyncOrderHistoryForOrderId((int)$orderId);
        }

        return $this;
    }

    /**
     * @return mixed[]
     */
    private function getLegacyDataToMigrate(): array
    {
        $legacyTableName = $this->resourceConnection->getTableName(self::LEGACY_DB_TABLE_NAME);

        $connection = $this->resourceConnection->getConnection();
        if (!$connection->isTableExists($legacyTableName)) {
            return [];
        }

        $select = $connection->select();
        $select->from(
            name: [
                'order_sync' => $legacyTableName,
            ],
            cols: [
                'order_item_id',
                'send',
            ],
        );
        $select->joinLeft(
            name: [
                'order_item' => $this->resourceConnection->getTableName('sales_order_item'),
            ],
            cond: 'order_sync.order_item_id = order_item.item_id',
            cols: [
                'order_id',
            ],
        );

        return $connection->fetchAssoc($select);
    }

    /**
     * @param mixed[] $orderItems
     * @return Statuses
     */
    private function getStatusForOrderItems(array $orderItems): Statuses
    {
        $orderItemStatuses = array_unique(
            array_column(
                array: $orderItems,
                column_key: 'send',
            ),
        );

        return match (true) {
            !$orderItemStatuses => Statuses::QUEUED,
            count($orderItemStatuses) > 1 && in_array('0', $orderItemStatuses, true) => Statuses::PARTIAL,
            in_array('0', $orderItemStatuses, true) => Statuses::QUEUED,
            in_array('2', $orderItemStatuses, true) => Statuses::ERROR,
            in_array('1', $orderItemStatuses, true) => Statuses::SYNCED,
            default => Statuses::NOT_REGISTERED,
        };
    }

    /**
     * @param int $orderId
     * @return void
     */
    private function updateSyncOrderHistoryForOrderId(int $orderId): void
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

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        foreach ($syncOrderHistoryResult->getItems() as $syncOrderHistory) {
            $syncOrderHistory->setAction(Actions::MIGRATE->value);
            try {
                $this->syncOrderHistoryRepository->save($syncOrderHistory);
            } catch (\Exception $exception) {
                $this->logger->warning(
                    message: 'Could not update syncOrderHistory action to {newAction} for item #{syncOrderHistoryId}',
                    context: [
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                        'method' => __METHOD__,
                        'newAction' => Actions::MIGRATE->value,
                        'syncOrderId' => $syncOrderHistory->getSyncOrderId(),
                        'syncOrderHistoryId' => $syncOrderHistory->getEntityId(),
                    ],
                );
            }
        }
    }
}

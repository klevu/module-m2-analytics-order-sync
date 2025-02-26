<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSyncApi\Service\Provider\LegacyDataProviderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;

class LegacyDataProvider implements LegacyDataProviderInterface
{
    private const LEGACY_DB_TABLE_NAME = 'klevu_order_sync';
    private const DEFAULT_BATCH_SIZE = 1000;

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ResourceConnection
     */
    private readonly ResourceConnection $resourceConnection;
    /**
     * @var int
     */
    private readonly int $batchSize;

    /**
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     * @param int|null $batchSize
     */
    public function __construct(
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        ?int $batchSize = null,
    ) {
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->batchSize = $batchSize ?? self::DEFAULT_BATCH_SIZE;
    }

    /**
     * @param int $storeId
     *
     * @return \Generator<array<string, int>>
     */
    public function getForStoreId(int $storeId): \Generator
    {
        $legacyTableName = $this->resourceConnection->getTableName(
            modelEntity: self::LEGACY_DB_TABLE_NAME,
        );
        $connection = $this->resourceConnection->getConnection();
        if (!$connection->isTableExists($legacyTableName)) {
            $this->logger->debug(
                message: 'Cannot retrieve legacy order data for store #{storeId}: '
                    . 'Legacy order sync table {legacyTableName} does not exist',
                context: [
                    'method' => __METHOD__,
                    'legacyTableName' => $legacyTableName,
                    'storeId' => $storeId,
                ],
            );

            return;
        }

        $lastOrderItemId = null;
        do {
            $select = $this->getSelect(
                storeId: $storeId,
                lastOrderItemId: $lastOrderItemId,
            );
            $data = $connection->fetchAssoc($select);

            foreach ($data as $row) {
                if (
                    null === ($row['order_item_id'] ?? null)
                    || null === ($row['order_id'] ?? null)
                ) {
                    $this->logger->warning(
                        message: 'Skipping invalid legacy order sync record',
                        context: [
                            'method' => __METHOD__,
                            'legacyDataRow' => $row,
                        ],
                    );

                    continue;
                }

                $lastOrderItemId = (int)$row['order_item_id'];

                yield array_map(
                    callback: 'intval',
                    array: $row,
                );
            }
        } while (!empty($data));
    }

    /**
     * @param int $storeId
     * @param int|null $lastOrderItemId
     *
     * @return Select
     */
    private function getSelect(
        int $storeId,
        ?int $lastOrderItemId,
    ): Select {
        $legacyTableName = $this->resourceConnection->getTableName(
            modelEntity: self::LEGACY_DB_TABLE_NAME,
        );
        $connection = $this->resourceConnection->getConnection();

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
        
        $select->where(
            cond: 'order_item.store_id = ?',
            value: $storeId,
        );

        if (null !== $lastOrderItemId) {
            $select->where(
                cond: 'order_sync.order_item_id > ?',
                value: $lastOrderItemId,
            );
        }

        $select->order(
            'order_sync.order_item_id ASC',
        );
        $select->limit(
            count: $this->batchSize,
        );

        return $select;
    }
}

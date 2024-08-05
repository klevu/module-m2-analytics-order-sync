<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder\Collection as SyncOrderCollection;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder\CollectionFactory as SyncOrderCollectionFactory;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\DuplicateOrderIpsProviderInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\ExpressionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class DuplicateOrderIpsProvider implements DuplicateOrderIpsProviderInterface
{
    private const SALES_ORDER_ALIAS = 'order';

    /**
     * @var ExpressionFactory
     */
    private readonly ExpressionFactory $expressionFactory;
    /**
     * @var SyncOrderCollectionFactory
     */
    private readonly SyncOrderCollectionFactory $syncOrderCollectionFactory;
    /**
     * @var OrderCollectionFactory
     */
    private readonly OrderCollectionFactory $orderCollectionFactory;
    /**
     * @var int
     */
    private readonly int $minimumOrdersToReportThreshold;

    /**
     * @param ExpressionFactory $expressionFactory
     * @param SyncOrderCollectionFactory $syncOrderCollectionFactory
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param int $minimumOrdersToReportThreshold
     */
    public function __construct(
        ExpressionFactory $expressionFactory,
        SyncOrderCollectionFactory $syncOrderCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
        int $minimumOrdersToReportThreshold = 5,
    ) {
        $this->expressionFactory = $expressionFactory;
        $this->syncOrderCollectionFactory = $syncOrderCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->minimumOrdersToReportThreshold = $minimumOrdersToReportThreshold;
    }

    /**
     * @param int|null $storeId
     * @param string $ipField
     * @param int $periodDays
     * @param float $threshold
     *
     * @return array<string, int>
     */
    public function get(
        ?int $storeId,
        string $ipField,
        int $periodDays,
        float $threshold,
    ): array {
        $fromDate = date(
            format: 'Y-m-d 00:00:00',
            timestamp: time() - ($periodDays * 86400),
        );

        $totalOrdersCount = $this->getTotalOrdersCount(
            storeId: $storeId,
            fromDate: $fromDate,
        );
        $minimumOrdersToReport = (int)ceil($totalOrdersCount * $threshold);

        return $this->getIpsWithOrderCount(
            storeId: $storeId,
            ipField: $ipField,
            fromDate: $fromDate,
            minimumOrdersToReport: $minimumOrdersToReport,
        );
    }

    /**
     * @param int|null $storeId
     * @param string $fromDate
     *
     * @return int
     */
    private function getTotalOrdersCount(
        ?int $storeId,
        string $fromDate,
    ): int {
        $syncOrderCollection = $this->createSyncOrderCollection(
            storeId: $storeId,
            fromDate: $fromDate,
        );

        return $syncOrderCollection->getSize();
    }

    /**
     * @param int|null $storeId
     * @param string $ipField
     * @param string $fromDate
     * @param int $minimumOrdersToReport
     *
     * @return array<string, int>
     */
    private function getIpsWithOrderCount(
        ?int $storeId,
        string $ipField,
        string $fromDate,
        int $minimumOrdersToReport,
    ): array {
        $syncOrderCollection = $this->createSyncOrderCollection(
            storeId: $storeId,
            fromDate: $fromDate,
        );
        
        $select = $syncOrderCollection->getSelect();
        $orderCountExpression = $this->expressionFactory->create([
            'expression' => sprintf(
                'COUNT(%s.%s)',
                self::SALES_ORDER_ALIAS,
                Order::ENTITY_ID,
            ),
        ]);
        $select->reset(Select::COLUMNS);
        $select->columns(
            cols: [
                'ip' => self::SALES_ORDER_ALIAS . '.' . $ipField,
                'order_count' => $orderCountExpression,
            ],
        );
        $select->group(self::SALES_ORDER_ALIAS . '.' . $ipField);
        $select->having(
            cond: 'order_count >= ?',
            value: $minimumOrdersToReport,
        );
        $select->order('order_count DESC');
        $select->limit(1000);

        $data = $syncOrderCollection->getData();

        $return = array_combine(
            keys: array_column($data, 'ip'),
            values: array_map(
                callback: 'intval',
                array: array_column($data, 'order_count'),
            ),
        );

        return array_filter(
            array: $return,
            callback: fn (int $count): bool => ($count >= $this->minimumOrdersToReportThreshold),
        );
    }

    /**
     * @param int|null $storeId
     * @param string $fromDate
     *
     * @return SyncOrderCollection
     */
    private function createSyncOrderCollection(
        ?int $storeId,
        string $fromDate,
    ): SyncOrderCollection {
        $orderCollection = $this->orderCollectionFactory->create();
        $syncOrderCollection = $this->syncOrderCollectionFactory->create();
        if (null !== $storeId) {
            $syncOrderCollection->addFieldToFilter(
                field: SyncOrder::FIELD_STORE_ID,
                condition: ['eq' => $storeId],
            );
        }

        $select = $syncOrderCollection->getSelect();
        $select->join(
            name: ['order' => $orderCollection->getMainTable()],
            cond: sprintf(
                'main_table.%s = %s.%s',
                SyncOrder::FIELD_ORDER_ID,
                self::SALES_ORDER_ALIAS,
                Order::ENTITY_ID,
            ),
            cols: [
                Order::CREATED_AT,
            ],
        );
        $select->where(
            cond: sprintf(
                '%s.%s >= ?',
                self::SALES_ORDER_ALIAS,
                Order::CREATED_AT,
            ),
            value: $fromDate,
        );

        return $syncOrderCollection;
    }
}

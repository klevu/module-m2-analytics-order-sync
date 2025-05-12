<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder;

use Klevu\Analytics\Model\ResourceModel\Collection\ExtendedAddFieldToFilterTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory as SyncOrderHistoryResource;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\ExpressionFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class Collection extends AbstractCollection
{
    use ExtendedAddFieldToFilterTrait {
        addFieldToFilter as extendedAddFieldToFilter;
    }

    /**
     * @var ExpressionFactory
     */
    private readonly ExpressionFactory $expressionFactory;

    /**
     * @param ExpressionFactory $expressionFactory
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
    public function __construct(
        ExpressionFactory $expressionFactory,
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null,
    ) {
        parent::__construct(
            entityFactory: $entityFactory,
            logger: $logger,
            fetchStrategy: $fetchStrategy,
            eventManager: $eventManager,
            connection: $connection,
            resource: $resource,
        );

        $this->expressionFactory = $expressionFactory;
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(
            model: SyncOrderModel::class,
            resourceModel: SyncOrderResource::class,
        );
    }

    /**
     * @return mixed[]
     */
    protected function _getMapper(): array
    {
        if (!isset($this->_map)) { // @phpstan-ignore-line Magento's docblock and code do not align
            $resource = $this->getResource();
            $salesOrderTableName = $resource->getTable(
                tableName: 'sales_order',
            );
            $syncOrderHistoryTableName = $resource->getTable(
                tableName: SyncOrderHistoryResource::TABLE,
            );

            $this->_map = [];
            $this->_map['table_aliases'] = [
                $salesOrderTableName => 'sales_order',
                $syncOrderHistoryTableName => SyncOrderHistoryResource::TABLE,
            ];
            $this->_map['fields'] = [
                SyncOrderModel::FIELD_ENTITY_ID => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_ENTITY_ID,
                ),
                SyncOrderModel::FIELD_ORDER_ID => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_ORDER_ID,
                ),
                SyncOrderModel::FIELD_STORE_ID => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_STORE_ID,
                ),
                SyncOrderModel::FIELD_STATUS => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_STATUS,
                ),
                SyncOrderModel::FIELD_ATTEMPTS => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_ATTEMPTS,
                ),
                'sync_status' => sprintf(
                    'main_table.%s',
                    SyncOrderModel::FIELD_STATUS,
                ),
                'order_status' => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$salesOrderTableName],
                    OrderInterface::STATUS,
                ),
                'last_history_timestamp' => $this->expressionFactory->create([
                    'expression' => sprintf(
                        '(SELECT MAX(%s) '
                        . 'FROM %s '
                        . 'WHERE %s.%s = main_table.%s)',
                        SyncOrderHistoryModel::FIELD_TIMESTAMP,
                        $syncOrderHistoryTableName,
                        $syncOrderHistoryTableName,
                        SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID,
                        SyncOrderModel::FIELD_ENTITY_ID,
                    ),
                ]),
            ];
        }

        return parent::_getMapper();
    }

    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    /**
     * @param string|string[]|string[][] $field
     * @param string|mixed[]|null $condition
     * @return $this
     * @throws \Zend_Db_Select_Exception
     */
    public function addFieldToFilter(
        $field,
        $condition = null,
    ): self {
        $this->extendedAddFieldToFilter(
            field: $field,
            condition: $condition,
        );

        $this->joinAssociatedTables(
            $this->extractTablesForFilter($field),
        );

        return $this;
    }
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint

    /**
     * @param string[] $tables
     * @return void
     * @throws \Zend_Db_Select_Exception
     */
    private function joinAssociatedTables(array $tables): void
    {
        if (
            in_array('sales_order', $tables, true)
            && !$this->isTableJoined('sales_order')
        ) {
            $this->joinSalesOrderTable();
        }

        if (
            in_array(SyncOrderHistoryResource::TABLE, $tables, true)
            && !$this->isTableJoined(SyncOrderHistoryResource::TABLE)
        ) {
            $this->joinKlevuSyncOrderHistoryTable();
        }
    }

    /**
     * @return void
     */
    private function joinSalesOrderTable(): void
    {
        $mapper = $this->_getMapper() ?: [];
        $salesOrderTableName = array_search(
            needle: 'sales_order',
            haystack: $mapper['table_aliases'] ?? [],
            strict: true,
        ) ?: 'sales_order';

        $select = $this->getSelect();
        $select->joinInner(
            name: [
                'sales_order' => $salesOrderTableName,
            ],
            cond: sprintf(
                '%s.%s = main_table.%s',
                'sales_order',
                OrderInterface::ENTITY_ID,
                SyncOrderModel::FIELD_ORDER_ID,
            ),
            cols: [],
        );
    }

    /**
     * @return void
     */
    private function joinKlevuSyncOrderHistoryTable(): void
    {
        $mapper = $this->_getMapper() ?: [];
        $klevuSyncOrderHistoryTableName = array_search(
            needle: SyncOrderHistoryResource::TABLE,
            haystack: $mapper['table_aliases'] ?? [],
            strict: true,
        ) ?: SyncOrderHistoryResource::TABLE;

        $select = $this->getSelect();
        $select->joinLeft(
            name: [
                SyncOrderHistoryResource::TABLE => $klevuSyncOrderHistoryTableName,
            ],
            cond: sprintf(
                '%s.%s = main_table.%s',
                SyncOrderHistoryResource::TABLE,
                SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID,
                SyncOrderModel::FIELD_ENTITY_ID,
            ),
            cols: [],
        );
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Model\ResourceModel\Order;

use Klevu\Analytics\Model\ResourceModel\Collection\ExtendedAddFieldToFilterTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as BaseOrderCollection;

class Collection extends BaseOrderCollection
{
    use ExtendedAddFieldToFilterTrait {
        addFieldToFilter as extendedAddFieldToFilter;
    }

    public const FIELD_ORDER_ID = 'order_id';
    public const FIELD_ORDER_STATUS = 'order_status';
    public const FIELD_STORE_ID = 'store_id';
    public const FIELD_SYNC_ORDER_ID = 'sync_order_id';
    public const FIELD_SYNC_STATUS = 'sync_status';
    public const FIELD_SYNC_ATTEMPTS = 'sync_attempts';

    /**
     * @var bool
     */
    private bool $mainTableAliasesAdded = false;

    /**
     * @return mixed[][]
     */
    protected function _getMapper() //phpcs:ignore SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    {
        $this->_map = parent::_getMapper() ?: [];

        $resource = $this->getResource();
        $syncOrderTableName = $resource->getTable(
            tableName: SyncOrderResource::TABLE,
        );

        $this->_map['table_aliases'] = array_merge(
            $this->_map['table_aliases'] ?? [],
            [
                $syncOrderTableName => SyncOrderResource::TABLE,
            ],
        );
        $this->_map['fields'] = array_merge(
            $this->_map['fields'] ?? [],
            [
                static::FIELD_ORDER_ID => sprintf(
                    'main_table.%s',
                    $this->getIdFieldName(),
                ),
                static::FIELD_ORDER_STATUS => sprintf(
                    'main_table.%s',
                    OrderInterface::STATUS,
                ),
                static::FIELD_STORE_ID => sprintf(
                    'main_table.%s',
                    OrderInterface::STORE_ID,
                ),
                static::FIELD_SYNC_ORDER_ID => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$syncOrderTableName],
                    SyncOrderModel::FIELD_ENTITY_ID,
                ),
                static::FIELD_SYNC_STATUS => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$syncOrderTableName],
                    SyncOrderModel::FIELD_STATUS,
                ),
                static::FIELD_SYNC_ATTEMPTS => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$syncOrderTableName],
                    SyncOrderModel::FIELD_ATTEMPTS,
                ),
            ],
        );

        return $this->_map;
    }

    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    /**
     * @param string|string[]|string[][] $field
     * @param string|mixed[]|null $condition
     * @return $this
     */
    public function addFieldToFilter(
        $field,
        $condition = null,
    ): self {
        $this->extendedAddFieldToFilter(
            field: $field,
            condition: $condition,
        );

        $this->addMainTableColumnAliases();
        $this->joinAssociatedTables(
            $this->extractTablesForFilter($field),
        );

        return $this;
    }
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint

    /**
     * @return void
     */
    private function addMainTableColumnAliases(): void
    {
        if ($this->mainTableAliasesAdded) {
            return;
        }

        $columns = [];

        $mapper = $this->_getMapper() ?: [];
        $aliasesToMap = [
            static::FIELD_ORDER_ID,
            static::FIELD_ORDER_STATUS,
            static::FIELD_STORE_ID,
        ];
        foreach ($aliasesToMap as $alias) {
            if ($alias !== ($mapper['fields'][$alias] ?? $alias)) {
                $columns[$alias] = $mapper['fields'][$alias];
            }
        }

        if (!$columns) {
            return;
        }

        $select = $this->getSelect();
        $select->columns($columns);
    }

    /**
     * @param string[] $tables
     * @return void
     * @throws \Zend_Db_Select_Exception
     */
    private function joinAssociatedTables(array $tables): void
    {
        if (
            in_array(SyncOrderResource::TABLE, $tables, true)
            && !$this->isTableJoined(SyncOrderResource::TABLE)
        ) {
            $this->joinKlevuSyncOrderTable();
        }
    }

    /**
     * @return void
     */
    private function joinKlevuSyncOrderTable(): void
    {
        $mapper = $this->_getMapper() ?: [];
        $klevuSyncOrderTableName = array_search(
            needle: SyncOrderResource::TABLE,
            haystack: $mapper['table_aliases'] ?? [],
            strict: true,
        ) ?: SyncOrderResource::TABLE;

        $select = $this->getSelect();
        $select->joinLeft(
            name: [
                SyncOrderResource::TABLE => $klevuSyncOrderTableName,
            ],
            cond: sprintf(
                '%s.%s = main_table.%s',
                SyncOrderResource::TABLE,
                SyncOrderModel::FIELD_ORDER_ID,
                $this->getIdFieldName(),
            ),
            cols: [
                static::FIELD_SYNC_ORDER_ID => $mapper['fields'][static::FIELD_SYNC_ORDER_ID],
                static::FIELD_SYNC_STATUS => $mapper['fields'][static::FIELD_SYNC_STATUS],
                static::FIELD_SYNC_ATTEMPTS => $mapper['fields'][static::FIELD_SYNC_ATTEMPTS],
            ],
        );
    }
}

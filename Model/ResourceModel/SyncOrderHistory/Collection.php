<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory;

use Klevu\Analytics\Model\ResourceModel\Collection\ExtendedAddFieldToFilterTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory as SyncOrderHistoryResource;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    use ExtendedAddFieldToFilterTrait {
        addFieldToFilter as extendedAddFieldToFilter;
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(
            model: SyncOrderHistoryModel::class,
            resourceModel: SyncOrderHistoryResource::class,
        );
    }

    /**
     * @return $this
     */
    protected function _afterLoad(): self
    {
        parent::_afterLoad();

        foreach ($this as $item) {
            $this->_resource->unserializeFields($item);
        }

        return $this;
    }

    /**
     * @return mixed[]
     */
    protected function _getMapper(): array
    {
        if (!isset($this->_map)) { // @phpstan-ignore-line Magento's docblock and code do not align
            $resource = $this->getResource();
            $syncOrderTableName = $resource->getTable(
                tableName: SyncOrderResource::TABLE,
            );

            $this->_map = [];
            $this->_map['table_aliases'] = [
                $syncOrderTableName => SyncOrderResource::TABLE,
            ];
            $this->_map['fields'] = [
                SyncOrderHistoryModel::FIELD_ENTITY_ID => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_ENTITY_ID,
                ),
                SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID,
                ),
                SyncOrderHistoryModel::FIELD_TIMESTAMP => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_TIMESTAMP,
                ),
                SyncOrderHistoryModel::FIELD_ACTION => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_ACTION,
                ),
                SyncOrderHistoryModel::FIELD_VIA => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_VIA,
                ),
                SyncOrderHistoryModel::FIELD_RESULT => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_RESULT,
                ),
                SyncOrderHistoryModel::FIELD_ADDITIONAL_INFORMATION => sprintf(
                    'main_table.%s',
                    SyncOrderHistoryModel::FIELD_ADDITIONAL_INFORMATION,
                ),
                'sync_status' => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$syncOrderTableName],
                    SyncOrderModel::FIELD_STATUS,
                ),
                'order_id' => sprintf(
                    '%s.%s',
                    $this->_map['table_aliases'][$syncOrderTableName],
                    SyncOrderModel::FIELD_ORDER_ID,
                ),
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
        $select->joinInner(
            name: [
                SyncOrderResource::TABLE => $klevuSyncOrderTableName,
            ],
            cond: sprintf(
                '%s.%s = main_table.%s',
                SyncOrderResource::TABLE,
                SyncOrderModel::FIELD_ENTITY_ID,
                SyncOrderHistoryModel::FIELD_SYNC_ORDER_ID,
            ),
            cols: [],
        );
    }
}

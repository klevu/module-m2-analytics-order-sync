<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\ResourceModel;

use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SyncOrderHistory extends AbstractDb
{
    public const TABLE = 'klevu_sync_order_history';
    public const ID_FIELD_NAME = SyncOrderHistoryModel::FIELD_ENTITY_ID;

    /**
     * @var mixed[][]
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    protected $_serializableFields = [
        SyncOrderHistoryModel::FIELD_ADDITIONAL_INFORMATION => [
            '[]',
            [],
            false,
        ],
    ];

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(
            mainTable: static::TABLE,
            idFieldName: static::ID_FIELD_NAME,
        );
    }

    /**
     * @param AbstractModel $object
     *
     * @return SyncOrderHistory
     */
    protected function _beforeSave(AbstractModel $object): self
    {
        if ($object instanceof SyncOrderHistoryModel) {
            // This ensures that if a record has been created using setData, values are correct for the DB
            $object->getEntityId();
            $object->getSyncOrderId();
            $object->getTimestamp();
            $object->getAction();
            $object->getVia();
            $object->getResult();
        }

        return parent::_beforeSave($object);
    }
}

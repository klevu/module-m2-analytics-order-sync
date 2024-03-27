<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\ResourceModel;

use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SyncOrder extends AbstractDb
{
    public const TABLE = 'klevu_sync_order';
    public const ID_FIELD_NAME = SyncOrderModel::FIELD_ENTITY_ID;

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
}

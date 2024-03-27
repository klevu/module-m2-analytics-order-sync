<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Magento\Framework\Model\AbstractModel;

class SyncOrder extends AbstractModel implements SyncOrderInterface
{
    public const FIELD_ENTITY_ID = 'entity_id';
    public const FIELD_ORDER_ID = 'order_id';
    public const FIELD_STORE_ID = 'store_id';
    public const FIELD_STATUS = 'status';
    public const FIELD_ATTEMPTS = 'attempts';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(
            resourceModel: SyncOrderResource::class,
        );
    }

    /**
     * @return int|null
     */
    public function getEntityId(): ?int
    {
        $entityId = $this->getData(static::FIELD_ENTITY_ID);
        if (!is_int($entityId)) {
            $entityId = is_numeric($entityId)
                ? (int)$entityId
                : null;
            $this->setEntityId($entityId);
        }

        return $entityId;
    }

    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    /**
     * @param int|null $entityId
     *
     * @return self
     */
    public function setEntityId($entityId): self
    {
        return $this->setData(static::FIELD_ENTITY_ID, $entityId);
    }
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint

    /**
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        $orderId = $this->getData(static::FIELD_ORDER_ID);
        if (!is_int($orderId)) {
            $orderId = is_numeric($orderId)
                ? (int)$orderId
                : null;
            $this->setOrderId($orderId);
        }

        return $orderId;
    }

    /**
     * @param int|null $orderId
     * @return void
     */
    public function setOrderId(?int $orderId): void
    {
        $this->setData(static::FIELD_ORDER_ID, $orderId);
    }

    /**
     * @return int|null
     */
    public function getStoreId(): ?int
    {
        $storeId = $this->getData(static::FIELD_STORE_ID);
        if (!is_int($storeId)) {
            $storeId = is_numeric($storeId)
                ? (int)$storeId
                : null;
            $this->setStoreId($storeId);
        }

        return $storeId;
    }

    /**
     * @param int|null $storeId
     * @return void
     */
    public function setStoreId(?int $storeId): void
    {
        $this->setData(static::FIELD_STORE_ID, $storeId);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        $status = $this->getData(static::FIELD_STATUS);
        if (!is_string($status)) {
            $status = (string)$status;
            $this->setStatus($status);
        }

        return $status;
    }

    /**
     * @param string $status
     * @return void
     */
    public function setStatus(string $status): void
    {
        $this->setData(static::FIELD_STATUS, $status);
    }

    /**
     * @return int
     */
    public function getAttempts(): int
    {
        $attempts = $this->getData(static::FIELD_ATTEMPTS);
        if (!is_int($attempts)) {
            $attempts = is_numeric($attempts)
                ? (int)$attempts
                : 0;
            $this->setAttempts($attempts);
        }

        return $attempts;
    }

    /**
     * @param int $attempts
     * @return void
     */
    public function setAttempts(int $attempts): void
    {
        $this->setData(static::FIELD_ATTEMPTS, $attempts);
    }

    /**
     * @return bool
     */
    public function canInitiateSync(): bool
    {
        $status = Statuses::tryFrom($this->getStatus());

        return $status && $status->canInitiateSync();
    }
}

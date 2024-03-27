<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory as SyncOrderHistoryResource;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Magento\Framework\Model\AbstractModel;

class SyncOrderHistory extends AbstractModel implements SyncOrderHistoryInterface
{
    public const FIELD_ENTITY_ID = 'entity_id';
    public const FIELD_SYNC_ORDER_ID = 'sync_order_id';
    public const FIELD_TIMESTAMP = 'timestamp';
    public const FIELD_ACTION = 'action';
    public const FIELD_VIA = 'via';
    public const FIELD_RESULT = 'result';
    public const FIELD_ADDITIONAL_INFORMATION = 'additional_information';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(
            resourceModel: SyncOrderHistoryResource::class,
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
    public function getSyncOrderId(): ?int
    {
        $syncOrderId = $this->getData(static::FIELD_SYNC_ORDER_ID);
        if (!is_int($syncOrderId)) {
            $syncOrderId = is_numeric($syncOrderId)
                ? (int)$syncOrderId
                : null;
            $this->setSyncOrderId($syncOrderId);
        }

        return $syncOrderId;
    }

    /**
     * @param int|null $syncOrderId
     * @return void
     */
    public function setSyncOrderId(?int $syncOrderId): void
    {
        $this->setData(static::FIELD_SYNC_ORDER_ID, $syncOrderId);
    }

    /**
     * @return string|null
     */
    public function getTimestamp(): ?string
    {
        $timestamp = $this->getData(static::FIELD_TIMESTAMP);
        if (!is_string($timestamp) && null !== $timestamp) {
            $timestamp = (string)$timestamp;
            $this->setTimestamp($timestamp);
        }

        return $timestamp;
    }

    /**
     * @param string $timestamp
     * @return void
     */
    public function setTimestamp(string $timestamp): void
    {
        $this->setData(static::FIELD_TIMESTAMP, $timestamp);
    }

    /**
     * @return string|null
     */
    public function getAction(): ?string
    {
        $action = $this->getData(static::FIELD_ACTION);
        if (!is_string($action) && null !== $action) {
            $action = (string)$action;
            $this->setAction($action);
        }

        return $action;
    }

    /**
     * @param string $action
     * @return void
     */
    public function setAction(string $action): void
    {
        $this->setData('action', $action);
    }

    /**
     * @return string|null
     */
    public function getVia(): ?string
    {
        $via = $this->getData(static::FIELD_VIA);
        if (!is_string($via) && null !== $via) {
            $via = (string)$via;
            $this->setVia($via);
        }

        return $via;
    }

    /**
     * @param string $via
     * @return void
     */
    public function setVia(string $via): void
    {
        $this->setData(static::FIELD_VIA, $via);
    }

    /**
     * @return string|null
     */
    public function getResult(): ?string
    {
        $result = $this->getData(static::FIELD_RESULT);
        if (!is_string($result) && null !== $result) {
            $result = (string)$result;
            $this->setResult($result);
        }

        return $result;
    }

    /**
     * @param string $result
     * @return void
     */
    public function setResult(string $result): void
    {
        $this->setData(static::FIELD_RESULT, $result);
    }

    /**
     * @return mixed[]|null
     */
    public function getAdditionalInformation(): ?array
    {
        return $this->getData(static::FIELD_ADDITIONAL_INFORMATION);
    }

    /**
     * @param mixed[] $additionalInformation
     * @return void
     */
    public function setAdditionalInformation(array $additionalInformation): void
    {
        $this->setData(static::FIELD_ADDITIONAL_INFORMATION, $additionalInformation);
    }
}

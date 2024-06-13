<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Action;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Exception\OrderNotValidException;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessingActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ProcessFailedOrderSyncActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

class ProcessFailedOrderSync implements ProcessFailedOrderSyncActionInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;
    /**
     * @var MarkOrderAsProcessingActionInterface
     */
    private readonly MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction;
    /**
     * @var MarkOrderAsProcessedActionInterface
     */
    private readonly MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     * @param MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction
     * @param MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        SyncOrderRepositoryInterface $syncOrderRepository,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
        MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction,
        MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->syncOrderRepository = $syncOrderRepository;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
        $this->markOrderAsProcessingAction = $markOrderAsProcessingAction;
        $this->markOrderAsProcessedAction = $markOrderAsProcessedAction;
    }

    /**
     * @param OrderInterface $order
     * @param string $via
     * @param mixed[] $additionalInformation
     * @return void
     * @throws OrderNotValidException
     */
    public function execute(
        OrderInterface $order,
        string $via = '',
        array $additionalInformation = [],
    ): void {
        if (!$order->getEntityId()) {
            throw new OrderNotValidException(
                phrase: __('Cannot process failed order sync for unsaved order #%1', $order->getIncrementId()),
            );
        }

        $syncOrderRecord = $this->getSyncOrderRecordForOrder($order);
        $maxAttempts = $this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS,
            ScopeInterface::SCOPE_STORES,
            $order->getStoreId(),
        );
        $maxAttempts = (is_numeric($maxAttempts) && $maxAttempts > 0)
            ? (int)$maxAttempts
            : 1;

        $orderId = (int)$order->getEntityId();
        if ($syncOrderRecord->getAttempts() < $maxAttempts) {
            $additionalInformation['reason'] ??= (string)__(
                'Order requeued after failed sync attempt within configured threshold',
            );

            $this->queueOrderForSyncAction->execute(
                orderId: $orderId,
                via: $via,
                additionalInformation: $additionalInformation,
            );
        } else {
            $this->markOrderAsProcessedAction->execute(
                orderId: $orderId,
                resultStatus: Statuses::ERROR->value,
                via: $via,
                additionalInformation: $additionalInformation,
            );
        }
    }

    /**
     * @param OrderInterface $order
     * @return SyncOrderInterface
     */
    private function getSyncOrderRecordForOrder(OrderInterface $order): SyncOrderInterface
    {
        $orderId = (int)$order->getEntityId();

        try {
            $syncOrderRecord = $this->syncOrderRepository->getByOrderId($orderId);
        } catch (NoSuchEntityException) {
            $syncOrderRecord = null;
        }

        if (
            !$syncOrderRecord
            || Statuses::tryFrom($syncOrderRecord->getStatus())?->canInitiateSync()
        ) {
            $this->markOrderAsProcessingAction->execute($orderId);
            $syncOrderRecord = $this->getSyncOrderRecordForOrder($order);
        }

        return $syncOrderRecord;
    }
}

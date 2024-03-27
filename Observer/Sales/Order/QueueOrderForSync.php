<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Observer\Sales\Order;

use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class QueueOrderForSync implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SyncEnabledStoresProviderInterface
     */
    private readonly SyncEnabledStoresProviderInterface $syncEnabledStoresProvider;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;

    /**
     * @param LoggerInterface $logger
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     */
    public function __construct(
        LoggerInterface $logger,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
    ) {
        $this->logger = $logger;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $order = $event?->getData('order'); // @phpstan-ignore-line Never trust the docblocks
        if (!($order instanceof OrderInterface)) {
            $this->logger->warning(
                message: 'Received invalid order record in observer. Expected {expectedClass}; Received {actualClass}',
                context: [
                    'method' => __METHOD__,
                    'expectedClass' => OrderInterface::class,
                    'actualClass' => get_debug_type($order),
                ],
            );

            return;
        }

        if (!$this->canSyncOrderForStore((int)$order->getStoreId())) {
            return;
        }

        try {
            $this->queueOrderForSyncAction->execute(
                orderId: (int)$order->getEntityId(),
                via: 'Order save event',
            );
        } catch (\Exception $exception) {
            $this->logger->error(
                message: 'Encountered error queuing order record for sync: {error}',
                context: [
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                    'method' => __METHOD__,
                    'orderId' => $order->getEntityId(),
                    'storeId' => $order->getStoreId(),
                ],
            );
        }
    }

    /**
     * @param int $storeId
     * @return bool
     */
    private function canSyncOrderForStore(int $storeId): bool
    {
        $syncEnabledStoreIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->syncEnabledStoresProvider->get(),
        );

        return in_array(
            needle: $storeId,
            haystack: $syncEnabledStoreIds,
            strict: true,
        );
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSync\Exception\OrderNotFoundException;
use Klevu\AnalyticsOrderSync\Exception\OrderNotValidException;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncOrderForOrderProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class SyncOrderForOrderProvider implements SyncOrderForOrderProviderInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    private readonly OrderRepositoryInterface $orderRepository;
    /**
     * @var SyncOrderInterfaceFactory
     */
    private readonly SyncOrderInterfaceFactory $syncOrderModelFactory;
    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SyncOrderInterfaceFactory $syncOrderModelFactory
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SyncOrderInterfaceFactory $syncOrderModelFactory,
        SyncOrderRepositoryInterface $syncOrderRepository,
    ) {
        $this->orderRepository = $orderRepository;
        $this->syncOrderModelFactory = $syncOrderModelFactory;
        $this->syncOrderRepository = $syncOrderRepository;
    }

    /**
     * @param OrderInterface $order
     * @return SyncOrderInterface
     * @throws OrderNotFoundException
     * @throws OrderNotValidException
     */
    public function getForOrder(OrderInterface $order): SyncOrderInterface
    {
        $orderId = (int)$order->getEntityId();
        if (!$orderId) {
            throw new OrderNotValidException(__(
                'Cannot create an order sync record for #%1 until the order is saved',
                $order->getIncrementId(),
            ));
        }

        // We call the methods this way around because if we called getForOrder from
        //  getForOrderId, we would always need to load the order record even if an
        //  existing SyncOrder exists
        // Here, we may need to pull the order from the repository despite already
        //  having the object, but that should be less common and less expensive
        //  (due to the OrderRepository's internal caching, if the order was
        //  originally retrieved from the repository)
        return $this->getForOrderId($orderId);
    }

    /**
     * @param int $orderId
     * @return SyncOrderInterface
     * @throws OrderNotFoundException
     */
    public function getForOrderId(int $orderId): SyncOrderInterface
    {
        try {
            $syncOrderRecord = $this->syncOrderRepository->getByOrderId($orderId);
        } catch (NoSuchEntityException) {
            try {
                $order = $this->orderRepository->get($orderId);
            } catch (NoSuchEntityException $orderNotFoundException) {
                throw new OrderNotFoundException(
                    phrase: __(
                        'Cannot create an order sync record for order id #%1: Order not found',
                        $orderId,
                    ),
                    cause: $orderNotFoundException,
                );
            }

            $syncOrderRecord = $this->syncOrderModelFactory->create();
            $syncOrderRecord->setOrderId($orderId);
            // Note: we intentionally do not check whether sync is disabled for store here as calling code
            //  may override that setting. This provider assumes permission through the act of being invoked
            $syncOrderRecord->setStoreId((int)$order->getStoreId());
        }

        return $syncOrderRecord;
    }
}

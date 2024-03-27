<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsApi\Api\EventsDataProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\ValidatorInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class OrderSyncEventsDataProvider implements EventsDataProviderInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;
    /**
     * @var OrderRepositoryInterface
     */
    private readonly OrderRepositoryInterface $orderRepository;
    /**
     * @var ValidatorInterface[]
     */
    private array $yieldOrderValidators = [];

    /**
     * @param LoggerInterface $logger
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ValidatorInterface[] $yieldOrderValidators
     */
    public function __construct(
        LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncOrderRepositoryInterface $syncOrderRepository,
        OrderRepositoryInterface $orderRepository,
        array $yieldOrderValidators = [],
    ) {
        $this->logger = $logger;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrderRepository = $syncOrderRepository;
        $this->orderRepository = $orderRepository;
        array_walk($yieldOrderValidators, [$this, 'addYieldOrderValidator']);
    }

    /**
     * @param SearchCriteriaInterface|null $searchCriteria
     * @return \Generator|null
     * @throws \Zend_Validate_Exception
     */
    public function get(?SearchCriteriaInterface $searchCriteria = null): ?\Generator
    {
        $syncOrders = $this->syncOrderRepository->getList(
            searchCriteria: $searchCriteria ?: $this->searchCriteriaBuilder->create(),
        );
        if (!$syncOrders->getTotalCount()) {
            return null;
        }

        /** @var SyncOrderInterface $syncOrder */
        foreach ($syncOrders->getItems() as $syncOrder) {
            try {
                $order = $this->orderRepository->get($syncOrder->getOrderId());
            } catch (LocalizedException $exception) {
                $this->logger->error(
                    message: 'Encountered error loading order #{orderId}: {error}',
                    context: [
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                        'orderId' => $syncOrder->getOrderId(),
                        'storeId' => $syncOrder->getStoreId(),
                        'syncStatus' => $syncOrder->getStatus(),
                        'syncAttempts' => $syncOrder->getAttempts(),
                    ],
                );

                continue;
            }

            foreach ($this->yieldOrderValidators as $validator) {
                if (!$validator->isValid($order)) {
                    $this->logger->debug(
                        message: 'Order #{orderId} found ineligible for sync',
                        context: [
                            'orderId' => $order->getEntityId(),
                            'incrementId' => $order->getIncrementId(),
                            'storeId' => $order->getStoreId(),
                            'validator' => $validator::class,
                            'messages' => $validator->getMessages(),
                        ],
                    );

                    continue 2;
                }
            }

            yield $order;
        }
    }

    /**
     * @param ValidatorInterface $yieldOrderValidator
     * @return void
     */
    private function addYieldOrderValidator(ValidatorInterface $yieldOrderValidator): void
    {
        $this->yieldOrderValidators[] = $yieldOrderValidator;
    }
}

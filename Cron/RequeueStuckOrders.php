<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Api\RequeueStuckOrdersServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RequeueStuckOrders
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var SyncEnabledStoresProviderInterface
     */
    private readonly SyncEnabledStoresProviderInterface $syncEnabledStoresProvider;
    /**
     * @var RequeueStuckOrdersServiceInterface
     */
    private readonly RequeueStuckOrdersServiceInterface $requeueStuckOrdersService;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param RequeueStuckOrdersServiceInterface $requeueStuckOrdersService
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        RequeueStuckOrdersServiceInterface $requeueStuckOrdersService,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->requeueStuckOrdersService = $requeueStuckOrdersService;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $storeIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->syncEnabledStoresProvider->get(),
        );
        $thresholdInMinutes = (int)$this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );

        $logContext = [
            'storeIds' => $storeIds,
            'thresholdInMinutes' => $thresholdInMinutes,
        ];
        $this->logger->info(
            message: 'Starting requeue of stuck orders',
            context: $logContext,
        );

        $result = $this->requeueStuckOrdersService->execute(
            storeIds: $storeIds,
            thresholdInMinutes: $thresholdInMinutes,
            via: 'Cron: requeue_stuck_orders',
        );

        foreach ($result->getMessages() as $message) {
            $this->logger->log(
                level: $result->isSuccess()
                    ? LogLevel::DEBUG
                    : LogLevel::WARNING,
                message: $message,
                context: $logContext,
            );
        }

        $this->logger->info(
            message: 'Requeue of stuck orders complete',
            context: $logContext,
        );
    }
}

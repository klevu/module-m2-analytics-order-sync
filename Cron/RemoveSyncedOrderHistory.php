<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\RemoveSyncedOrderHistoryServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RemoveSyncedOrderHistory
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
     * @var RemoveSyncedOrderHistoryServiceInterface
     */
    private readonly RemoveSyncedOrderHistoryServiceInterface $removeSyncedOrderHistoryService;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param RemoveSyncedOrderHistoryServiceInterface $removeSyncedOrderHistoryService
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        RemoveSyncedOrderHistoryServiceInterface $removeSyncedOrderHistoryService,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->removeSyncedOrderHistoryService = $removeSyncedOrderHistoryService;
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function execute(): void
    {
        $storeIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->syncEnabledStoresProvider->get(),
        );

        $logContext = [
            'storeIds' => $storeIds,
        ];
        $this->logger->info(
            message: 'Starting clean-up of synced order history',
            context: $logContext,
        );

        $successfulOrderThreshold = (string)$this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );
        $errorOrderThreshold = (string)$this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );
        if ('' === $successfulOrderThreshold && '' === $errorOrderThreshold) {
            $this->logger->info(
                message: 'Order history clean-up disabled',
                context: $logContext,
            );

            return;
        }

        if ('' !== $successfulOrderThreshold) {
            $this->removeExpiredHistoryItems(
                syncStatus: Statuses::SYNCED->value,
                storeIds: $storeIds,
                thresholdInDays: (int)$successfulOrderThreshold,
            );
        }
        if ('' !== $errorOrderThreshold) {
            $this->removeExpiredHistoryItems(
                syncStatus: Statuses::ERROR->value,
                storeIds: $storeIds,
                thresholdInDays: (int)$errorOrderThreshold,
            );
        }

        $this->logger->info(
            message: 'Synced order history clean-up complete',
            context: $logContext,
        );
    }

    /**
     * @param string $syncStatus
     * @param int[] $storeIds
     * @param int $thresholdInDays
     * @return void
     */
    private function removeExpiredHistoryItems(
        string $syncStatus,
        array $storeIds,
        int $thresholdInDays,
    ): void {
        $result = $this->removeSyncedOrderHistoryService->execute(
            syncStatus: $syncStatus,
            storeIds: $storeIds,
            thresholdInDays: $thresholdInDays,
        );

        foreach ($result->getMessages() as $message) {
            $this->logger->log(
                level: $result->isSuccess()
                    ? LogLevel::DEBUG
                    : LogLevel::WARNING,
                message: $message,
                context: [
                    'syncStatus' => $syncStatus,
                    'storeIds' => $storeIds,
                    'thresholdInDays' => $thresholdInDays,
                ],
            );
        }

        $message = 'Removed {successCount} history items for orders in status {syncStatus}. ';
        if ($result->getErrorCount()) {
            $message .= 'Failed to remove {errorCount} items.';
        }

        $this->logger->debug(
            message: $message,
            context: [
                'syncStatus' => $syncStatus,
                'storeIds' => $storeIds,
                'thresholdInDays' => $thresholdInDays,
                'result' => $result->isSuccess(),
                'successCount' => $result->getSuccessCount(),
                'errorCount' => $result->getErrorCount(),
            ],
        );
    }
}

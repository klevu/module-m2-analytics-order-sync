<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\DuplicateOrderIpsProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class CheckDuplicateOrderIps
{
    /**
     * @var EventManagerInterface
     */
    private readonly EventManagerInterface $eventManager;
    /**
     * @var StoreManagerInterface 
     */
    private readonly StoreManagerInterface $storeManager;
    /**
     * @var ScopeConfigInterface 
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var SyncEnabledStoresProviderInterface
     */
    private readonly SyncEnabledStoresProviderInterface $syncEnabledStoresProvider;
    /**
     * @var DuplicateOrderIpsProviderInterface
     */
    private readonly DuplicateOrderIpsProviderInterface $duplicateOrderIpsProvider;

    /**
     * @param EventManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param DuplicateOrderIpsProviderInterface $duplicateOrderIpsProvider
     */
    public function __construct(
        EventManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        DuplicateOrderIpsProviderInterface $duplicateOrderIpsProvider,
    ) {
        $this->eventManager = $eventManager;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->duplicateOrderIpsProvider = $duplicateOrderIpsProvider;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $storeIds = $this->storeManager->isSingleStoreMode()
            ? [null]
            : array_map(
                callback: static fn (StoreInterface $store): int => (int)$store->getId(),
                array: $this->syncEnabledStoresProvider->get(),
            );
        
        $periodDays = (int)$this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_PERIOD_DAYS,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );
        $threshold = (float)$this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_THRESHOLD,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        );

        $duplicateOrderIps = [];
        foreach ($storeIds as $storeId) {
            $ipField = $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
                (null === $storeId)
                    ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                    : ScopeInterface::SCOPE_STORES,
                $storeId,
            );
            
            $duplicateOrderIps[$storeId] = $this->duplicateOrderIpsProvider->get(
                storeId: $storeId,
                ipField: $ipField,
                periodDays: $periodDays,
                threshold: $threshold,
            );
        }
        $duplicateOrderIps = array_filter($duplicateOrderIps);
        
        if ($duplicateOrderIps) {
            $this->eventManager->dispatch(
                eventName: 'klevu_notifications_upsertNotification',
                data: [
                    'notification_data' => [
                        'type' => Constants::NOTIFICATION_TYPE_DUPLICATE_ORDER_IPS,
                        'severity' => MessageInterface::SEVERITY_MAJOR,
                        // Magic number prevents dependency. See \Klevu\Notifications\Model\Notification::STATUS_WARNING
                        'status' => 4,
                        'message' => 'Klevu has detected many checkout orders originating from the same IP address '
                            . 'causing inaccuracies in Klevu sales analytics.',
                        'details' => $this->formatNotificationDetails($duplicateOrderIps),
                        'date' => date('Y-m-d H:i:s'),
                        'delete_after_view' => false,
                    ],
                ],
            );

            return;
        }

        $this->eventManager->dispatch(
            eventName: 'klevu_notifications_deleteNotification',
            data: [
                'notification_data' => [
                    'type' => Constants::NOTIFICATION_TYPE_DUPLICATE_ORDER_IPS,
                ],
            ],
        );
    }

    /**
     * @param array<int|string, array<string, int>> $duplicateOrderIps
     *
     * @return string
     */
    private function formatNotificationDetails(array $duplicateOrderIps): string
    {
        $return = '';
        foreach ($duplicateOrderIps as $storeId => $duplicateOrderIpDetails) {
            $return .= __('Store ID: %1', $storeId)->render();
            $return .= PHP_EOL;

            foreach ($duplicateOrderIpDetails as $ipAddress => $duplicateCount) {
                $return .= __('IP Address "%1" found %2 time(s)', $ipAddress, $duplicateCount)->render();
                $return .= PHP_EOL;
            }
        }

        return $return;
    }
}

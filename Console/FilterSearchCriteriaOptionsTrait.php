<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Console;

use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Store\Api\Data\StoreInterface;

trait FilterSearchCriteriaOptionsTrait
{
    /**
     * @var SyncEnabledStoresProviderInterface
     */
    private readonly SyncEnabledStoresProviderInterface $syncEnabledStoresProvider;

    /**
     * @param int[]|null $orderIds
     * @return int[]|null
     */
    private function getOrderIdsToFilter(?array $orderIds): ?array
    {
        $orderIdsToFilter = array_filter($orderIds ?? []);

        if (!$orderIdsToFilter) {
            return null;
        }

        return array_filter(
            array: $orderIdsToFilter,
            callback: static fn (mixed $orderId): bool => (
                (is_int($orderId) || ctype_digit($orderId))
                && (int)$orderId > 0
            ),
        );
    }

    /**
     * @param string[]|null $syncStatuses
     * @return string[]|null
     */
    private function getSyncStatusesToFilter(?array $syncStatuses): ?array
    {
        return array_filter($syncStatuses ?? []) ?: null;
    }

    /**
     * @param int[]|null $storeIds
     * @param bool $ignoreSyncEnabledFlag
     * @return int[]|null
     */
    private function getStoreIdsToFilter(?array $storeIds, bool $ignoreSyncEnabledFlag): ?array
    {
        $storeIdsToFilter = array_filter($storeIds ?? []) ?: null;

        if ($ignoreSyncEnabledFlag) {
            return $storeIdsToFilter;
        }

        $syncEnabledStores = $this->syncEnabledStoresProvider->get();
        $syncEnabledStoreIds = array_map(
            callback: static fn (StoreInterface $store): int => (int)$store->getId(),
            array: $syncEnabledStores,
        );
        if (null === $storeIdsToFilter) {
            $storeIdsToFilter = $syncEnabledStoreIds;
        } else {
            $storeIdsToFilter = array_intersect(
                $storeIdsToFilter,
                $syncEnabledStoreIds,
            );
        }

        return $storeIdsToFilter;
    }
}

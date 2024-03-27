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
        return array_filter($orderIds ?? []) ?: null;
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
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $syncEnabledStores,
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

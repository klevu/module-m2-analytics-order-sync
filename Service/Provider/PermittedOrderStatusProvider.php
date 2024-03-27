<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\PermittedOrderStatusProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;
use Magento\Store\Model\ScopeInterface;

class PermittedOrderStatusProvider implements PermittedOrderStatusProviderInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var OrderStatusCollectionFactory
     */
    private readonly OrderStatusCollectionFactory $orderStatusCollectionFactory;
    /**
     * @var string[]|null
     */
    private ?array $orderStatusOptionHash = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderStatusCollectionFactory $orderStatusCollectionFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        OrderStatusCollectionFactory $orderStatusCollectionFactory,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->orderStatusCollectionFactory = $orderStatusCollectionFactory;
    }

    /**
     * @param int $storeId
     * @return string[]
     */
    public function getForStore(int $storeId): array
    {
        $allOrderStatuses = $this->getOrderStatusOptionHash();
        $excludedOrderStatuses = array_filter(
            array_map('trim',
                explode(
                    ',',
                    (string)$this->scopeConfig->getValue(
                        Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
                        ScopeInterface::SCOPE_STORES,
                        $storeId,
                    ),
                ),
            ),
        );

        return array_diff(
            $allOrderStatuses,
            $excludedOrderStatuses,
        );
    }

    /**
     * @return string[]
     */
    private function getOrderStatusOptionHash(): array
    {
        if (null === $this->orderStatusOptionHash) {
            /** @var OrderStatusCollection $orderStatusCollection */
            $orderStatusCollection = $this->orderStatusCollectionFactory->create();
            $this->orderStatusOptionHash = array_keys($orderStatusCollection->toOptionHash());
        }

        return $this->orderStatusOptionHash;
    }
}

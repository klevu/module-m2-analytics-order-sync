<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider\PipelineContext;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\Configuration\Service\Provider\StoreScopeProviderInterface;
use Klevu\PlatformPipelines\Api\PipelineContextProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ConfigProvider implements PipelineContextProviderInterface
{
    /**
     * @var StoreScopeProviderInterface
     */
    private readonly StoreScopeProviderInterface $storeScopeProvider;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var mixed[][]
     */
    private array $contextForStoreId = [];

    /**
     * @param StoreScopeProviderInterface $storeScopeProvider
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        StoreScopeProviderInterface $storeScopeProvider,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->storeScopeProvider = $storeScopeProvider;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return $this
     */
    public function get(): self
    {
        return $this;
    }

    /**
     * @return mixed[]
     */
    public function getForCurrentStore(): array
    {
        $currentStore = $this->storeScopeProvider->getCurrentStore();
        $storeId = (int)$currentStore?->getId() ?: 0;

        if (!array_key_exists($storeId, $this->contextForStoreId)) {
            $this->contextForStoreId[$storeId] = [
                'ip_address_accessor' => $this->getIpAddressAccessor($storeId),
                'excluded_order_statuses' => $this->getExcludedOrderStatuses($storeId),
            ];
        }

        return $this->contextForStoreId[$storeId];
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function getIpAddressAccessor(int $storeId): string
    {
        $ipAddressAttribute = $this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
            ScopeInterface::SCOPE_STORES,
            $storeId,
        );

        return sprintf(
            'get%s()',
            str_replace(
                search: '_',
                replace: '',
                subject: ucwords(
                    string: $ipAddressAttribute,
                    separators: '_',
                ),
            ),
        );
    }

    /**
     * @param int $storeId
     * @return string[]
     */
    private function getExcludedOrderStatuses(int $storeId): array
    {
        $excludeStatuses = $this->scopeConfig->getValue(
            Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            ScopeInterface::SCOPE_STORES,
            $storeId,
        ) ?? '';

        return array_filter(
            array_map(
                'trim',
                explode(',', $excludeStatuses),
            ),
        );
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\Configuration\Model\CurrentScopeInterfaceFactory;
use Klevu\Configuration\Service\Provider\ApiKeyProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SyncEnabledStoresProvider implements SyncEnabledStoresProviderInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var StoreManagerInterface
     */
    private readonly StoreManagerInterface $storeManager;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var CurrentScopeInterfaceFactory
     */
    private readonly CurrentScopeInterfaceFactory $currentScopeInterfaceFactory;
    /**
     * @var ApiKeyProviderInterface
     */
    private readonly ApiKeyProviderInterface $apiKeyProvider;
    /**
     * @var StoreInterface[]|null
     */
    private ?array $syncEnabledStores = null;

    /**
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CurrentScopeInterfaceFactory $currentScopeInterfaceFactory
     * @param ApiKeyProviderInterface $apiKeyProvider
     */
    public function __construct(
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        CurrentScopeInterfaceFactory $currentScopeInterfaceFactory,
        ApiKeyProviderInterface $apiKeyProvider,
    ) {
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->currentScopeInterfaceFactory = $currentScopeInterfaceFactory;
        $this->apiKeyProvider = $apiKeyProvider;
    }

    /**
     * @return StoreInterface[]
     */
    public function get(): array
    {
        if (null === $this->syncEnabledStores) {
            $allStores = $this->storeManager->getStores(
                withDefault: false,
            );

            try {
                $this->syncEnabledStores = array_filter(
                    $allStores,
                    function (StoreInterface $store): bool {
                        $currentScope = $this->currentScopeInterfaceFactory->create([
                            'scopeObject' => $store,
                        ]);

                        return $store->getIsActive()
                            && $this->apiKeyProvider->get($currentScope)
                            && $this->scopeConfig->isSetFlag(
                                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                                ScopeInterface::SCOPE_STORES,
                                $store->getId(),
                            );
                    },
                );
            } catch (LocalizedException $exception) {
                $this->logger->error(
                    message: 'Exception filtering sync enabled stores: {exceptionMessage}',
                    context: [
                        'exception' => get_debug_type($exception),
                        'exceptionMessage' => $exception->getMessage(),
                        'method' => __METHOD__,
                    ],
                );
                $this->syncEnabledStores = [];
            }
        }

        return $this->syncEnabledStores;
    }

    /**
     * @return void
     */
    public function clearCache(): void
    {
        $this->syncEnabledStores = null;
    }
}

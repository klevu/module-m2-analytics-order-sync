<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Validator\OrderSyncEventsData;

use Klevu\Configuration\Model\CurrentScopeInterfaceFactory;
use Klevu\Configuration\Service\Provider\ApiKeyProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validator\AbstractValidator;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

class KlevuIntegrationStatusValidator extends AbstractValidator
{
    /**
     * @var StoreManagerInterface
     */
    private readonly StoreManagerInterface $storeManager;
    /**
     * @var CurrentScopeInterfaceFactory
     */
    private readonly CurrentScopeInterfaceFactory $currentScopeFactory;
    /**
     * @var ApiKeyProviderInterface
     */
    private readonly ApiKeyProviderInterface $apiKeyProvider;
    /**
     * @var bool[]
     */
    private array $storeIdToIntegrationStatus = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param CurrentScopeInterfaceFactory $currentScopeFactory
     * @param ApiKeyProviderInterface $apiKeyProvider
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        CurrentScopeInterfaceFactory $currentScopeFactory,
        ApiKeyProviderInterface $apiKeyProvider,
    ) {
        $this->storeManager = $storeManager;
        $this->currentScopeFactory = $currentScopeFactory;
        $this->apiKeyProvider = $apiKeyProvider;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        $this->_messages = [];
        if (!($value instanceof OrderInterface)) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'Value must be instance of %s; received %s',
                    OrderInterface::class,
                    get_debug_type($value),
                ),
            );
        }

        $storeId = $value->getStoreId();
        if (!array_key_exists($storeId, $this->storeIdToIntegrationStatus)) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $currentScope = $this->currentScopeFactory->create([
                    'scopeObject' => $store,
                ]);
                $this->storeIdToIntegrationStatus[$storeId] = !!$this->apiKeyProvider->get($currentScope);
            } catch (NoSuchEntityException) {
                $this->storeIdToIntegrationStatus[$storeId] = false;
            }
        }

        return $this->storeIdToIntegrationStatus[$storeId];
    }
}

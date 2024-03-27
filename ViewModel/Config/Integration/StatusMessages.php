<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\ViewModel\Config\Integration;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\Options\CronFrequency;
use Klevu\Configuration\Service\Provider\ScopeProviderInterface;
use Klevu\Configuration\ViewModel\MessageInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;

class StatusMessages implements MessageInterface
{
    use OptionSourceToHashTrait;

    /**
     * @var RequestInterface
     */
    private readonly RequestInterface $request;
    /**
     * @var ScopeProviderInterface
     */
    private readonly ScopeProviderInterface $scopeProvider;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $cronFrequencyOptions;
    /**
     * @var Phrase[][]|null
     */
    private ?array $messages = null;

    /**
     * @param RequestInterface $request
     * @param ScopeProviderInterface $scopeProvider
     * @param ScopeConfigInterface $scopeConfig
     * @param OptionSourceInterface $cronFrequencyOptions
     */
    public function __construct(
        RequestInterface $request,
        ScopeProviderInterface $scopeProvider,
        ScopeConfigInterface $scopeConfig,
        OptionSourceInterface $cronFrequencyOptions,
    ) {
        $this->request = $request;
        $this->scopeProvider = $scopeProvider;
        $this->scopeConfig = $scopeConfig;
        $this->cronFrequencyOptions = $cronFrequencyOptions;
    }

    /**
     * @return array|Phrase[][]
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getMessages(): array
    {
        if (null !== $this->messages) {
            return $this->messages;
        }

        $this->messages = [];

        $currentScopeObject = $this->getCurrentScopeObject();
        if ($currentScopeObject instanceof WebsiteInterface) {
            if (!method_exists($currentScopeObject, 'getStores')) {
                throw new \LogicException(sprintf(
                    'Cannot retrieve stores for WebsiteInterface implementation of type %s',
                    $currentScopeObject::class,
                ));
            }

            $stores = $currentScopeObject->getStores();
        } else {
            $stores = [$currentScopeObject];
        }
        $stores = array_filter($stores);

        $showCronMessage = false;

        /** @var StoreInterface $store */
        foreach ($stores as $store) {
            $orderSyncEnabled = $this->scopeConfig->isSetFlag(
                Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORES,
                $store->getId(),
            );
            $orderSyncFrequency = $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ScopeInterface::SCOPE_STORES,
                $store->getId(),
            );
            $excludedStatuses = $this->scopeConfig->getValue(
                Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
                ScopeInterface::SCOPE_STORES,
                $store->getId(),
            );

            $message = sprintf(
                '%s [%s / #%s]: ',
                $store->getName(),
                $store->getCode(),
                $store->getId(),
            );
            $message .= $this->generateMessageStringForSettings(
                orderSyncEnabled: $orderSyncEnabled,
                orderSyncFrequency: $orderSyncFrequency,
                excludedStatuses: $excludedStatuses,
            );

            $messageKey = match (true) {
                !$orderSyncEnabled => 'error',
                CronFrequency::OPTION_DISABLED === $orderSyncFrequency => 'warning',
                default => 'success',
            };

            $this->messages[$messageKey][] = __($message);
            $showCronMessage = $showCronMessage || $orderSyncEnabled;
        }

        return $this->messages;
    }

    /**
     * @return StoreInterface|WebsiteInterface|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function getCurrentScopeObject(): StoreInterface|WebsiteInterface|null
    {
        $currentScope = $this->scopeProvider->getCurrentScope();
        $currentScopeObject = $currentScope->getScopeObject();
        if (null !== $currentScopeObject) {
            return $currentScopeObject;
        }

        $scopeId = $this->request->getParam('scope_id');
        $scopeType = $this->request->getParam('scope');
        if (!$scopeId || !$scopeType) {
            return null;
        }

        $this->scopeProvider->setCurrentScopeById(
            scopeId: (int)$scopeId,
            scopeType: $scopeType,
        );

        $currentScope = $this->scopeProvider->getCurrentScope();

        return $currentScope->getScopeObject();
    }

    /**
     * @param bool $orderSyncEnabled
     * @param string|null $orderSyncFrequency
     * @param string|null $excludedStatuses
     * @return string
     */
    private function generateMessageStringForSettings(
        bool $orderSyncEnabled,
        ?string $orderSyncFrequency,
        ?string $excludedStatuses,
    ): string {
        $cronFrequencies = $this->getHashForOptionSource($this->cronFrequencyOptions);

        $messageString = '';

        switch (true) {
            case !$orderSyncEnabled:
                $messageString .= __(
                    'Server-side order data processing is disabled. '
                    . 'Conversion rate metrics will not be available in KMC.',
                );
                break;

            case CronFrequency::OPTION_DISABLED === $orderSyncFrequency:
                $messageString .= __(
                    'Server-side order data processing is enabled, but sending via Magento\'s cron is disabled. '
                    . 'Please ensure you have implemented an alternative means of transmitting order data to Klevu, '
                    . 'otherwise conversion rate metrics will not be available in KMC.',
                );
                break;

            default:
                $messageString .= __(
                    'Server-side order data processing is enabled and configured to send data to Klevu %1.',
                    CronFrequency::OPTION_CUSTOM === $orderSyncFrequency
                        ? __('on a custom schedule')
                        : strtolower(
                            (string)($cronFrequencies[$orderSyncFrequency] ?? ''),
                        ),
                );
                break;
        }

        if ($orderSyncEnabled) {
            $messageString .= ' ';

            $excludedStatusesArray = array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string)$excludedStatuses,
                    ),
                ),
            );
            $messageString .= $excludedStatusesArray
                ? __(
                    'Orders in the following statuses at time of sync will not be included: %1.',
                    implode(', ', $excludedStatusesArray),
                )
                : __('Orders in any status will be included.');
        }

        return $messageString;
    }
}

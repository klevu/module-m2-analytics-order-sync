<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Validator\OrderSyncEventsData;

use Klevu\AnalyticsOrderSyncApi\Service\Provider\PermittedOrderStatusProviderInterface;
use Magento\Framework\Validator\AbstractValidator;
use Magento\Sales\Api\Data\OrderInterface;

class MagentoOrderStatusValidator extends AbstractValidator
{
    /**
     * @var PermittedOrderStatusProviderInterface
     */
    private readonly PermittedOrderStatusProviderInterface $permittedOrderStatusProvider;

    /**
     * @param PermittedOrderStatusProviderInterface $permittedOrderStatusProvider
     */
    public function __construct(
        PermittedOrderStatusProviderInterface $permittedOrderStatusProvider,
    ) {
        $this->permittedOrderStatusProvider = $permittedOrderStatusProvider;
    }

    /**
     * @param OrderInterface $value
     * @return bool
     * @throws \InvalidArgumentException
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

        $permittedOrderStatuses = $this->permittedOrderStatusProvider->getForStore(
            storeId: (int)$value->getStoreId(),
        );
        $orderStatusIsPermitted = (
            empty($permittedOrderStatuses)
            || in_array(
                needle: $value->getStatus(),
                haystack: $permittedOrderStatuses,
                strict: true,
            )
        );
        if (!$orderStatusIsPermitted) {
            $this->_addMessages([
                __(
                    'Order status "%1" is excluded by configuration in store %2',
                    $value->getStatus(),
                    $value->getStoreId(),
                ),
            ]);
        }

        return empty($this->getMessages());
    }
}

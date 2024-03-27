<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Validator\OrderSyncEventsData;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Magento\Framework\Validator\AbstractValidator;
use Magento\Sales\Api\Data\OrderInterface;

class KlevuOrderSyncStatusValidator extends AbstractValidator
{
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

        $extensionAttributes = $value->getExtensionAttributes();
        $status = Statuses::tryFrom(
            value: $extensionAttributes->getKlevuOrderSyncStatus(),
        );

        if (!($status?->canInitiateSync())) {
            $this->_addMessages([
                __(
                    'Sync Status "%1" cannot initiate sync',
                    $status?->value,
                ),
            ]);
        }

        return empty($this->getMessages());
    }
}

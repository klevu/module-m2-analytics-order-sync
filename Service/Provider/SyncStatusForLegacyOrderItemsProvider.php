<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncStatusForLegacyOrderItemsProviderInterface;

class SyncStatusForLegacyOrderItemsProvider implements SyncStatusForLegacyOrderItemsProviderInterface
{
    /**
     * @param int[]|string[] $orderItems
     *
     * @return Statuses
     * @throws \InvalidArgumentException
     */
    public function get(array $orderItems): Statuses
    {
        $this->validateOrderItems($orderItems);

        $orderItemStatuses = array_unique(
            array_column(
                array: $orderItems,
                column_key: 'send',
            ),
        );

        return match (true) {
            !$orderItemStatuses => Statuses::QUEUED,
            count($orderItemStatuses) > 1 && in_array('0', $orderItemStatuses, true),
            count($orderItemStatuses) > 1 && in_array(0, $orderItemStatuses, true) => Statuses::PARTIAL,
            in_array(0, $orderItemStatuses, true),
            in_array('0', $orderItemStatuses, true) => Statuses::QUEUED,
            in_array(2, $orderItemStatuses, true),
            in_array('2', $orderItemStatuses, true) => Statuses::ERROR,
            in_array(1, $orderItemStatuses, true),
            in_array('1', $orderItemStatuses, true) => Statuses::SYNCED,
            default => Statuses::NOT_REGISTERED,
        };
    }

    /**
     * @param mixed[] $orderItems
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateOrderItems(array $orderItems): void
    {
        $errors = [];
        foreach ($orderItems as $index => $orderItem) {
            try {
                $this->validateOrderItem($orderItem);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = sprintf(
                    '#%s : %s',
                    $index,
                    $exception->getMessage(),
                );
            }
        }

        if ($errors) {
            throw new \InvalidArgumentException(
                message: implode(', ', $errors),
            );
        }
    }

    /**
     * @param mixed $orderItem
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateOrderItem(mixed $orderItem): void
    {
        if (!is_array($orderItem)) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'Order item must be an array, received %s.',
                    get_debug_type($orderItem),
                ),
            );
        }

        if (!array_key_exists('send', $orderItem)) {
            throw new \InvalidArgumentException(
                message: 'Order item must contain "send" key.',
            );
        }

        if (!is_numeric($orderItem['send'])) {
            throw new \InvalidArgumentException(
                message: sprintf(
                    'Order item "send" value must be numeric, received %s.',
                    get_debug_type($orderItem['send']),
                ),
            );
        }
    }
}

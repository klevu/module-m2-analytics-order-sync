<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Transformer\OrderAnalytics;

use Klevu\AnalyticsOrderSyncApi\Service\Provider\ConsolidatedGroupedProductOrderItemProviderInterface;
use Klevu\Pipelines\Exception\TransformationException;
use Klevu\Pipelines\Model\ArgumentIterator;
use Klevu\Pipelines\Transformer\TransformerInterface;
use Magento\Sales\Api\Data\OrderInterface;

class InjectConsolidatedGroupedProductOrderItems implements TransformerInterface
{
    /**
     * @var ConsolidatedGroupedProductOrderItemProviderInterface
     */
    private readonly ConsolidatedGroupedProductOrderItemProviderInterface $consolidatedGroupedProductOrderItemProvider;

    /**
     * @param ConsolidatedGroupedProductOrderItemProviderInterface $consolidatedGroupedProductOrderItemProvider
     */
    public function __construct(
        ConsolidatedGroupedProductOrderItemProviderInterface $consolidatedGroupedProductOrderItemProvider,
    ) {
        $this->consolidatedGroupedProductOrderItemProvider = $consolidatedGroupedProductOrderItemProvider;
    }

    /**
     * @param mixed $data
     * @param ArgumentIterator|null $arguments
     * @param \ArrayAccess<int|string, mixed>|null $context
     * @return OrderInterface
     * @throws TransformationException
     */
    public function transform(
        mixed $data,
        // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        ?ArgumentIterator $arguments = null,
        // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        ?\ArrayAccess $context = null,
    ): OrderInterface {
        if (!($data instanceof OrderInterface)) {
            throw new TransformationException(
                transformerName: static::class,
                errors: [
                    sprintf(
                        'Invalid data received for transformation (%s); Expected %s, received %s',
                        static::class,
                        OrderInterface::class,
                        get_debug_type($data),
                    ),
                ],
            );
        }

        $order = clone $data;

        $consolidatedGroupedOrderItems = $this->consolidatedGroupedProductOrderItemProvider->getForOrder(
            order: $order,
        );
        if (!$consolidatedGroupedOrderItems) {
            return $order;
        }

        $orderItems = array_merge(
            $order->getItems(),
            $consolidatedGroupedOrderItems,
        );
        $order->setItems($orderItems);

        return $order;
    }
}

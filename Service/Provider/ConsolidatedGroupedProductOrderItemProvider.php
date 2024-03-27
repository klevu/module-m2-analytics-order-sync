<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\AnalyticsOrderSyncApi\Service\Provider\ConsolidatedGroupedProductOrderItemProviderInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderItemInterfaceFactory;
use Psr\Log\LoggerInterface;

class ConsolidatedGroupedProductOrderItemProvider implements ConsolidatedGroupedProductOrderItemProviderInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ProductRepositoryInterface
     */
    private readonly ProductRepositoryInterface $productRepository;
    /**
     * @var OrderItemInterfaceFactory
     */
    private readonly OrderItemInterfaceFactory $orderItemFactory;
    /**
     * @var string
     */
    private readonly string $productType;

    /**
     * @param LoggerInterface $logger
     * @param ProductRepositoryInterface $productRepository
     * @param OrderItemInterfaceFactory $orderItemFactory
     * @param string $productType
     */
    public function __construct(
        LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        OrderItemInterfaceFactory $orderItemFactory,
        string $productType = 'consolidated-grouped',
    ) {
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->orderItemFactory = $orderItemFactory;
        $this->productType = $productType;
    }

    /**
     * @param OrderInterface $order
     * @return OrderItemInterface[]
     */
    public function getForOrder(OrderInterface $order): array
    {
        $groupedProductOrderItems = $this->extractGroupedProductOrderItems($order);
        if (!$groupedProductOrderItems) {
            return [];
        }

        $groupedProductOrderItemsByParent = [];
        foreach ($groupedProductOrderItems as $orderItem) {
            $logContext = [
                'method' => __METHOD__,
                'orderId' => method_exists($order, 'getId') ? $order->getId() : null,
                'orderItemId' => $orderItem->getItemId(),
                'orderItemFqcn' => $orderItem::class,
            ];

            if (!method_exists($orderItem, 'getDataUsingMethod')) {
                $this->logger->warning(
                    message: 'OrderItem implementation of type {orderItemFqcn} does not implement getDataUsingMethod',
                    context: $logContext,
                );

                continue;
            }

            $productOptions = $orderItem->getDataUsingMethod('product_options');
            $parentProductId = (int)($productOptions['super_product_config']['product_id'] ?? 0);
            if (!$parentProductId) {
                $this->logger->notice(
                    message: 'Order Item #{orderItemId} does not contain parent product id information',
                    context: $logContext,
                );

                continue;
            }

            $groupedProductOrderItemsByParent[$parentProductId] ??= [];
            $groupedProductOrderItemsByParent[$parentProductId][] = $orderItem;
        }

        return array_map(
            [$this, 'generateConsolidatedGroupedProductOrderItem'],
            array_keys($groupedProductOrderItemsByParent),
            array_values($groupedProductOrderItemsByParent),
        );
    }

    /**
     * @param OrderInterface $order
     * @return OrderItemInterface[]
     */
    private function extractGroupedProductOrderItems(
        OrderInterface $order,
    ): array {
        return array_filter(
            array: $order->getItems(),
            callback: static fn (OrderItemInterface $item): bool => Grouped::TYPE_CODE === $item->getProductType(),
        );
    }

    /**
     * @param int $parentProductId
     * @param OrderItemInterface[] $orderItems
     * @return OrderItemInterface
     */
    private function generateConsolidatedGroupedProductOrderItem(
        int $parentProductId,
        array $orderItems,
    ): OrderItemInterface {
        $return = $this->initConsolidatedOrderItem(
            parentProductId: $parentProductId,
            orderItems: $orderItems,
        );

        $totals = $this->getTotalsArray();
        foreach ($orderItems as $orderItem) {
            $return = $this->applyOrderItemToConsolidatedOrderItem(
                consolidatedOrderItem: $return,
                orderItem: $orderItem,
            );

            // We collate the totals values while looping through, and will set against the order item when
            //  all the values are accounted for
            array_walk(
                array: $totals,
                // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedPassingByReference
                callback: function (mixed &$total, string $key) use ($orderItem): void {
                    $method = $this->getAttributeMethodName('get', $key);
                    $value = $orderItem->{$method}();
                    if (null === $value) {
                        return;
                    }

                    switch ($key) {
                        case 'base_price':
                        case 'base_price_incl_tax':
                        case 'base_original_price':
                        case 'original_price':
                        case 'price':
                        case 'price_incl_tax':
                            $total += ($value * $orderItem->getQtyOrdered());
                            break;

                        default:
                            $total += $value;
                            break;
                    }
                },
            );
        }

        $return = $this->applyOrderItemTotals(
            consolidatedOrderItem: $return,
            totals: $totals,
        );

        return $return;
    }

    /**
     * @param int $parentProductId
     * @param OrderItemInterface[] $orderItems
     * @return OrderItemInterface
     */
    private function initConsolidatedOrderItem(
        int $parentProductId,
        array $orderItems,
    ): OrderItemInterface {
        $parentProduct = null;
        try {
            $parentProduct = $this->productRepository->getById($parentProductId);
        } catch (NoSuchEntityException) {
            $this->logger->warning(
                message: 'Parent product id #{parentProductId} cannot be found for order items',
                context: [
                    'method' => __METHOD__,
                    'parentProductId' => $parentProductId,
                    'orderItemIds' => array_map(
                        static fn (OrderItemInterface $orderItem): int => (int)$orderItem->getItemId(),
                        $orderItems,
                    ),
                ],
            );
        }

        $return = $this->orderItemFactory->create();

        // While Magento ignores the parent grouped product, we want to use its data in places
        $return->setProductId($parentProductId);
        $return->setProductType($this->productType);
        $return->setSku($parentProduct?->getSku());
        $return->setName($parentProduct?->getName());
        $return->setDescription($parentProduct?->getName());

        // We get a total value of all associated products and use an order quantity of 1
        // The alternative would be a summed quantity of all associated products, which would
        //  require unintuitive calculations of base price for the item, subject to rounding and
        //  tax calculation errors
        $return->setQtyOrdered(1);
        $return->setIsVirtual(1);

        return $return;
    }

    /**
     * @param OrderItemInterface $consolidatedOrderItem
     * @param OrderItemInterface $orderItem
     * @return OrderItemInterface
     */
    private function applyOrderItemToConsolidatedOrderItem(
        OrderItemInterface $consolidatedOrderItem,
        OrderItemInterface $orderItem,
    ): OrderItemInterface {
        // The following we cannot or do not support when flattening
        // * additional_data
        // * applied_rule_ids
        // * ext_order_item_id
        // * gw_id
        // * parent_item_id - will always be null anyway
        // * quote_item_id - there is no quote item for this pseudo item
        // * extension_attributes
        // * event_id

        // These values should be the same (or within a margin of error) for items within an order
        // We could implement checks and throw an exception if the order items we are provided are not
        //  from the same order if necessary in the future
        $consolidatedOrderItem->setOrderId($orderItem->getOrderId());
        $consolidatedOrderItem->setStoreId($orderItem->getStoreId());
        $consolidatedOrderItem->setCreatedAt($orderItem->getCreatedAt());
        $consolidatedOrderItem->setUpdatedAt($orderItem->getUpdatedAt());

        if (null === $consolidatedOrderItem->getItemId()) {
            // Yes, we're taking advantage of loose typing in OrderItemInterface::setItemId
            //  because this will never reach the database (and, if it does, it will fail to persist)
            //  and we can ensure that there is no clash with the real order items
            $consolidatedOrderItem->setItemId(
                $orderItem->getItemId() . '-' . $this->productType, // @phpstan-ignore-line (see above)
            );
        }

        // The following flags should always be the same for items within an order
        // Given they are not critical, if we find a mismatch then we set to null
        $flagAttributes = [
            'is_qty_decimal',
            'no_discount',
            'free_shipping',
            'locked_do_invoice',
            'locked_do_ship',
        ];
        foreach ($flagAttributes as $attributeCode) {
            $getMethod = $this->getAttributeMethodName('get', $attributeCode);
            $existingValue = $consolidatedOrderItem->{$getMethod}();
            $newValue = $orderItem->{$getMethod}();

            $setMethod = $this->getAttributeMethodName('set', $attributeCode);
            switch (true) {
                case $existingValue === $newValue:
                case null === $newValue:
                    break;

                case null === $existingValue:
                    $consolidatedOrderItem->{$setMethod}($newValue);
                    break;

                default:
                    $this->logger->warning(
                        message: 'Found conflicting values for order item attribute {attributeCode}',
                        context: [
                            'method' => __METHOD__,
                            'attributeCode' => $attributeCode,
                            'existingValue' => $existingValue,
                            'newValue' => $newValue,
                        ],
                    );
                    $consolidatedOrderItem->{$setMethod}(null);
                    break;
            }
        }

        return $consolidatedOrderItem;
    }

    /**
     * @param OrderItemInterface $consolidatedOrderItem
     * @param array<int|float|null> $totals
     * @return OrderItemInterface
     */
    private function applyOrderItemTotals(
        OrderItemInterface $consolidatedOrderItem,
        array $totals,
    ): OrderItemInterface {
        // Totals summed during loop are safe to be assigned now
        foreach ($totals as $key => $total) {
            if (null === $total) {
                continue;
            }

            $method = $this->getAttributeMethodName('set', $key);
            switch ($key) {
                case 'qty_backordered':
                case 'qty_canceled':
                case 'qty_invoiced':
                case 'qty_refunded':
                case 'qty_returned':
                case 'qty_shipped':
                    // Because our qty ordered is set to 1, the maximum this can be is also 1
                    $consolidatedOrderItem->{$method}($total ? 1 : 0);
                    break;

                default:
                    $consolidatedOrderItem->{$method}($total);
                    break;
            }
        }

        $price = $consolidatedOrderItem->getPrice();
        if ($price) {
            $consolidatedOrderItem->setTaxPercent(
                taxPercent: 100 * (($consolidatedOrderItem->getPriceInclTax() / $price) - 1),
            );
            $consolidatedOrderItem->setDiscountPercent(
                discountPercent: 100 * ($consolidatedOrderItem->getDiscountAmount() / $price),
            );
        }

        return $consolidatedOrderItem;
    }

    /**
     * @return null[]
     */
    private function getTotalsArray(): array
    {
        return [
            'base_amount_refunded' => null,
            'base_cost' => null,
            'base_discount_amount' => null,
            'base_discount_invoiced' => null,
            'base_discount_refunded' => null,
            'base_discount_tax_compensation_amount' => null,
            'base_discount_tax_compensation_invoiced' => null,
            'base_discount_tax_compensation_refunded' => null,
            'base_original_price' => null,
            'base_price' => null,
            'base_price_incl_tax' => null,
            'base_row_invoiced' => null,
            'base_row_total' => null,
            'base_row_total_incl_tax' => null,
            'base_tax_amount' => null,
            'base_tax_before_discount' => null,
            'base_tax_invoiced' => null,
            'base_tax_refunded' => null,
            'base_tax_weee_tax_applied_amount' => null,
            'base_wee_tax_applied_row_amnt' => null,
            'base_weee_tax_disposition' => null,
            'base_weee_tax_row_disposition' => null,

            'discount_amount' => null,
            'discount_invoiced' => null,
            'discount_refunded' => null,

            'gw_base_price' => null,
            'gw_base_price_invoiced' => null,
            'gw_base_price_refunded' => null,
            'gw_base_tax_amount' => null,
            'gw_base_tax_amount_invoiced' => null,
            'gw_base_tax_amount_refunded' => null,
            'gw_price' => null,
            'gw_price_invoiced' => null,
            'gw_price_refunded' => null,
            'gw_tax_amount' => null,
            'gw_tax_amount_invoiced' => null,
            'gw_tax_amount_refunded' => null,

            'discount_tax_compensation_amount' => null,
            'discount_tax_compensation_canceled' => null,
            'discount_tax_compensation_invoiced' => null,
            'discount_tax_compensation_refunded' => null,

            'original_price' => null,
            'price' => null,
            'price_incl_tax' => null,

            'row_invoiced' => null,
            'row_total' => null,
            'row_total_incl_tax' => null,
            'row_weight' => null,

            'tax_amount' => null,
            'tax_before_discount' => null,
            'tax_canceled' => null,
            'tax_invoiced' => null,
            'tax_refunded' => null,
            'weee_tax_applied' => null,
            'weee_tax_applied_amount' => null,
            'weee_tax_applied_row_amount' => null,
            'weee_tax_disposition' => null,
            'weee_tax_row_disposition' => null,
            'weight' => null,

            'qty_backordered' => null,
            'qty_canceled' => null,
            'qty_invoiced' => null,
            'qty_refunded' => null,
            'qty_returned' => null,
            'qty_shipped' => null,
        ];
    }

    /**
     * @param string $action
     * @param string $attributeCode
     * @return string
     */
    private function getAttributeMethodName(
        string $action,
        string $attributeCode,
    ): string {
        return $action
            . str_replace(
                search: '_',
                replace: '',
                subject: ucwords(
                    string: $attributeCode,
                    separators: '_',
                ),
            );
    }
}

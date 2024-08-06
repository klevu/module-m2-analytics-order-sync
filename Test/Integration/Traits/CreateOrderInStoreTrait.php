<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Whitespaces.DuplicateSpaces.DuplicateSpaces

namespace Klevu\AnalyticsOrderSync\Test\Integration\Traits;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessingActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Klevu\TestFixtures\Catalog\ConfigurableProductBuilder;
use Klevu\TestFixtures\Catalog\GroupedProductBuilder;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use TddWizard\Fixtures\Catalog\ProductBuilder;
use TddWizard\Fixtures\Checkout\CartBuilder;
use TddWizard\Fixtures\Core\ConfigFixture;
use TddWizard\Fixtures\Sales\InvoiceBuilder;
use TddWizard\Fixtures\Sales\OrderBuilder;
use TddWizard\Fixtures\Sales\ShipmentBuilder;

trait CreateOrderInStoreTrait
{
    /**
     * @todo Alternative product types
     *
     * @param string $status
     * @param mixed[] $orderData
     * @param mixed[] $orderItemsData
     * @param Statuses $syncStatus
     * @param string $storeCode
     *
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    private function createOrderInStore(
        string $storeCode,
        string $status,
        array $orderData,
        array $orderItemsData,
        Statuses $syncStatus,
    ): OrderInterface {
        $store = $this->storeManager->getStore($storeCode);
        $this->storeManager->setCurrentStore($store);

        $orderBuilder = OrderBuilder::anOrder();
        $orderBuilder = $orderBuilder->withProducts(
            ...array_map(
                static function (array $orderItemData): ProductBuilder {
                    switch ($orderItemData['product_type'] ?? ProductType::TYPE_SIMPLE) {
                        case ProductType::TYPE_SIMPLE:
                            $return = ProductBuilder::aSimpleProduct();
                            $return = $return->withData($orderItemData);
                            $return = $return->withSku($orderItemData['sku']);
                            break;

                        case ProductType::TYPE_VIRTUAL:
                            $return = ProductBuilder::aVirtualProduct();
                            $return = $return->withData($orderItemData);
                            $return = $return->withSku($orderItemData['sku']);
                            break;

                        case Grouped::TYPE_CODE:
                            $return = GroupedProductBuilder::aGroupedProduct()
                                ->withSku($orderItemData['sku']);
                            if (isset($orderItemData['name'])) {
                                $return = $return->withName($orderItemData['name']);
                            }

                            $configuration = $orderItemData['configuration'];
                            foreach ($configuration['product_links'] ?? [] as $linkedProductData) {
                                $linkedProductBuilder = ProductBuilder::aSimpleProduct();
                                $linkedProductBuilder = $linkedProductBuilder->withData($linkedProductData);

                                $linkedProductBuilder = $linkedProductBuilder->withSku(
                                    sku: $linkedProductData['sku'] ?? ($orderItemData['sku'] . '_lp' . rand(0, 99)),
                                );
                                $linkedProductBuilder = $linkedProductBuilder->withPrice(
                                    price: $linkedProductData['price'] ?? 100.00,
                                );

                                $return = $return->withLinkedProduct($linkedProductBuilder);
                            }
                            break;

                        case Configurable::TYPE_CODE:
                            $return = ConfigurableProductBuilder::aConfigurableProduct()
                                ->withSku($orderItemData['sku']);
                            if (isset($orderItemData['name'])) {
                                $return = $return->withName($orderItemData['name']);
                            }

                            $configuration = $orderItemData['configuration'];
                            foreach ($configuration['configurable_attribute_codes'] ?? [] as $attributeCode) {
                                $return = $return->withConfigurableAttribute($attributeCode);
                            }
                            foreach ($configuration['variants'] ?? [] as $variantProductData) {
                                $variantProductBuilder = ProductBuilder::aSimpleProduct();
                                $variantProductBuilder = $variantProductBuilder->withData($variantProductData);

                                $variantProductBuilder = $variantProductBuilder->withSku(
                                    sku: $variantProductData['sku'] ?? ($orderItemData['sku'] . '_v' . rand(0, 99)),
                                );
                                $variantProductBuilder = $variantProductBuilder->withPrice(
                                    price: $variantProductData['price'] ?? 100.00,
                                );

                                $return = $return->withVariant($variantProductBuilder);
                            }
                            break;

                        default:
                            throw new \LogicException(sprintf(
                                'Unsupported product type %s',
                                $orderItemData['product_type'] ?? null,
                            ));
                    }

                    return $return;
                },
                $orderItemsData,
            ),
        );

        $cartBuilder = CartBuilder::forCurrentSession();
        foreach ($orderItemsData as $orderItem) {
            $cartBuilder = match ($orderItem['product_type'] ?? 'simple') {
                Grouped::TYPE_CODE => $cartBuilder->withGroupedProduct(
                    sku: $orderItem['sku'],
                    options: $orderItem['options'] ?? [],
                    qty: $orderItem['qty'] ?? 1,
                ),
                Configurable::TYPE_CODE => $cartBuilder->withConfigurableProduct(
                    sku: $orderItem['sku'],
                    options: $orderItem['options'] ?? [],
                    qty: $orderItem['qty'] ?? 1,
                ),
                default => $cartBuilder->withSimpleProduct(
                    sku: $orderItem['sku'],
                    qty: $orderItem['qty'] ?? 1.0,
                ),
            };
        }

        $orderBuilder = $orderBuilder->withCart($cartBuilder);

        $order = $orderBuilder->build();
        if ($orderData) {
            foreach ($orderData as $key => $value) {
                $order->setDataUsingMethod($key, $value);
            }
            $this->orderRepository->save($order);
        }

        switch ($status) {
            case 'processing':
                $this->invoiceOrder($order);
                break;

            case 'complete':
                $this->invoiceOrder($order);
                $this->shipOrder($order);
                break;
        }

        $this->orderFixtures[$order->getEntityId()] = $order;

        $this->createOrUpdateSyncOrderRecord(
            order: $order,
            syncStatus: $syncStatus,
        );

        return $order;
    }

    // -- Fixtures and Mocks

    /**
     * @param string $storeCode
     * @param WebsiteInterface $website
     * @param string|null $klevuApiKey
     * @param bool $syncEnabled
     * @return StoreInterface
     * @throws \Exception
     */
    private function createStoreFixture(
        string $storeCode,
        WebsiteInterface $website,
        ?string $klevuApiKey,
        bool $syncEnabled,
    ): StoreInterface {
        try {
            $store = $this->storeManager->getStore($storeCode);
            $this->storeFixturesPool->add($store);
        } catch (NoSuchEntityException) {
            $this->createStore([
                'code' => $storeCode,
                'key' => $storeCode,
                'website_id' => (int)$website->getId(),
                'with_sequence' => true,
            ]);
            $storeFixture = $this->storeFixturesPool->get($storeCode);
            $store = $storeFixture->get();
        }

        if ($klevuApiKey) {
            ConfigFixture::setForStore(
                path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
                value: $klevuApiKey,
                storeCode: $storeCode,
            );
            ConfigFixture::setForStore(
                path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
                value: self::FIXTURE_REST_AUTH_KEY,
                storeCode: $storeCode,
            );
        }
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: (int)$syncEnabled,
            storeCode: $storeCode,
        );

        return $store;
    }

    /**
     * @param OrderInterface|Order $order
     * @return ShipmentInterface
     */
    private function shipOrder(
        OrderInterface $order,
    ): ShipmentInterface {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        $shipmentBuilder = ShipmentBuilder::forOrder($order);

        return $shipmentBuilder->build();
    }

    /**
     * @param OrderInterface|Order $order
     * @return InvoiceInterface
     */
    private function invoiceOrder(
        OrderInterface $order,
    ): InvoiceInterface {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        $invoiceBuilder = InvoiceBuilder::forOrder($order);

        return $invoiceBuilder->build();
    }

    /**
     * @param OrderInterface $order
     * @param Statuses $syncStatus
     * @param int|null $attempts
     * @return void
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    private function createOrUpdateSyncOrderRecord(
        OrderInterface $order,
        Statuses $syncStatus,
        ?int $attempts = null,
    ): void {
        if (!($order instanceof Order)) {
            throw new \InvalidArgumentException(sprintf(
                'Order argument must be instance of %s; received %s in %s',
                Order::class,
                get_debug_type($order),
                __METHOD__,
            ));
        }

        switch ($syncStatus) {
            case Statuses::QUEUED:
            case Statuses::RETRY:
            case Statuses::PARTIAL:
                /** @var QueueOrderForSyncActionInterface $queueOrderForSyncAction */
                $queueOrderForSyncAction = $this->objectManager->get(QueueOrderForSyncActionInterface::class);
                $queueOrderForSyncAction->execute(
                    orderId: (int)$order->getId(),
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            case Statuses::PROCESSING:
                /** @var MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction */
                $markOrderAsProcessingAction = $this->objectManager->get(MarkOrderAsProcessingActionInterface::class);
                $markOrderAsProcessingAction->execute(
                    orderId: (int)$order->getId(),
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            case Statuses::SYNCED:
            case Statuses::ERROR:
                /** @var MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction */
                $markOrderAsProcessedAction = $this->objectManager->get(MarkOrderAsProcessedActionInterface::class);
                $markOrderAsProcessedAction->execute(
                    orderId: (int)$order->getId(),
                    resultStatus: $syncStatus->value,
                    via: 'PHPUnit: Update Fixture',
                );
                break;

            default:
                break;
        }

        if (null !== $attempts) {
            $syncOrder = $this->syncOrderRepository->getByOrderId(
                orderId: (int)$order->getEntityId(),
            );
            $syncOrder->setAttempts($attempts);
            $this->syncOrderRepository->save($syncOrder);
        }
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Fixtures\Order;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\Configuration\Service\Provider\ApiKeyProvider;
use Klevu\Configuration\Service\Provider\AuthKeyProvider;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use TddWizard\Fixtures\Catalog\ProductBuilder;
use TddWizard\Fixtures\Checkout\CartBuilder;
use TddWizard\Fixtures\Core\ConfigFixture;
use TddWizard\Fixtures\Sales\OrderBuilder;

/**
 * @property ObjectManagerInterface $objectManager
 */
trait OrderTrait
{
    /**
     * @var array<OrderInterface&Order>
     */
    private ?array $orderFixtures = [];

    /**
     * @param bool $orderSyncEnabled
     * @param int $orderLines
     * @return OrderInterface
     * @throws \Exception
     */
    private function getOrderFixture(
        bool $orderSyncEnabled = true,
        int $orderLines = 1,
    ): OrderInterface {
        $syncEnabledStoresProvider = $this->objectManager->get(SyncEnabledStoresProviderInterface::class);
        $syncEnabledStoresProvider->clearCache();

        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: (int)$orderSyncEnabled,
        );
        ConfigFixture::setGlobal(
            path: ApiKeyProvider::CONFIG_XML_PATH_JS_API_KEY,
            value: 'klevu-1234567890',
        );
        ConfigFixture::setGlobal(
            path: AuthKeyProvider::CONFIG_XML_PATH_REST_AUTH_KEY,
            value: 'ABCDE1234567890',
        );

        $orderBuilder = OrderBuilder::anOrder();
        $skus = array_map(
            static fn (int $i): string => sprintf(
                'klevu_test_product_%d_%d',
                $i,
                rand(),
            ),
            range(1, $orderLines),
        );

        $orderBuilder = $orderBuilder->withProducts(
            ...array_map(
                static fn (string $sku): ProductBuilder => ProductBuilder::aSimpleProduct()->withSku(
                    sku: $sku,
                ),
                $skus,
            ),
        );
        $cartBuilder = CartBuilder::forCurrentSession();
        foreach ($skus as $sku) {
            $cartBuilder = $cartBuilder->withSimpleProduct(
                sku: $sku,
                qty: rand(1, 5),
            );
        }

        $order = $orderBuilder->build();
        $this->orderFixtures[$order->getEntityId()] = $order;

        return $order;
    }

    /**
     * @return void
     */
    private function rollbackOrderFixtures(): void
    {
        if (!$this->orderFixtures) {
            return;
        }

        $this->registerSecureArea();
        array_walk($this->orderFixtures, [$this, 'deleteOrderFixture']);
        $this->unregisterSecureArea();
    }

    /**
     * @param OrderInterface $order
     * @return void
     * @throws StateException
     */
    private function deleteOrderFixture(OrderInterface $order): void
    {
        if (!method_exists($order, 'getAllItems')) {
            throw new \InvalidArgumentException(sprintf(
                'Order object of type %s does not contain method getAllItems()',
                $order::class,
            ));
        }

        /** @var OrderRepositoryInterface $orderRepository */
        $orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->get(ProductRepositoryInterface::class);

        $allProductIds = array_map(
            static fn (OrderItemInterface $orderItem): int => (int)$orderItem->getProductId(),
            $order->getAllItems(),
        );

        $orderRepository->delete($order);
        foreach ($allProductIds as $productId) {
            try {
                $productRepository->deleteById((string)$productId);
            } catch (NoSuchEntityException) {
                // No drama
            }
        }
        unset($this->orderFixtures[$order->getEntityId()]);
    }

    /**
     * @return void
     */
    private function registerSecureArea(): void
    {
        /** @var Registry $registry */
        $registry = $this->objectManager->get(Registry::class);

        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
    }

    /**
     * @return void
     */
    private function unregisterSecureArea(): void
    {
        /** @var Registry $registry */
        $registry = $this->objectManager->get(Registry::class);

        $registry->unregister('isSecureArea');
    }
}

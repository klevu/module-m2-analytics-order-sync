<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Plugin\Sales\OrderRepository;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

class SetOrderSyncExtensionAttributesPluginTest extends TestCase
{
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var OrderRepositoryInterface|null
     */
    private ?OrderRepositoryInterface $orderRepository = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);

        $this->orderFixtures = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testAfterGet_SyncDisabled(): void
    {
        $orderFixture = $this->getOrderFixture(false);
        $orderId = (int)$orderFixture->getEntityId();

        $order = $this->orderRepository->get($orderId);

        $extensionAttributes = $order->getExtensionAttributes();
        $this->assertInstanceOf(OrderExtensionInterface::class, $extensionAttributes);
        $this->assertSame(
            Statuses::NOT_REGISTERED->value,
            $extensionAttributes->getKlevuOrderSyncStatus(),
        );
       $this->assertSame(0, $extensionAttributes->getKlevuOrderSyncAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testAfterGet_SyncEnabled(): void
    {
        $orderFixture = $this->getOrderFixture(true);
        $orderId = (int)$orderFixture->getEntityId();

        $order = $this->orderRepository->get($orderId);

        $extensionAttributes = $order->getExtensionAttributes();
        $this->assertInstanceOf(OrderExtensionInterface::class, $extensionAttributes);
        $this->assertSame(
            Statuses::QUEUED->value,
            $extensionAttributes->getKlevuOrderSyncStatus(),
        );
        $this->assertSame(0, $extensionAttributes->getKlevuOrderSyncAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testAfterGet_Synced(): void
    {
        $orderFixture = $this->getOrderFixture(true);
        $orderId = (int)$orderFixture->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(2);
        $this->syncOrderRepository->save($syncOrder);

        $order = $this->orderRepository->get($orderId);

        $extensionAttributes = $order->getExtensionAttributes();
        $this->assertInstanceOf(OrderExtensionInterface::class, $extensionAttributes);
        $this->assertSame(
            Statuses::SYNCED->value,
            $extensionAttributes->getKlevuOrderSyncStatus(),
        );
        $this->assertSame(2, $extensionAttributes->getKlevuOrderSyncAttempts());
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Exception\OrderNotFoundException;
use Klevu\AnalyticsOrderSync\Exception\OrderNotValidException;
use Klevu\AnalyticsOrderSync\Service\Provider\SyncOrderForOrderProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncOrderForOrderProviderInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderForOrderProvider instantiateTestObject(?array $arguments = null)
 * @method SyncOrderForOrderProvider instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncOrderForOrderProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line Used by traits

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrderForOrderProvider::class;
        $this->interfaceFqcn = SyncOrderForOrderProviderInterface::class;

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
    public function testGetForOrder_WithoutEntityId(): void
    {
        $order = $this->getOrderFixture(false);
        if (!method_exists($order, 'unsetData')) {
            throw new \LogicException(sprintf(
                'Order object (%s) does not contain unsetData method',
                $order::class,
            ));
        }

        $testOrder = clone $order;
        $testOrder->unsetData('entity_id');

        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $this->expectException(OrderNotValidException::class);
        $syncOrderForOrderProvider->getForOrder($testOrder);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForOrder_SyncOrderExists(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $syncOrder = $syncOrderForOrderProvider->getForOrder($order);

        $this->assertNotEmpty($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('queued', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForOrder_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);

        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $syncOrder = $syncOrderForOrderProvider->getForOrder($order);

        $this->assertNull($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForOrderId_OrderNotExists(): void
    {
        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $this->expectException(OrderNotFoundException::class);
        $syncOrderForOrderProvider->getForOrderId(99999999999);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForOrderId_SyncOrderExists(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $syncOrder = $syncOrderForOrderProvider->getForOrderId((int)$order->getEntityId());

        $this->assertNotEmpty($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('queued', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForOrderId_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);

        $syncOrderForOrderProvider = $this->instantiateTestObject();

        $syncOrder = $syncOrderForOrderProvider->getForOrderId((int)$order->getEntityId());

        $this->assertNull($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame('', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());
    }
}

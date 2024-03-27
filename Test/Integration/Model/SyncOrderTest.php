<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\SyncOrder;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrder instantiateTestObject(?array $arguments = null)
 * @method SyncOrder instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncOrderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
    use TestFactoryGenerationTrait;

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

        $this->implementationFqcn = SyncOrder::class;
        $this->interfaceFqcn = SyncOrderInterface::class;
    }

    public function testGettersAndSetters(): void
    {
        $syncOrder = $this->instantiateTestObject([]);

        // Default values
        $this->assertNull($syncOrder->getEntityId());
        $this->assertNull($syncOrder->getOrderId());
        $this->assertNull($syncOrder->getStoreId());
        $this->assertSame('', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());

        $syncOrder->setEntityId(1);
        $syncOrder->setOrderId(42);
        $syncOrder->setStoreId(999);
        $syncOrder->setStatus('synced');
        $syncOrder->setAttempts(5);

        // After update
        $this->assertSame(1, $syncOrder->getEntityId());
        $this->assertSame(42, $syncOrder->getOrderId());
        $this->assertSame(999, $syncOrder->getStoreId());
        $this->assertSame('synced', $syncOrder->getStatus());
        $this->assertSame(5, $syncOrder->getAttempts());
    }

    public function testGetAfterSetData(): void
    {
        $syncOrder = $this->instantiateTestObject([]);

        // Default values
        $this->assertNull($syncOrder->getEntityId());
        $this->assertNull($syncOrder->getOrderId());
        $this->assertNull($syncOrder->getStoreId());
        $this->assertSame('', $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());

        $syncOrder->setData([
            'entity_id' => '1',
            'order_id' => '42',
            'store_id' => '999',
            'status' => 'synced',
            'attempts' => '5',
        ]);

        // After update
        $this->assertSame(1, $syncOrder->getEntityId());
        $this->assertSame(42, $syncOrder->getOrderId());
        $this->assertSame(999, $syncOrder->getStoreId());
        $this->assertSame('synced', $syncOrder->getStatus());
        $this->assertSame(5, $syncOrder->getAttempts());
    }

    public function testCanInitiateSync(): void
    {
        $syncOrder = $this->instantiateTestObject([]);

        $this->assertFalse($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::NOT_REGISTERED->value);
        $this->assertFalse($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::QUEUED->value);
        $this->assertTrue($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $this->assertFalse($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::SYNCED->value);
        $this->assertFalse($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::RETRY->value);
        $this->assertTrue($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::PARTIAL->value);
        $this->assertTrue($syncOrder->canInitiateSync());

        $syncOrder->setStatus(Statuses::ERROR->value);
        $this->assertFalse($syncOrder->canInitiateSync());

        $syncOrder->setStatus('foo');
        $this->assertFalse($syncOrder->canInitiateSync());
    }
}

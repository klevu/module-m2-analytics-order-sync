<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model;

use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderHistory instantiateTestObject(?array $arguments = null)
 * @method SyncOrderHistory instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncOrderHistoryTest extends TestCase
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

        $this->implementationFqcn = SyncOrderHistory::class;
        $this->interfaceFqcn = SyncOrderHistoryInterface::class;
    }

    public function testGettersAndSetters(): void
    {
        $syncOrderHistory = $this->instantiateTestObject([]);

        // Default values
        $this->assertNull($syncOrderHistory->getEntityId());
        $this->assertNull($syncOrderHistory->getSyncOrderId());
        $this->assertNull($syncOrderHistory->getTimestamp());
        $this->assertNull($syncOrderHistory->getAction());
        $this->assertNull($syncOrderHistory->getVia());
        $this->assertNull($syncOrderHistory->getResult());
        $this->assertNull($syncOrderHistory->getAdditionalInformation());

        $syncOrderHistory->setEntityId(1);
        $syncOrderHistory->setSyncOrderId(42);
        $syncOrderHistory->setTimestamp('2000-01-01 00:00:00');
        $syncOrderHistory->setAction('queue');
        $syncOrderHistory->setVia('PHPUnit');
        $syncOrderHistory->setResult('success');
        $syncOrderHistory->setAdditionalInformation(['foo' => 'bar']);

        // After update
        $this->assertSame(1, $syncOrderHistory->getEntityId());
        $this->assertSame(42, $syncOrderHistory->getSyncOrderId());
        $this->assertSame('2000-01-01 00:00:00', $syncOrderHistory->getTimestamp());
        $this->assertSame('queue', $syncOrderHistory->getAction());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $this->assertSame('success', $syncOrderHistory->getResult());
        $this->assertSame(['foo' => 'bar'], $syncOrderHistory->getAdditionalInformation());
    }

    public function testGetAfterSetData(): void
    {
        $syncOrderHistory = $this->instantiateTestObject([]);

        // Default values
        $this->assertNull($syncOrderHistory->getEntityId());
        $this->assertNull($syncOrderHistory->getSyncOrderId());
        $this->assertNull($syncOrderHistory->getTimestamp());
        $this->assertNull($syncOrderHistory->getAction());
        $this->assertNull($syncOrderHistory->getVia());
        $this->assertNull($syncOrderHistory->getResult());
        $this->assertNull($syncOrderHistory->getAdditionalInformation());

        $syncOrderHistory->setData([
            'entity_id' => '1',
            'sync_order_id' => '42',
            'timestamp' => '2000-01-01 00:00:00',
            'action' => 'queue',
            'via' => 'PHPUnit',
            'result' => 'success',
            'additional_information' => ['foo' => 'bar'],
        ]);

        // After update
        $this->assertSame(1, $syncOrderHistory->getEntityId());
        $this->assertSame(42, $syncOrderHistory->getSyncOrderId());
        $this->assertSame('2000-01-01 00:00:00', $syncOrderHistory->getTimestamp());
        $this->assertSame('queue', $syncOrderHistory->getAction());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $this->assertSame('success', $syncOrderHistory->getResult());
        $this->assertSame(['foo' => 'bar'], $syncOrderHistory->getAdditionalInformation());
    }
}

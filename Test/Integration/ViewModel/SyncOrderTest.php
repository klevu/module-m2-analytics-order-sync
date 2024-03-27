<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\ViewModel;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\ViewModel\SyncOrder as SyncOrderViewModel;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderViewModel instantiateTestObject(?array $arguments = null)
 */
class SyncOrderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
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

        $this->implementationFqcn = SyncOrderViewModel::class;
        $this->interfaceFqcn = ArgumentInterface::class;

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

    public function testGetSyncOrderRecordForOrderId_OrderNotExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertNull(
            $viewModel->getSyncOrderRecordForOrderId(-1),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetSyncOrderRecordForOrderId_OrderExists(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $viewModel = $this->instantiateTestObject();

        $syncOrderRecord = $viewModel->getSyncOrderRecordForOrderId($orderId);

        $this->assertInstanceOf(SyncOrderInterface::class, $syncOrderRecord);
        $this->assertNotEmpty($syncOrderRecord->getEntityId());
        $this->assertSame($orderId, $syncOrderRecord->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrderRecord->getStatus());
        $this->assertSame(0, $syncOrderRecord->getAttempts());
    }

    public function testGetStatusForDisplay_StatusNotExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('foo'),
            $viewModel->getStatusForDisplay('foo'),
        );
    }

    public function testGetStatusForDisplay_StatusExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('Not Registered'),
            $viewModel->getStatusForDisplay(Statuses::NOT_REGISTERED->value),
        );
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\ViewModel;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\ViewModel\SyncOrderHistory as SyncOrderHistoryViewModel;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderHistoryViewModel instantiateTestObject(?array $arguments = null)
 */
class SyncOrderHistoryTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
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

        $this->implementationFqcn = SyncOrderHistoryViewModel::class;
        $this->interfaceFqcn = ArgumentInterface::class;

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

    public function testGetSyncOrderHistoryRecordsForSyncOrderId_SyncOrderNotExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertSame(
            [],
            $viewModel->getSyncOrderHistoryRecordsForSyncOrderId(-1),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetSyncOrderHistoryRecordsForSyncId_SyncOrderExists(): void
    {
        $order = $this->getOrderFixture(true);
        $syncOrder = $this->syncOrderRepository->getByOrderId(
            (int)$order->getEntityId(),
        );

        $viewModel = $this->instantiateTestObject();

        $syncOrderHistoryRecords = $viewModel->getSyncOrderHistoryRecordsForSyncOrderId(
            $syncOrder->getEntityId(),
        );
        $this->assertNotEmpty($syncOrderHistoryRecords);
        $syncOrderHistoryRecord = current($syncOrderHistoryRecords);

        $this->assertNotEmpty($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertGreaterThanOrEqual(
            time() - 60,
            strtotime($syncOrderHistoryRecord->getTimestamp()),
        );
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistoryRecord->getAction());
        $this->assertNotEmpty($syncOrderHistoryRecord->getVia());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryRecord->getResult());
        $this->assertNotEmpty($syncOrderHistoryRecord->getAdditionalInformation());
    }

    public function testGetActionForDisplay_ActionNotExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('foo'),
            $viewModel->getActionForDisplay('foo'),
        );
    }

    public function testGetActionForDisplay_ActionExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('Processing Started'),
            $viewModel->getActionForDisplay(Actions::PROCESS_START->value),
        );
    }

    public function testGetResultForDisplay_ResultNotExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('foo'),
            $viewModel->getResultForDisplay('foo'),
        );
    }

    public function testGetResultForDisplay_ResultExists(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertEquals(
            new Phrase('No Action'),
            $viewModel->getResultForDisplay(Results::NOOP->value),
        );
    }

    public function testGetAdditionalInformationForDisplay_Null(): void
    {
        $viewModel = $this->instantiateTestObject();

        $this->assertSame(
            '',
            $viewModel->getAdditionalInformationForDisplay(null),
        );
    }

    public function testGetAdditionalInformationForDisplay_Array(): void
    {
        $viewModel = $this->instantiateTestObject();

        $additionalInformation = ['foo' => ['bar', 'baz']];
        $this->assertSame(
            json_encode($additionalInformation, JSON_PRETTY_PRINT),
            $viewModel->getAdditionalInformationForDisplay($additionalInformation),
        );
    }
}

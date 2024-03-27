<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Action;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Service\Action\MarkOrderAsProcessing;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessingActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method MarkOrderAsProcessing instantiateTestObject(?array $arguments = null)
 * @method MarkOrderAsProcessing instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MarkOrderAsProcessingTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
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

        $this->implementationFqcn = MarkOrderAsProcessing::class;
        $this->interfaceFqcn = MarkOrderAsProcessingActionInterface::class;

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

    public function testExecute_OrderNotExists(): void
    {
        $markOrderAsProcessingAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessingAction->execute(
            orderId: -1,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getSyncOrderRecord());
        $this->assertNull($result->getSyncOrderHistoryRecord());
        $this->assertNotEmpty($result->getMessages());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessingAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertNotNull($syncOrderRecord->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrderRecord->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrderRecord->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame(1, $syncOrderRecord->getAttempts());

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNotNull($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrderRecord->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistoryRecord->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryRecord->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertSame('PHPUnit', $syncOrderHistoryRecord->getVia());

        $additionalInformation = $syncOrderHistoryRecord->getAdditionalInformation();
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
        $this->assertArrayHasKey('original_status', $additionalInformation);
        $this->assertSame('', $additionalInformation['original_status']);
        $this->assertArrayHasKey('new_status', $additionalInformation);
        $this->assertSame(Statuses::PROCESSING->value, $additionalInformation['new_status']);
        $this->assertArrayHasKey('order_id', $additionalInformation);
        $this->assertEquals($order->getEntityId(), $additionalInformation['order_id']);
        $this->assertArrayHasKey('store_id', $additionalInformation);
        $this->assertEquals($order->getStoreId(), $additionalInformation['store_id']);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInQueuedStatus(): void
    {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessingAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertNotNull($syncOrderRecord->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrderRecord->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrderRecord->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame(1, $syncOrderRecord->getAttempts());

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNotNull($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrderRecord->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistoryRecord->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryRecord->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertSame('PHPUnit', $syncOrderHistoryRecord->getVia());

        $additionalInformation = $syncOrderHistoryRecord->getAdditionalInformation();
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
        $this->assertArrayHasKey('original_status', $additionalInformation);
        $this->assertSame(Statuses::QUEUED->value, $additionalInformation['original_status']);
        $this->assertArrayHasKey('new_status', $additionalInformation);
        $this->assertSame(Statuses::PROCESSING->value, $additionalInformation['new_status']);
        $this->assertArrayHasKey('order_id', $additionalInformation);
        $this->assertEquals($order->getEntityId(), $additionalInformation['order_id']);
        $this->assertArrayHasKey('store_id', $additionalInformation);
        $this->assertEquals($order->getStoreId(), $additionalInformation['store_id']);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInProcessingStatus(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertFalse($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertSame($syncOrder->getEntityId(), $syncOrderRecord->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrderRecord->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrderRecord->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame(1, $syncOrderRecord->getAttempts());

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNotNull($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrderRecord->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistoryRecord->getAction());
        $this->assertSame(Results::NOOP->value, $syncOrderHistoryRecord->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertSame('PHPUnit', $syncOrderHistoryRecord->getVia());

        $additionalInformation = $syncOrderHistoryRecord->getAdditionalInformation();
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
        $this->assertArrayHasKey('original_status', $additionalInformation);
        $this->assertSame(Statuses::PROCESSING->value, $additionalInformation['original_status']);
        $this->assertArrayHasKey('order_id', $additionalInformation);
        $this->assertEquals($order->getEntityId(), $additionalInformation['order_id']);
        $this->assertArrayHasKey('store_id', $additionalInformation);
        $this->assertEquals($order->getStoreId(), $additionalInformation['store_id']);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInProcessedStatus(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertNotNull($syncOrderRecord->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrderRecord->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrderRecord->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame(2, $syncOrderRecord->getAttempts());

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNotNull($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrderRecord->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistoryRecord->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryRecord->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertSame('PHPUnit', $syncOrderHistoryRecord->getVia());

        $additionalInformation = $syncOrderHistoryRecord->getAdditionalInformation();
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
        $this->assertArrayHasKey('original_status', $additionalInformation);
        $this->assertSame(Statuses::SYNCED->value, $additionalInformation['original_status']);
        $this->assertArrayHasKey('new_status', $additionalInformation);
        $this->assertSame(Statuses::PROCESSING->value, $additionalInformation['new_status']);
        $this->assertArrayHasKey('order_id', $additionalInformation);
        $this->assertEquals($order->getEntityId(), $additionalInformation['order_id']);
        $this->assertArrayHasKey('store_id', $additionalInformation);
        $this->assertEquals($order->getStoreId(), $additionalInformation['store_id']);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_ExceptionOnSyncOrderSave(): void
    {
        $mockSyncOrderRepository = $this->getMockBuilder(SyncOrderRepositoryInterface::class)
            ->getMock();
        $mockSyncOrderRepository->method('save')
            ->willThrowException(new CouldNotSaveException(__('Test Exception Message')));

        $order = $this->getOrderFixture(false);

        $markOrderAsProcessingAction = $this->instantiateTestObject([
            'syncOrderRepository' => $mockSyncOrderRepository,
        ]);

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertFalse($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertNull($syncOrderRecord->getEntityId());

        $this->assertNull($result->getSyncOrderHistoryRecord());

        $this->assertContains('Test Exception Message', $result->getMessages());
    }

    public function testExecute_ExceptionOnSyncOrderHistorySave(): void
    {
        $mockSyncOrderHistoryRepository = $this->getMockBuilder(SyncOrderHistoryRepositoryInterface::class)
            ->getMock();
        $mockSyncOrderHistoryRepository->method('save')
            ->willThrowException(new CouldNotSaveException(__('Test Exception Message')));

        $order = $this->getOrderFixture(false);

        $markOrderAsProcessingAction = $this->instantiateTestObject([
            'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
        ]);

        $result = $markOrderAsProcessingAction->execute(
            orderId: (int)$order->getEntityId(),
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertNotNull($syncOrderRecord);
        $this->assertNotNull($syncOrderRecord->getEntityId());

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNull($syncOrderHistoryRecord->getEntityId());

        $this->assertContains('Test Exception Message', $result->getMessages());
    }
}

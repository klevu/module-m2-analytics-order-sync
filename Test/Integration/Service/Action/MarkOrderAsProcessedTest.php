<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Action;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Service\Action\MarkOrderAsProcessed;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method MarkOrderAsProcessed instantiateTestObject(?array $arguments = null)
 * @method MarkOrderAsProcessed instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MarkOrderAsProcessedTest extends TestCase
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

        $this->implementationFqcn = MarkOrderAsProcessed::class;
        $this->interfaceFqcn = MarkOrderAsProcessedActionInterface::class;

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
    public function testExecute_OrderNotExists_Synced(): void
    {
        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: -1,
            resultStatus: Statuses::SYNCED->value,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getSyncOrderRecord());
        $this->assertNull($result->getSyncOrderHistoryRecord());
        $this->assertNotEmpty($result->getMessages());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderNotExists_Error(): void
    {
        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: -1,
            resultStatus: Statuses::SYNCED->value,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getSyncOrderRecord());
        $this->assertNull($result->getSyncOrderHistoryRecord());
        $this->assertNotEmpty($result->getMessages());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderNotExists_Partial(): void
    {
        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: -1,
            resultStatus: Statuses::PARTIAL->value,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getSyncOrderRecord());
        $this->assertNull($result->getSyncOrderHistoryRecord());
        $this->assertNotEmpty($result->getMessages());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists_Synced(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: '',
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists_Error(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: '',
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists_Partial(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: '',
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInQueuedStatus_Synced(): void
    {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::QUEUED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInQueuedStatus_Error(): void
    {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::QUEUED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInQueuedStatus_Partial(): void
    {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: Statuses::QUEUED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInProcessingStatus_Synced(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(2);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 2,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::PROCESSING->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInProcessingStatus_Error(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(2);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 2,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::PROCESSING->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInProcessingStatus_Partial(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(2);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 2,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: Statuses::PROCESSING->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInSyncedStatus_Synced(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertFalse($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::SYNCED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
            expectedResult: Results::NOOP,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInSyncedStatus_Error(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::SYNCED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInSyncedStatus_Partial(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: Statuses::SYNCED->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    public function testExecute_OrderInErrorStatus_Synced(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::ERROR->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::ERROR->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInErrorStatus_Error(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::ERROR->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertFalse($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::ERROR->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
            expectedResult: Results::NOOP,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInErrorStatus_Partial(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::ERROR->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: Statuses::ERROR->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    public function testExecute_OrderInPartialStatus_Synced(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PARTIAL->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::SYNCED,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::PARTIAL->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInPartialStatus_Error(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PARTIAL->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::ERROR->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertTrue($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::ERROR,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::PROCESS_END,
            expectedOriginalStatusValue: Statuses::PARTIAL->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderInPartialStatus_Partial(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus(Statuses::PARTIAL->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::PARTIAL->value,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $this->assertFalse($result->isSuccess());

        $syncOrderRecord = $result->getSyncOrderRecord();
        $this->assertSyncOrderRecord(
            syncOrderRecord: $syncOrderRecord,
            order: $order,
            expectedStatus: Statuses::PARTIAL,
            expectedAttempts: 1,
        );

        $syncOrderHistoryRecord = $result->getSyncOrderHistoryRecord();
        $this->assertSyncOrderHistoryRecord(
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            syncOrderRecord: $syncOrderRecord,
            expectedAction: Actions::QUEUE,
            expectedOriginalStatusValue: Statuses::PARTIAL->value,
            expectedAdditionalInformation: ['foo' => 'bar'],
            expectedResult: Results::NOOP,
        );
    }

    /**
     * @return string[][]
     */
    public static function dataProvider_testExecute_InvalidResultStatus(): array
    {
        $return = [
            ['foo'],
            [''],
            ['ERROR'],
        ];

        $allowedStatuses = [
            Statuses::SYNCED,
            Statuses::ERROR,
            Statuses::PARTIAL,
        ];
        foreach (Statuses::cases() as $status) {
            if (in_array($status, $allowedStatuses, true)) {
                continue;
            }

            $return[] = [$status->value];
        }

        return $return;
    }

    /**
     * @magentoAppIsolation enabled
     * @dataProvider dataProvider_testExecute_InvalidResultStatus
     */
    public function testExecute_InvalidResultStatus(
        string $resultStatus,
    ): void {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedAction = $this->instantiateTestObject();

        $this->expectException(\InvalidArgumentException::class);
        $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: $resultStatus,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );
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

        $markOrderAsProcessedAction = $this->instantiateTestObject([
            'syncOrderRepository' => $mockSyncOrderRepository,
        ]);

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
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

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_ExceptionOnSyncOrderHistorySave(): void
    {
        $mockSyncOrderHistoryRepository = $this->getMockBuilder(SyncOrderHistoryRepositoryInterface::class)
            ->getMock();
        $mockSyncOrderHistoryRepository->method('save')
            ->willThrowException(new CouldNotSaveException(__('Test Exception Message')));

        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedAction = $this->instantiateTestObject([
            'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
        ]);

        $result = $markOrderAsProcessedAction->execute(
            orderId: (int)$order->getEntityId(),
            resultStatus: Statuses::SYNCED->value,
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

    /**
     * @param mixed $syncOrderRecord
     * @param OrderInterface $order
     * @param Statuses $expectedStatus
     * @param int $expectedAttempts
     * @return void
     */
    private function assertSyncOrderRecord(
        mixed $syncOrderRecord,
        OrderInterface $order,
        Statuses $expectedStatus,
        int $expectedAttempts,
    ): void {
        $this->assertNotNull($syncOrderRecord);
        $this->assertNotNull($syncOrderRecord->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrderRecord->getOrderId());
        $this->assertSame($expectedStatus->value, $syncOrderRecord->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrderRecord->getStoreId());
        $this->assertSame($expectedAttempts, $syncOrderRecord->getAttempts());
    }

    /**
     * @param mixed $syncOrderHistoryRecord
     * @param SyncOrderInterface $syncOrderRecord
     * @param Actions $expectedAction
     * @param string|null $expectedOriginalStatusValue
     * @param mixed[] $expectedAdditionalInformation
     * @param Results $expectedResult
     * @return void
     */
    private function assertSyncOrderHistoryRecord(
        mixed $syncOrderHistoryRecord,
        SyncOrderInterface $syncOrderRecord,
        Actions $expectedAction,
        ?string $expectedOriginalStatusValue,
        array $expectedAdditionalInformation,
        Results $expectedResult = Results::SUCCESS,
    ): void {
        $this->assertNotNull($syncOrderHistoryRecord);
        $this->assertNotNull($syncOrderHistoryRecord->getEntityId());
        $this->assertSame($syncOrderRecord->getEntityId(), $syncOrderHistoryRecord->getSyncOrderId());
        $this->assertSame($expectedAction->value, $syncOrderHistoryRecord->getAction());
        $this->assertSame($expectedResult->value, $syncOrderHistoryRecord->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistoryRecord->getTimestamp()));
        $this->assertSame('PHPUnit', $syncOrderHistoryRecord->getVia());

        $additionalInformation = $syncOrderHistoryRecord->getAdditionalInformation();
        foreach ($expectedAdditionalInformation as $key => $value) {
            $this->assertArrayHasKey($key, $additionalInformation);
            $this->assertSame($value, $additionalInformation[$key]);
        }

        if (null !== $expectedOriginalStatusValue) {
            $this->assertArrayHasKey('original_status', $additionalInformation);
            $this->assertSame($expectedOriginalStatusValue, $additionalInformation['original_status']);
        }
        if ($syncOrderRecord->getStatus() !== $expectedOriginalStatusValue) {
            $this->assertArrayHasKey('new_status', $additionalInformation);
            $this->assertSame($syncOrderRecord->getStatus(), $additionalInformation['new_status']);
        }
        $this->assertArrayHasKey('order_id', $additionalInformation);
        $this->assertEquals($syncOrderRecord->getOrderId(), $additionalInformation['order_id']);
        $this->assertArrayHasKey('store_id', $additionalInformation);
        $this->assertEquals($syncOrderRecord->getStoreId(), $additionalInformation['store_id']);
    }
}

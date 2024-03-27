<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Observer\Sales\Order;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Observer\Sales\Order\QueueOrderForSync;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Sales\InvoiceBuilder;
use TddWizard\Fixtures\Sales\ShipmentBuilder;

/**
 * @method QueueOrderForSync instantiateTestObject(?array $arguments = null)
 */
class QueueOrderForSyncTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|ObjectManager|null
     */
    private ObjectManagerInterface|ObjectManager|null $objectManager = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var SyncOrderHistoryRepositoryInterface|null
     */
    private ?SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = QueueOrderForSync::class;
        $this->interfaceFqcn = ObserverInterface::class;

        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);

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

    public function testInvalidObserverEvent(): void
    {
        $event = $this->objectManager->create(DataObject::class, [
            'data' => [
                'order' => (object)['foo' => 'bar'],
            ],
        ]);
        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class);
        $observer->setEvent($event);

        $mockLogger = $this->getMockLogger();
        $mockLogger->expects($this->once())->method('warning');

        $queueOrderForSync = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);

        $initialSyncOrdersResult = $this->syncOrderRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $initialCount = $initialSyncOrdersResult->getTotalCount();

        $queueOrderForSync->execute($observer);

        $finalSyncOrdersResult = $this->syncOrderRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame($initialCount, $finalSyncOrdersResult->getTotalCount());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testNewOrder_SyncEnabled(): void
    {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());

        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(0, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistory->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistory->getTimestamp()));
        $this->assertSame('Order save event', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testNewOrder_SyncDisabled(): void
    {
        $order = $this->getOrderFixture(false);

        $this->expectException(NoSuchEntityException::class);
        $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testUpdateOrder_SyncOrderExists(): void
    {
        /** @var Order&OrderInterface $order */
        $order = $this->getOrderFixture(true);
        ShipmentBuilder::forOrder($order);
        InvoiceBuilder::forOrder($order);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());

        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame((int)$order->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(0, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        $this->assertCount(1, $syncOrderHistoryResults->getItems());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertGreaterThan(time() - 60, strtotime($syncOrderHistory->getTimestamp()));
        $this->assertLessThanOrEqual(time(), strtotime($syncOrderHistory->getTimestamp()));
        $this->assertSame('Order save event', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testUpdateOrder_SyncOrderNotExists(): void
    {
        /** @var Order&OrderInterface $order */
        $order = $this->getOrderFixture(false);
        ShipmentBuilder::forOrder($order);
        InvoiceBuilder::forOrder($order);

        $this->expectException(NoSuchEntityException::class);
        $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testQueueOrderThrowsException(): void
    {
        $mockQueueOrderForSyncAction = $this->getMockBuilder(QueueOrderForSyncActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockQueueOrderForSyncAction->method('execute')
            ->willThrowException(new \Exception('Test Exception Message'));

        $mockLogger = $this->getMockLogger();
        $mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Encountered error queuing order record for sync: {error}',
                $this->callback(function (?array $context): bool {
                    $this->assertIsArray($context);

                    $this->assertArrayHasKey('error', $context);
                    $this->assertSame('Test Exception Message', $context['error']);

                    $this->assertArrayHasKey('orderId', $context);

                    return true;
                }),
            );

        $queueOrderForSync = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
        ]);
        $this->objectManager->addSharedInstance(
            $queueOrderForSync,
            QueueOrderForSync::class,
        );

        $this->getOrderFixture(true);
    }

    /**
     * @return LoggerInterface&MockObject
     */
    private function getMockLogger(): LoggerInterface&MockObject
    {
        return $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Cron\RequeueStuckOrders;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\RequeueStuckOrdersServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method RequeueStuckOrders instantiateTestObject(?array $arguments)
 * @magentoDbIsolation disabled
 */
class RequeueStuckOrdersTest extends TestCase
{
    use ObjectInstantiationTrait;
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

        $this->implementationFqcn = RequeueStuckOrders::class;

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

    public function testObjectInstantiation(): void
    {
        $this->assertInstanceOf(
            RequeueStuckOrders::class,
            $this->objectManager->get(RequeueStuckOrders::class),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_StuckOrderThresholdNotSet(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '',
        );

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting requeue of stuck orders', $message),
                        2 => $this->assertSame('Requeue of stuck orders complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                'warning',
                'ThresholdInMinutes value must be greater than 0; Received "0"',
            );

        $order = $this->getOrderFixture(true);
        $this->createSyncOrderFixturesForOrderId((int)$order->getEntityId());

        $requeueStuckOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $requeueStuckOrdersCron->execute();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderWithinThreshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '7200',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '7200',
        );

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting requeue of stuck orders', $message),
                        2 => $this->assertSame('Requeue of stuck orders complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );

        $order = $this->getOrderFixture(true);
        $this->createSyncOrderFixturesForOrderId((int)$order->getEntityId());

        $requeueStuckOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $requeueStuckOrdersCron->execute();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderOutwithThreshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '15',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '15',
        );

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting requeue of stuck orders', $message),
                        2 => $this->assertSame('Requeue of stuck orders complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );

        $order = $this->getOrderFixture(true);
        $this->createSyncOrderFixturesForOrderId((int)$order->getEntityId());

        $requeueStuckOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $requeueStuckOrdersCron->execute();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertSame(Statuses::RETRY->value, $syncOrder->getStatus());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_VIA,
            value: 'Cron: requeue_stuck_orders',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_RESULT,
            value: Results::SUCCESS->value,
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $this->assertSame(1, $syncOrderHistoryResult->getTotalCount());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_QueueOrderThrowsException(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '15',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES,
            value: '15',
        );
        $order = $this->getOrderFixture(true);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting requeue of stuck orders', $message),
                        2 => $this->assertSame('Requeue of stuck orders complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                'warning',
                'Failed to requeue stuck order #' . $order->getEntityId() . ': Test Exception Message',
            );

        $mockQueueOrderForSyncAction = $this->getMockBuilder(QueueOrderForSyncActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockQueueOrderForSyncAction->method('execute')
            ->willThrowException(new LocalizedException(
                __('Test Exception Message'),
            ));

        $this->createSyncOrderFixturesForOrderId((int)$order->getEntityId());

        $requeueStuckOrdersService = $this->objectManager->create(
            RequeueStuckOrdersServiceInterface::class,
            [
                'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
            ],
        );

        $requeueStuckOrdersCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'requeueStuckOrdersService' => $requeueStuckOrdersService,
        ]);
        $requeueStuckOrdersCron->execute();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_VIA,
            value: 'Cron: requeue_stuck_orders',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_RESULT,
            value: Results::SUCCESS->value,
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $this->assertSame(0, $syncOrderHistoryResult->getTotalCount());
    }

    /**
     * @param int $orderId
     * @return void
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function createSyncOrderFixturesForOrderId(int $orderId): void
    {
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        /** @var SyncOrderHistory $syncOrderHistory */
        $syncOrderHistory = $this->objectManager->create(SyncOrderHistoryInterface::class);
        $syncOrderHistory->setSyncOrderId($syncOrder->getEntityId());
        $syncOrderHistory->setAction(Actions::PROCESS_START->value);
        $syncOrderHistory->setResult(Results::SUCCESS->value);
        $syncOrderHistory->setVia('PHPUnit');
        $this->syncOrderHistoryRepository->save($syncOrderHistory);

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: $orderId,
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertGreaterThanOrEqual(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistoryItem */
        foreach ($syncOrderHistoryResults->getItems() as $syncOrderHistoryItem) {
            $syncOrderHistoryItem->setTimestamp(date('Y-m-d H:i:s', time() - 3600));
            $this->syncOrderHistoryRepository->save($syncOrderHistoryItem);
        }
    }

    /**
     * @return MockObject&LoggerInterface
     */
    private function getMockLogger(): MockObject&LoggerInterface
    {
        return $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

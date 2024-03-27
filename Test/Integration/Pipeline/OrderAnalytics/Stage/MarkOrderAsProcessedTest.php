<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Pipeline\OrderAnalytics\Stage;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Pipeline\OrderAnalytics\Stage\MarkOrderAsProcessed;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ProcessFailedOrderSyncActionInterface;
use Klevu\Pipelines\Exception\Pipeline\InvalidPipelineArgumentsException;
use Klevu\Pipelines\Exception\Pipeline\InvalidPipelinePayloadException;
use Klevu\Pipelines\Exception\PipelineException;
use Klevu\Pipelines\Pipeline\Context;
use Klevu\Pipelines\Pipeline\Pipeline;
use Klevu\Pipelines\Pipeline\PipelineInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method MarkOrderAsProcessed instantiateTestObject(?array $arguments = null)
 */
class MarkOrderAsProcessedTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestFactoryGenerationTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
    /**
     * @var SyncOrderHistoryRepositoryInterface|null
     */
    private ?SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MarkOrderAsProcessed::class;
        $this->interfaceFqcn = PipelineInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);

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
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_PayloadNotNumeric(): array
    {
        return [
            ['foo'],
            [3.14],
            [null],
            [true],
            [[42]],
            [(object)['payload' => 42]],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_PayloadNotNumeric
     */
    public function testExecute_PayloadNotNumeric(mixed $payload): void
    {
        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $this->expectException(InvalidPipelinePayloadException::class);
        $markOrderAsProcessedStage->execute(
            payload: $payload,
            context: new Context([]),
        );
    }

    public function testExecute_ResultArgNotSet(): void
    {
        $markOrderAsProcessedStage = $this->instantiateTestObject([
            'orderRepository' => $this->getMockOrderRepository(1),
        ]);

        $this->expectException(InvalidPipelineArgumentsException::class);

        $markOrderAsProcessedStage->execute(
            payload: 1,
            context: new Context([]),
        );
    }

    /**
     * @testWith [true]
     *           [false]
     */
    public function testExecute_SetArgs(
        bool $result,
    ): void {
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessAction();
        $mockProcessFailedOrderSyncAction = $this->getMockProcessFailedOrderSyncAction();
        if ($result) {
            $mockMarkOrderAsProcessedAction->expects($this->once())
                ->method('execute');
            $mockProcessFailedOrderSyncAction->expects($this->never())
                ->method('execute');
        } else {
            $mockMarkOrderAsProcessedAction->expects($this->never())
                ->method('execute');
            $mockProcessFailedOrderSyncAction->expects($this->once())
                ->method('execute');
        }

        $markOrderAsProcessedStage = $this->instantiateTestObject([
            'orderRepository' => $this->getMockOrderRepository(1),
            'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
            'processFailedOrderSyncAction' => $mockProcessFailedOrderSyncAction,
        ]);
        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $result,
        ]);
        $markOrderAsProcessedStage->execute(
            payload: 1,
        );
    }

    /**
     * @testWith [true]
     *           [false]
     */
    public function testExecute_SetArgs_Construct(
        bool $result,
    ): void {
        $mockMarkOrderAsProcessedAction = $this->getMockMarkOrderAsProcessAction();
        $mockProcessFailedOrderSyncAction = $this->getMockProcessFailedOrderSyncAction();
        if ($result) {
            $mockMarkOrderAsProcessedAction->expects($this->once())
                ->method('execute');
            $mockProcessFailedOrderSyncAction->expects($this->never())
                ->method('execute');
        } else {
            $mockMarkOrderAsProcessedAction->expects($this->never())
                ->method('execute');
            $mockProcessFailedOrderSyncAction->expects($this->once())
                ->method('execute');
        }

        $markOrderAsProcessedStage = $this->instantiateTestObject([
            'orderRepository' => $this->getMockOrderRepository(1),
            'markOrderAsProcessedAction' => $mockMarkOrderAsProcessedAction,
            'processFailedOrderSyncAction' => $mockProcessFailedOrderSyncAction,
            'args' => [
                MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $result,
            ],
        ]);
        $markOrderAsProcessedStage->execute(
            payload: 1,
        );
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_ResultArgNotBoolean(): array
    {
        return [
            ['foo'],
            [42],
            [3.14],
            [null],
            [[42]],
            [(object)['payload' => 42]],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_ResultArgNotBoolean
     * @magentoAppIsolation enabled
     */
    public function testExecute_ResultArgNotBoolean(mixed $result): void
    {
        $markOrderAsProcessedStage = $this->instantiateTestObject([
            'orderRepository' => $this->getMockOrderRepository(1),
        ]);

        $this->expectException(InvalidPipelineArgumentsException::class);

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $result,
        ]);
        $markOrderAsProcessedStage->execute(
            payload: 1,
            context: new Context([]),
        );
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_ResultArgNotBoolean_Extraction(): array
    {
        return [
            [
                '$foo',
                new Context(['foo' => true]),
            ],
            [
                '$getFoo()',
                new Context(['foo' => true]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => 'baz'],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => 1],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['baz' => true],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => 0],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => 3.14],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => null],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => [true]],
                ]),
            ],
            [
                '$foo::bar',
                new Context([
                    'foo' => ['bar' => (object)[true]],
                ]),
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_ResultArgNotBoolean_Extraction
     */
    public function testExecute_ResultArgNotBoolean_Extraction(
        string $resultAccessor,
        Context $context,
    ): void {
        $markOrderAsProcessedStage = $this->instantiateTestObject([
            'orderRepository' => $this->getMockOrderRepository(1),
        ]);

        $this->expectException(InvalidPipelineArgumentsException::class);

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $resultAccessor,
        ]);
        $markOrderAsProcessedStage->execute(
            payload: 1,
            context: $context,
        );
    }

    public function testExecute_OrderNotExists(): void
    {
        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $this->expectException(InvalidPipelinePayloadException::class);
        $markOrderAsProcessedStage->execute(
            payload: -1,
            context: new Context([]),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_Success_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => true,
        ]);
        $processedPayload = $markOrderAsProcessedStage->execute(
            payload: $order->getEntityId(),
            context: new Context([]),
        );

        $this->assertSame($order->getEntityId(), $processedPayload);

        $orderId = (int)$order->getEntityId();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResult->getTotalCount());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResult->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_END->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_Fail_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);

        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => false,
        ]);
        $processedPayload = $markOrderAsProcessedStage->execute(
            payload: $order->getEntityId(),
            context: new Context([]),
        );

        $this->assertSame($order->getEntityId(), $processedPayload);

        $orderId = (int)$order->getEntityId();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(Statuses::RETRY->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(2, $syncOrderHistoryResult->getTotalCount());

        /** @var SyncOrderHistoryInterface[] $syncOrderHistoryItems */
        $syncOrderHistoryItems = $syncOrderHistoryResult->getItems();

        $syncOrderHistoryProcessing = array_shift($syncOrderHistoryItems);
        $this->assertNotNull($syncOrderHistoryProcessing->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistoryProcessing->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistoryProcessing->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryProcessing->getResult());

        $syncOrderHistoryProcessed = array_shift($syncOrderHistoryItems);
        $this->assertNotNull($syncOrderHistoryProcessed->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistoryProcessed->getSyncOrderId());
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistoryProcessed->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistoryProcessed->getResult());
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_Success(): array
    {
        return [
            [
                true,
                new Context([]),
            ],
            [
                '$sync::result',
                new Context([
                    'sync' => [
                        'result' => true,
                    ],
                ]),
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_Success
     * @magentoAppIsolation enabled
     */
    public function testExecute_Success(
        mixed $resultArgument,
        Context $context,
    ): void {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $resultArgument,
        ]);
        $processedPayload = $markOrderAsProcessedStage->execute(
            payload: $order->getEntityId(),
            context: $context,
        );

        $this->assertSame($order->getEntityId(), $processedPayload);

        $orderId = (int)$order->getEntityId();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
            conditionType: 'neq',
        );
        $syncOrderHistoryResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResult->getTotalCount());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResult->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_END->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_Fail(): array
    {
        return [
            [
                false,
                new Context([]),
            ],
            [
                '$sync::result',
                new Context([
                    'sync' => [
                        'result' => false,
                    ],
                ]),
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_Fail
     * @magentoAppIsolation enabled
     */
    public function testExecute_Fail_Retry(
        mixed $resultArgument,
        Context $context,
    ): void {
        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $resultArgument,
        ]);
        $processedPayload = $markOrderAsProcessedStage->execute(
            payload: $order->getEntityId(),
            context: $context,
        );

        $this->assertSame($order->getEntityId(), $processedPayload);

        $orderId = (int)$order->getEntityId();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(Statuses::RETRY->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::PROCESS_START->value,
        );
        $syncOrderHistoryResultProcessStart = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResultProcessStart->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResultProcessStart->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
        );
        $syncOrderHistoryResultQueue = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(2, $syncOrderHistoryResultQueue->getTotalCount());

        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        foreach ($syncOrderHistoryResultQueue->getItems() as $syncOrderHistory) {
            $this->assertNotNull($syncOrderHistory->getEntityId());
            $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
            $this->assertSame(Actions::QUEUE->value, $syncOrderHistory->getAction());
            $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        }
    }

    /**
     * @dataProvider dataProvider_testExecute_Fail
     * @magentoAppIsolation enabled
     */
    public function testExecute_Fail_Error(
        mixed $resultArgument,
        Context $context,
    ): void {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS,
            value: 1,
        );

        $order = $this->getOrderFixture(true);

        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $markOrderAsProcessedStage->setArgs([
            MarkOrderAsProcessed::ARGUMENT_KEY_RESULT => $resultArgument,
        ]);
        $processedPayload = $markOrderAsProcessedStage->execute(
            payload: $order->getEntityId(),
            context: $context,
        );

        $this->assertSame($order->getEntityId(), $processedPayload);

        $orderId = (int)$order->getEntityId();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(Statuses::ERROR->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::PROCESS_START->value,
        );
        $syncOrderHistoryResultProcessStart = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResultProcessStart->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResultProcessStart->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
        );
        $syncOrderHistoryResultQueue = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResultQueue->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResultQueue->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::PROCESS_END->value,
        );
        $syncOrderHistoryResultProcessEnd = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResultProcessEnd->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResultProcessEnd->getItems());
        $this->assertNotNull($syncOrderHistory->getEntityId());
        $this->assertSame($syncOrder->getEntityId(), $syncOrderHistory->getSyncOrderId());
        $this->assertSame(Actions::PROCESS_END->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
    }

    public function testAddStages_Construct(): void
    {
        $this->expectException(PipelineException::class);
        $this->instantiateTestObject([
            'stages' => [
                new Pipeline(),
            ],
        ]);
    }

    public function testAddStage(): void
    {
        $markOrderAsProcessedStage = $this->instantiateTestObject();

        $this->expectException(PipelineException::class);
        $markOrderAsProcessedStage->addStage(new Pipeline());
    }

    /**
     * @param int $orderId
     * @return MockObject&OrderRepositoryInterface
     */
    private function getMockOrderRepository(int $orderId): MockObject&OrderRepositoryInterface
    {
        $mockOrder = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockOrder->method('getEntityId')
            ->willReturn((string)$orderId);

        $mockOrderRepository = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockOrderRepository->method('get')
            ->willReturn($mockOrder);

        return $mockOrderRepository;
    }

    /**
     * @return MockObject&MarkOrderAsProcessedActionInterface
     */
    private function getMockMarkOrderAsProcessAction(): MockObject&MarkOrderAsProcessedActionInterface
    {
        return $this->getMockBuilder(MarkOrderAsProcessedActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return MockObject&ProcessFailedOrderSyncActionInterface
     */
    private function getMockProcessFailedOrderSyncAction(): MockObject&ProcessFailedOrderSyncActionInterface
    {
        return $this->getMockBuilder(ProcessFailedOrderSyncActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

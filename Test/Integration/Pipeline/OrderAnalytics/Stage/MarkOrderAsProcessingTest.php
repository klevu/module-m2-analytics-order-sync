<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Pipeline\OrderAnalytics\Stage;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Pipeline\OrderAnalytics\Stage\MarkOrderAsProcessing;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
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
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method MarkOrderAsProcessing instantiateTestObject(?array $arguments = null)
 */
class MarkOrderAsProcessingTest extends TestCase
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

        $this->implementationFqcn = MarkOrderAsProcessing::class;
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
        $markOrderAsProcessingStage = $this->instantiateTestObject();

        $this->expectException(InvalidPipelinePayloadException::class);
        $markOrderAsProcessingStage->execute(
            payload: $payload,
            context: new Context([]),
        );
    }

    public function testExecute_OrderNotExists(): void
    {
        $markOrderAsProcessingStage = $this->instantiateTestObject();

        $this->expectException(InvalidPipelinePayloadException::class);
        $markOrderAsProcessingStage->execute(
            payload: -1,
            context: new Context([]),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists(): void
    {
        $order = $this->getOrderFixture(false);
        $orderId = (int)$order->getEntityId();

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
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
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderInQueuedStatus(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $this->syncOrderRepository->clearCache();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
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
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderInRetryStatus(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::RETRY->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $this->syncOrderRepository->clearCache();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
        $this->assertSame(2, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
            conditionType: 'neq',
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderInPartialStatus(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::PARTIAL->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $this->syncOrderRepository->clearCache();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
        $this->assertSame(2, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
            conditionType: 'neq',
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderInProcessingStatus(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::PROCESSING->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $this->syncOrderRepository->clearCache();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
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
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::NOOP->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderInSyncedStatus(): void
    {
        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus(Statuses::SYNCED->value);
        $syncOrder->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder);

        $markOrderAsProcessingStage = $this->instantiateTestObject();
        $result = $markOrderAsProcessingStage->execute(
            payload: $orderId,
            context: new Context([
                'system' => [
                    'via' => 'PHPUnit',
                ],
            ]));
        $this->assertSame($orderId, $result);

        $this->syncOrderRepository->clearCache();
        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame($orderId, $syncOrder->getOrderId());
        $this->assertSame(Statuses::PROCESSING->value, $syncOrder->getStatus());
        $this->assertSame(2, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_ACTION,
            value: Actions::QUEUE->value,
            conditionType: 'neq',
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::PROCESS_START->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
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
        $markOrderAsProcessingStage = $this->instantiateTestObject();

        $this->expectException(PipelineException::class);
        $markOrderAsProcessingStage->addStage(new Pipeline());
    }

    public function testSetArgs(): void
    {
        $this->expectNotToPerformAssertions();

        $markOrderAsProcessingStage = $this->instantiateTestObject();

        $markOrderAsProcessingStage->setArgs([
            'foo' => 'bar',
        ]);
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Action;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Exception\OrderNotValidException;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Service\Action\ProcessFailedOrderSync;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ProcessFailedOrderSyncActionInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method ProcessFailedOrderSync instantiateTestObject(?array $arguments = null)
 * @method ProcessFailedOrderSync instantiateTestObjectFromInterface(?array $arguments = null)
 */
class ProcessFailedOrderSyncTest extends TestCase
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
     * @var SortOrderBuilder|null
     */
    private ?SortOrderBuilder $sortOrderBuilder = null;
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

        $this->implementationFqcn = ProcessFailedOrderSync::class;
        $this->interfaceFqcn = ProcessFailedOrderSyncActionInterface::class;

        $this->sortOrderBuilder = $this->objectManager->get(SortOrderBuilder::class);
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
     * @magentoAppIsolation enabled
     */
    public function testExecute_UnsavedOrder(): void
    {
        $order = $this->getOrderFixture(false);
        if (!method_exists($order, 'unsetData')) {
            throw new \LogicException(sprintf(
                'Order of type %s does not contain method unsetData()',
                $order::class,
            ));
        }

        $testOrder = clone $order;
        $testOrder->unsetData('entity_id');

        $processFailedOrderSyncAction = $this->instantiateTestObject();

        $this->expectException(OrderNotValidException::class);
        $processFailedOrderSyncAction->execute($testOrder);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderNotExists(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS,
            value: 5,
        );

        $order = $this->getOrderFixture(false);
        $orderId = (int)$order->getEntityId();

        $processFailedOrderSyncAction = $this->instantiateTestObject();

        $processFailedOrderSyncAction->execute(
            order: $order,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);

        $this->assertSame(Statuses::RETRY->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $syncOrderHistory = $this->getMostRecentSyncOrderHistory(
            syncOrderId: $syncOrder->getEntityId(),
        );
        $this->assertSame(Actions::QUEUE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $additionalInformation = $syncOrderHistory->getAdditionalInformation();
        $this->assertArrayHasKey('reason', $additionalInformation);
        $this->assertSame(
            'Order requeued after failed sync attempt within configured threshold',
            $additionalInformation['reason'],
        );
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testExecute_SyncOrderQueued(): array
    {
        return [
            [
                Statuses::QUEUED,
                0,
                5,
                Statuses::RETRY,
                1,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::QUEUED,
                3,
                5,
                Statuses::RETRY,
                4,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::QUEUED,
                3,
                4,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::QUEUED,
                3,
                3,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::QUEUED,
                3,
                2,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::RETRY,
                0,
                5,
                Statuses::RETRY,
                1,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::RETRY,
                3,
                5,
                Statuses::RETRY,
                4,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::RETRY,
                3,
                4,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::RETRY,
                3,
                3,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::RETRY,
                3,
                2,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::PARTIAL,
                0,
                5,
                Statuses::RETRY,
                1,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::PARTIAL,
                3,
                5,
                Statuses::RETRY,
                4,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::PARTIAL,
                3,
                4,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::PARTIAL,
                3,
                3,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::PARTIAL,
                3,
                2,
                Statuses::ERROR,
                4,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],

            [
                Statuses::PROCESSING,
                0,
                5,
                Statuses::QUEUED, // Known anomalous behaviour; should not be replicable in real world
                0, // This is _why_ it assigns QUEUED not RETRY
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::PROCESSING,
                3,
                5,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::PROCESSING,
                3,
                4,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::PROCESSING,
                3,
                3,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::PROCESSING,
                3,
                2,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],

            [
                Statuses::SYNCED,
                0,
                5,
                Statuses::QUEUED, // Known anomalous behaviour; should not be replicable in real world
                0, // This is _why_ it assigns QUEUED not RETRY
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::SYNCED,
                3,
                5,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::SYNCED,
                3,
                4,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::SYNCED,
                3,
                3,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],
            [
                Statuses::SYNCED,
                3,
                2,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::SUCCESS,
            ],

            [
                Statuses::ERROR,
                0,
                5,
                Statuses::QUEUED, // Known anomalous behaviour; should not be replicable in real world
                0, // This is _why_ it assigns QUEUED not RETRY
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::ERROR,
                3,
                5,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::ERROR,
                3,
                4,
                Statuses::RETRY,
                3,
                Actions::QUEUE,
                Results::SUCCESS,
            ],
            [
                Statuses::ERROR,
                3,
                3,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::NOOP,
            ],
            [
                Statuses::ERROR,
                3,
                2,
                Statuses::ERROR,
                3,
                Actions::PROCESS_END,
                Results::NOOP,
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testExecute_SyncOrderQueued
     * @magentoAppIsolation enabled
     */
    public function testExecute(
        Statuses $syncOrderStatus,
        int $initialSyncOrderAttempts,
        int $configuredMaxAttempts,
        Statuses $expectedStatus,
        int $expectedSyncOrderAttempts,
        Actions $expectedAction,
        Results $expectedResult,
    ): void {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS,
            value: $configuredMaxAttempts,
        );

        $order = $this->getOrderFixture(true);
        $orderId = (int)$order->getEntityId();

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);
        $syncOrder->setStatus($syncOrderStatus->value);
        $syncOrder->setAttempts($initialSyncOrderAttempts);
        $this->syncOrderRepository->save($syncOrder);
        $this->syncOrderRepository->clearCache();

        $processFailedOrderSyncAction = $this->instantiateTestObject();

        $processFailedOrderSyncAction->execute(
            order: $order,
            via: 'PHPUnit',
            additionalInformation: [
                'foo' => 'bar',
            ],
        );

        $syncOrder = $this->syncOrderRepository->getByOrderId($orderId);

        $this->assertSame($expectedStatus->value, $syncOrder->getStatus());
        $this->assertSame($expectedSyncOrderAttempts, $syncOrder->getAttempts());

        $syncOrderHistory = $this->getMostRecentSyncOrderHistory(
            syncOrderId: $syncOrder->getEntityId(),
        );
        $this->assertSame($expectedAction->value, $syncOrderHistory->getAction());
        $this->assertSame($expectedResult->value, $syncOrderHistory->getResult());
        $this->assertSame('PHPUnit', $syncOrderHistory->getVia());
        $additionalInformation = $syncOrderHistory->getAdditionalInformation();
        if ($expectedStatus->canInitiateSync()) {
            $this->assertArrayHasKey('reason', $additionalInformation);
            $this->assertSame(
                'Order requeued after failed sync attempt within configured threshold',
                $additionalInformation['reason'],
            );
        }
        $this->assertArrayHasKey('foo', $additionalInformation);
        $this->assertSame('bar', $additionalInformation['foo']);
    }

    /**
     * @param int $syncOrderId
     * @return SyncOrderHistoryInterface
     */
    private function getMostRecentSyncOrderHistory(
        int $syncOrderId,
    ): SyncOrderHistoryInterface {
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrderId,
        );

        // Would like to sort by timestamp, but if the test runs quickly enough then
        //  the order save event and test fixture are created with the same value
        $this->sortOrderBuilder->setField(SyncOrderHistory::FIELD_ENTITY_ID);
        $this->sortOrderBuilder->setDescendingDirection();
        $this->searchCriteriaBuilder->setSortOrders([
            $this->sortOrderBuilder->create(),
        ]);

        $this->searchCriteriaBuilder->setCurrentPage(1);
        $this->searchCriteriaBuilder->setPageSize(1);

        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertGreaterThanOrEqual(1, $syncOrderHistoryResults->getTotalCount());

        $syncOrderHistoryResult = current($syncOrderHistoryResults->getItems());
        if (!($syncOrderHistoryResult instanceof SyncOrderHistoryInterface)) {
            throw new \LogicException(sprintf(
                'Sync order history results must return array of %s; received %s[]',
                SyncOrderHistoryInterface::class,
                get_debug_type($syncOrderHistoryResult),
            ));
        }

        return $syncOrderHistoryResult;
    }
}

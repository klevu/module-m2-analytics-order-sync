<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Cron\RemoveSyncedOrderHistory;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\RemoveSyncedOrderHistoryServiceInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
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
 * @method RemoveSyncedOrderHistory instantiateTestObject(?array $arguments)
 * @magentoDbIsolation disabled
 */
class RemoveSyncedOrderHistoryTest extends TestCase
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
     * @var SyncOrderHistoryInterfaceFactory|null
     */
    private ?SyncOrderHistoryInterfaceFactory $syncOrderHistoryFactory = null;
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

        $this->implementationFqcn = RemoveSyncedOrderHistory::class;

        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);
        $this->syncOrderHistoryFactory = $this->objectManager->get(SyncOrderHistoryInterfaceFactory::class);
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

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_OrderCleanupDisabled(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            value: '',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            value: '',
        );
        $processingSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::PROCESSING);
        $syncedSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::SYNCED);
        $errorSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::ERROR);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting clean-up of synced order history', $message),
                        2 => $this->assertSame('Order history clean-up disabled', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );

        $removeSyncedOrderHistoryCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $removeSyncedOrderHistoryCron->execute();

        $threshold = 0;
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: $threshold,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: $threshold,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: $threshold,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SuccessHistoryThreshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            value: '',
        );
        $processingSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::PROCESSING);
        $syncedSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::SYNCED);
        $errorSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::ERROR);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting clean-up of synced order history', $message),
                        2 => $this->assertSame('Synced order history clean-up complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );
        $mockLogger->expects($this->once())
            ->method('debug')
            ->with('Removed {successCount} history items for orders in status {syncStatus}. ');

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );

        $removeSyncedOrderHistoryCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $removeSyncedOrderHistoryCron->execute();

        $threshold = time() - (2 * 86400);
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 2,
            threshold: $threshold,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_ErrorHistoryThreshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            value: '',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        $processingSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::PROCESSING);
        $syncedSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::SYNCED);
        $errorSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::ERROR);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting clean-up of synced order history', $message),
                        2 => $this->assertSame('Synced order history clean-up complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );
        $mockLogger->expects($this->once())
            ->method('debug')
            ->with('Removed {successCount} history items for orders in status {syncStatus}. ');

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );

        $removeSyncedOrderHistoryCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $removeSyncedOrderHistoryCron->execute();

        $threshold = time() - (2 * 86400);
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 2,
            threshold: $threshold,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SuccessAndErrorHistoryThreshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        $processingSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::PROCESSING);
        $syncedSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::SYNCED);
        $errorSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::ERROR);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting clean-up of synced order history', $message),
                        2 => $this->assertSame('Synced order history clean-up complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );
        $mockLogger->expects($this->exactly(2))
            ->method('debug')
            ->with('Removed {successCount} history items for orders in status {syncStatus}. ');

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );

        $removeSyncedOrderHistoryCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $removeSyncedOrderHistoryCron->execute();

        $threshold = time() - (2 * 86400);
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 2,
            threshold: $threshold,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 2,
            threshold: $threshold,
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncOrderHistoryDeleteThrowsException(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS,
            value: '2',
        );
        $processingSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::PROCESSING);
        $syncedSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::SYNCED);
        $errorSyncOrder = $this->createSyncOrderHistoryFixture(Statuses::ERROR);

        $mockLogger = $this->getMockLogger();
        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('info')
            ->willReturnCallback(
                function (string $message) use ($matcher): void {
                    match ($matcher->getInvocationCount()) {
                        1 => $this->assertSame('Starting clean-up of synced order history', $message),
                        2 => $this->assertSame('Synced order history clean-up complete', $message),
                        default => $this->fail('Logger::info was not expected to be called more than twice'),
                    };
                },
            );

        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('debug')
            ->willReturnCallback(
                function (string $message, array $context) use ($matcher): void {
                    $invocationCount = $matcher->getInvocationCount();

                    $this->assertSame(
                        'Removed {successCount} history items for orders in status {syncStatus}. '
                            . 'Failed to remove {errorCount} items.',
                        $message,
                    );

                    $this->assertArrayHasKey('successCount', $context);
                    $this->assertSame(0, $context['successCount']);

                    $this->assertArrayHasKey('errorCount', $context);
                    $this->assertSame(1, $context['errorCount']);

                    $this->assertArrayHasKey('syncStatus', $context);
                    match ($invocationCount) {
                        1, => $this->assertSame(Statuses::SYNCED->value, $context['syncStatus']),
                        2, => $this->assertSame(Statuses::ERROR->value, $context['syncStatus']),
                        default => null,
                    };
                },
            );

        $matcher = $this->exactly(2);
        $mockLogger->expects($matcher)
            ->method('log')
            ->willReturnCallback(
                function (string $level, string $message, array $context) use ($matcher): void {
                    $invocationCount = $matcher->getInvocationCount();

                    $this->assertSame('warning', $level);
                    $this->assertSame(
                        'Failed to remove sync history item #1: Test Exception Message',
                        $message,
                        'Invocation: ' . $invocationCount,
                    );

                    $this->assertArrayHasKey('syncStatus', $context);
                    match ($invocationCount) {
                        1, => $this->assertSame(Statuses::SYNCED->value, $context['syncStatus']),
                        2, => $this->assertSame(Statuses::ERROR->value, $context['syncStatus']),
                        default => null,
                    };
                },
            );

        /** @var MockObject&SyncOrderHistoryRepositoryInterface $mockSyncOrderHistoryRepository */
        $mockSyncOrderHistoryRepository = $this->getMockBuilder(SyncOrderHistoryRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var SearchResultsInterface $searchResults */
        $searchResults = $this->objectManager->create(SearchResultsInterface::class);
        $searchResults->setTotalCount(2);
        /** @var array<ExtensibleDataInterface&SyncOrderHistoryInterface&MockObject> $items */
        $items = [
            $this->createConfiguredMock(SyncOrderHistoryInterface::class, [
                'getEntityId' => 1,
            ]),
        ];
        $searchResults->setItems($items);
        $mockSyncOrderHistoryRepository->method('getList')
            ->willReturn($searchResults);
        $mockSyncOrderHistoryRepository->method('delete')
            ->willThrowException(
                new CouldNotDeleteException(__('Test Exception Message')),
            );

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );

        $removeSyncedOrderHistoryService = $this->objectManager->create(
            RemoveSyncedOrderHistoryServiceInterface::class,
            [
                'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
            ],
        );

        $removeSyncedOrderHistoryCron = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'removeSyncedOrderHistoryService' => $removeSyncedOrderHistoryService,
        ]);
        $removeSyncedOrderHistoryCron->execute();

        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $processingSyncOrder,
            ),
            expectedCount: 2,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $syncedSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
        $this->assertSyncOrderHistoryResults(
            syncOrderHistoryResults: $this->getSyncOrderHistoryResultForSyncOrder(
                syncOrder: $errorSyncOrder,
            ),
            expectedCount: 3,
            threshold: 0,
        );
    }

    /**
     * Creates Order fixture
     * Creates SyncOrder fixture with specified status
     * Creates SyncOrderHistory for Process Start
     * Creates SyncOrderHistory for Process End if status is not processing
     *
     * Updates Queue history timestamp to -3 days
     * Updates Process Start history timestamp to -2 days
     *
     * @param Statuses $syncOrderStatus
     * @return SyncOrderInterface
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function createSyncOrderHistoryFixture(
        Statuses $syncOrderStatus,
    ): SyncOrderInterface {
        $order = $this->getOrderFixture(true);

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $syncOrder->setStatus($syncOrderStatus->value);
        $syncOrder = $this->syncOrderRepository->save($syncOrder);

        // Queue
        $syncOrderHistoryResults = $this->getSyncOrderHistoryResultForSyncOrder(
            syncOrder: $syncOrder,
            action: Actions::QUEUE,
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistoryQueue */
        $syncOrderHistoryQueue = current($syncOrderHistoryResults->getItems());
        $syncOrderHistoryQueue->setTimestamp(date('Y-m-d H:i:s', time() - (3 * 86350)));
        $this->syncOrderHistoryRepository->save($syncOrderHistoryQueue);

        // Process Start
        $syncOrderHistoryProcessStart = $this->syncOrderHistoryFactory->createFromSyncOrder($syncOrder);
        $syncOrderHistoryProcessStart->setAction(Actions::PROCESS_START->value);
        $syncOrderHistoryProcessStart->setResult(Results::SUCCESS->value);
        $syncOrderHistoryProcessStart->setTimestamp(date('Y-m-d H:i:s', time() - (2 * 86350)));
        $this->syncOrderHistoryRepository->save($syncOrderHistoryProcessStart);

        // Process End
        if (Statuses::PROCESSING !== $syncOrderStatus) {
            $syncOrderHistoryProcessEnd = $this->syncOrderHistoryFactory->createFromSyncOrder($syncOrder);
            $syncOrderHistoryProcessEnd->setAction(Actions::PROCESS_END->value);
            $syncOrderHistoryProcessEnd->setResult(Results::SUCCESS->value);
            $syncOrderHistoryProcessEnd->setTimestamp(date('Y-m-d H:i:s'));
            $this->syncOrderHistoryRepository->save($syncOrderHistoryProcessEnd);
        }

        return $syncOrder;
    }

    /**
     * @param SyncOrderInterface $syncOrder
     * @param Actions|null $action
     * @return SearchResultsInterface
     */
    private function getSyncOrderHistoryResultForSyncOrder(
        SyncOrderInterface $syncOrder,
        ?Actions $action = null,
    ): SearchResultsInterface {
        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getEntityId(),
        );
        if ($action) {
            $this->searchCriteriaBuilder->addFilter(
                field: SyncOrderHistory::FIELD_ACTION,
                value: $action->value,
            );
        }

        return $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
    }

    /**
     * @param SearchResultsInterface $syncOrderHistoryResults
     * @param int $expectedCount
     * @param int $threshold
     * @return void
     */
    private function assertSyncOrderHistoryResults(
        SearchResultsInterface $syncOrderHistoryResults,
        int $expectedCount,
        int $threshold,
    ): void {
        $this->assertSame($expectedCount, $syncOrderHistoryResults->getTotalCount());

        /** @var SyncOrderHistoryInterface $syncOrderHistoryItem */
        foreach ($syncOrderHistoryResults->getItems() as $syncOrderHistoryItem) {
            $this->assertGreaterThanOrEqual(
                $threshold,
                strtotime($syncOrderHistoryItem->getTimestamp()),
            );
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

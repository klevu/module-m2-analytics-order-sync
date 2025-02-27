<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Action;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory;
use Klevu\AnalyticsOrderSync\Service\Action\UpdateSyncOrderHistoryForOrderId;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\UpdateSyncOrderHistoryForOrderIdActionInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @method UpdateSyncOrderHistoryForOrderId instantiateTestObject(?array $arguments = null)
 * @method UpdateSyncOrderHistoryForOrderId instantiateTestObjectFromInterface(?array $arguments = null)
 */
class UpdateSyncOrderHistoryForOrderIdTest extends TestCase
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
     * @var SyncOrderHistoryInterfaceFactory|null
     */
    private ?SyncOrderHistoryInterfaceFactory $syncOrderHistoryInterfaceFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = UpdateSyncOrderHistoryForOrderId::class;
        $this->interfaceFqcn = UpdateSyncOrderHistoryForOrderIdActionInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);
        $this->syncOrderHistoryInterfaceFactory = $this->objectManager->get(SyncOrderHistoryInterfaceFactory::class);

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

    public function testExecute_WithNoOrderHistoryItems(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [],
        );

        $mockSyncOrderHistoryRepository = $this->getMockSyncOrderHistoryRepository();
        $mockSyncOrderHistoryRepository->method('getList')
            ->willReturn(
                value: $this->getMockSearchResults(
                    items: [],
                    totalCount: 0,
                ),
            );
        $mockSyncOrderHistoryRepository->expects($this->never())
            ->method('save');

        $updateSyncOrderHistoryForOrderIdAction = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
        ]);

        $updateSyncOrderHistoryForOrderIdAction->execute(9999999);
    }

    public function testExecute_SaveThrowsException_WithThrowOnException(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [],
        );

        $mockSyncOrderHistoryRepository = $this->getMockSyncOrderHistoryRepository();
        $mockSyncOrderHistoryRepository->method('getList')
            ->willReturn(
                value: $this->getMockSearchResults(
                    items: [
                        $this->syncOrderHistoryInterfaceFactory->create(
                            data: [
                                SyncOrderHistory::FIELD_ENTITY_ID => 9999999,
                                SyncOrderHistory::FIELD_SYNC_ORDER_ID => 42,
                                SyncOrderHistory::FIELD_TIMESTAMP => '2024-12-31 23:59:59',
                                SyncOrderHistory::FIELD_ACTION => Actions::QUEUE,
                                SyncOrderHistory::FIELD_VIA => 'phpunit',
                                SyncOrderHistory::FIELD_RESULT => 'success',
                                SyncOrderHistory::FIELD_ADDITIONAL_INFORMATION => [],
                            ],
                        ),
                    ],
                    totalCount: 1,
                ),
            );
        $mockSyncOrderHistoryRepository->expects($this->once())
            ->method('save')
            ->willThrowException(
                exception: new CouldNotSaveException(
                    phrase: __('Test Exception'),
                ),
            );

        $updateSyncOrderHistoryForOrderIdAction = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
            'throwOnException' => true,
        ]);

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Test Exception');
        $updateSyncOrderHistoryForOrderIdAction->execute(9999999);
    }

    public function testExecute_SaveThrowsException_WithoutThrowOnException(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [
                LogLevel::WARNING,
            ],
        );
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                'Could not update syncOrderHistory action to {newAction} for item #{syncOrderHistoryId}',
                [
                    'exception' => CouldNotSaveException::class,
                    'error' => 'Test Exception',
                    'method' => 'Klevu\AnalyticsOrderSync\Service\Action\UpdateSyncOrderHistoryForOrderId::execute',
                    'newAction' => Actions::MIGRATE->value,
                    'syncOrderId' => 42,
                    'syncOrderHistoryId' => 9999999,
                ],
            );

        $mockSyncOrderHistoryRepository = $this->getMockSyncOrderHistoryRepository();
        $mockSyncOrderHistoryRepository->method('getList')
            ->willReturn(
                value: $this->getMockSearchResults(
                    items: [
                        $this->createSyncOrderHistoryItem(
                            data: [
                                SyncOrderHistory::FIELD_ENTITY_ID => 9999999,
                                SyncOrderHistory::FIELD_SYNC_ORDER_ID => 42,
                                SyncOrderHistory::FIELD_TIMESTAMP => '2024-12-31 23:59:59',
                                SyncOrderHistory::FIELD_ACTION => Actions::QUEUE,
                                SyncOrderHistory::FIELD_VIA => 'phpunit',
                                SyncOrderHistory::FIELD_RESULT => 'success',
                                SyncOrderHistory::FIELD_ADDITIONAL_INFORMATION => [],
                            ],
                        ),
                    ],
                    totalCount: 1,
                ),
            );
        $mockSyncOrderHistoryRepository->expects($this->once())
            ->method('save')
            ->willThrowException(
                exception: new CouldNotSaveException(
                    phrase: __('Test Exception'),
                ),
            );

        $updateSyncOrderHistoryForOrderIdAction = $this->instantiateTestObject([
            'logger' => $mockLogger,
            'syncOrderHistoryRepository' => $mockSyncOrderHistoryRepository,
            'throwOnException' => false,
        ]);

        $updateSyncOrderHistoryForOrderIdAction->execute(9999999);
    }

    public function testExecute(
        ?Actions $action = null,
        ?string $via = null,
        ?string $result = null,
        ?array $additionalInformation = null,
    ): void {
        $order = $this->getOrderFixture(
            orderSyncEnabled: true,
            orderLines: 3,
        );
        $syncOrder = $this->syncOrderRepository->getByOrderId(
            orderId: (int)$order->getId(),
        );

        $additionalSyncOrderHistory = $this->createSyncOrderHistoryItem(
            data: [
                SyncOrderHistory::FIELD_SYNC_ORDER_ID => $syncOrder->getId(),
                SyncOrderHistory::FIELD_TIMESTAMP => '2024-12-31 23:59:59',
                SyncOrderHistory::FIELD_ACTION => Actions::QUEUE,
                SyncOrderHistory::FIELD_VIA => 'phpunit',
                SyncOrderHistory::FIELD_RESULT => 'success',
                SyncOrderHistory::FIELD_ADDITIONAL_INFORMATION => [],
            ],
        );
        $this->syncOrderHistoryRepository->save($additionalSyncOrderHistory);

        $this->searchCriteriaBuilder->addFilter(
            field: SyncOrderHistory::FIELD_SYNC_ORDER_ID,
            value: $syncOrder->getId(),
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $syncOrderHistoryItemsBeforeResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $searchCriteria,
        );

        $updateSyncOrderHistoryForOrderIdAction = $this->instantiateTestObject();
        $updateSyncOrderHistoryForOrderIdAction->execute(
            orderId: (int)$order->getId(),
            action: $action,
            via: $via,
            result: $result,
            additionalInformation: $additionalInformation,
        );

        $syncOrderHistoryItemsAfterResult = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $searchCriteria,
        );

        $this->assertSame(
            expected: $syncOrderHistoryItemsBeforeResult->getTotalCount(),
            actual: $syncOrderHistoryItemsAfterResult->getTotalCount(),
        );

        $syncOrderHistoryItemsBefore = $syncOrderHistoryItemsBeforeResult->getItems();
        foreach ($syncOrderHistoryItemsAfterResult->getItems() as $syncOrderHistoryItem) {
            $syncOrderHistoryItemBefore = $syncOrderHistoryItemsBefore[$syncOrderHistoryItem->getEntityId()] ?? null;
            $this->assertNotNull($syncOrderHistoryItemBefore);

            $this->assertSame(
                expected: (null !== $action)
                    ? $action->value
                    : $syncOrderHistoryItemBefore->getAction(),
                actual: $syncOrderHistoryItem->getAction(),
            );
            $this->assertSame(
                expected: (null !== $via)
                    ? $via
                    : $syncOrderHistoryItemBefore->getVia(),
                actual: $syncOrderHistoryItem->getVia(),
            );
            $this->assertSame(
                expected: (null !== $result)
                    ? $result
                    : $syncOrderHistoryItemBefore->getResult(),
                actual: $syncOrderHistoryItem->getResult(),
            );
            $this->assertSame(
                expected: (null !== $additionalInformation)
                    ? $additionalInformation
                    : $syncOrderHistoryItemBefore->getAdditionalInformation(),
                actual: $syncOrderHistoryItem->getAdditionalInformation(),
            );
        }
    }

    /**
     * @param string[] $expectedLogLevels
     *
     * @return MockObject&LoggerInterface
     */
    private function getMockLogger(
        array $expectedLogLevels = [],
    ): MockObject {
        $mockLogger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $logLevels = array_diff(
            [
                LogLevel::EMERGENCY,
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::ERROR,
                LogLevel::WARNING,
                LogLevel::NOTICE,
                LogLevel::INFO,
                LogLevel::DEBUG,
            ],
            $expectedLogLevels,
        );
        foreach ($logLevels as $logLevel) {
            $mockLogger->expects($this->never())
                ->method($logLevel);
        }

        return $mockLogger;
    }

    /**
     * @return MockObject&SyncOrderHistoryRepositoryInterface
     */
    private function getMockSyncOrderHistoryRepository(): MockObject
    {
        return $this->getMockBuilder(SyncOrderHistoryRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param SyncOrderHistoryInterface[] $items
     * @param int $totalCount
     *
     * @return MockObject&SearchResultsInterface
     */
    private function getMockSearchResults(
        array $items,
        int $totalCount,
    ): MockObject {
        $mockSearchResults = $this->getMockBuilder(SearchResultsInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockSearchResults->method('getItems')
            ->willReturn($items);
        $mockSearchResults->method('getTotalCount')
            ->willReturn($totalCount);

        return $mockSearchResults;
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return SyncOrderHistory
     */
    private function createSyncOrderHistoryItem(
        ?array $data = null,
    ): SyncOrderHistory {
        $syncOrderHistory = $this->syncOrderHistoryInterfaceFactory->create();
        foreach ($data as $field => $value) {
            $syncOrderHistory->setData($field, $value);
        }

        return $syncOrderHistory;
    }
}

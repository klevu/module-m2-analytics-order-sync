<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Console\Command;

use Klevu\AnalyticsOrderSync\Console\Command\QueueOrdersForSyncCommand;
use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method QueueOrdersForSyncCommand instantiateTestObject(?array $arguments = null)
 */
class QueueOrdersForSyncCommandTest extends TestCase
{
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;

    /**
     * @var ObjectManagerInterface|ObjectManager|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;
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

        $this->implementationFqcn = QueueOrdersForSyncCommand::class;
        // newrelic-describe-commands globs onto Console commands
        $this->expectPlugins = true;

        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->orderFixtures = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testExecute_NoStoresEnabledForSync(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
            storeCode: 'default',
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order = $this->getOrderFixture(false);
        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [],
            options: [],
        );

        $this->assertStringContainsString(
            'No stores enabled for sync',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            $tester->getDisplay(),
        );
        $this->assertSame(
            QueueOrdersForSyncCommand::FAILURE,
            $tester->getStatusCode(),
        );

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testExecute_NoStoresEnabledForSync_WithStoreIdOption(): void
    {
        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
        $testStore = $this->storeFixturesPool->get('test_store_1');

        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
            storeCode: 'default',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '1',
            storeCode: $testStore->getCode(),
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order = $this->getOrderFixture(false);
        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--store-id' => ['1'],
            ],
        );

        $this->assertStringContainsString(
            'No stores enabled for sync',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            $tester->getDisplay(),
        );
        $this->assertSame(
            QueueOrdersForSyncCommand::FAILURE,
            $tester->getStatusCode(),
        );

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testExecute_NoStoresEnabledForSync_WithIgnoreSyncEnabledFlag(): void
    {
        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
        $testStore = $this->storeFixturesPool->get('test_store_1');

        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '0',
            storeCode: 'default',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '1',
            storeCode: $testStore->getCode(),
        );
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order = $this->getOrderFixture(false);
        if (!method_exists($order, 'getId')) {
            throw new \LogicException(sprintf(
                'Order of type %s does not contain method getId()',
                $order::class,
            ));
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--store-id' => ['1'],
                '--ignore-sync-enabled-flag' => true,
            ],
        );

        $this->assertStringNotContainsString(
            'No stores enabled for sync',
            $tester->getDisplay(),
        );
        $this->assertStringNotContainsString(
            'Enable sync for selected stores or run with the --ignore-sync-enabled-flag option',
            $tester->getDisplay(),
        );
        $this->assertSame(
            QueueOrdersForSyncCommand::SUCCESS,
            $tester->getStatusCode(),
        );

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order->getEntityId());
        $this->assertNotNull($syncOrder);
        $this->assertNotNull($syncOrder->getEntityId());
        $this->assertSame((int)$order->getId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder->getStatus());
        $this->assertSame((int)$order->getStoreId(), $syncOrder->getStoreId());
        $this->assertSame(0, $syncOrder->getAttempts());

        $this->assertStringContainsString(
            'Queueing order id #' . $order->getEntityId() . ': OK',
            $tester->getDisplay(),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_WithOrderIds(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order1 = $this->getOrderFixture(false);
        $order2 = $this->getOrderFixture(false);

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_id',
            value: [
                (int)$order1->getEntityId(),
                (int)$order2->getEntityId(),
            ],
            conditionType: 'in',
        );
        $syncOrderResult = $this->syncOrderRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(0, $syncOrderResult->getTotalCount());

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--order-id' => [$order2->getEntityId()],
                '--ignore-sync-enabled-flag' => true,
            ],
        );
        try {
            $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder1 = null;
        }
        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());

        $this->assertNull($syncOrder1);
        $this->assertNotNull($syncOrder2);
        $this->assertSame((int)$order2->getEntityId(), $syncOrder2->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder2->getStatus());

        $this->assertStringContainsString(
            'Queueing order id #' . $order2->getEntityId() . ': OK',
            $tester->getDisplay(),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_WithSyncStatuses(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order1 = $this->getOrderFixture(false);
        try {
            $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder1 = null;
        }
        $this->assertNull($syncOrder1);

        $order2 = $this->getOrderFixture(true);
        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $syncOrder2->setStatus(Statuses::ERROR->value);
        $syncOrder2->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder2);

        $order3 = $this->getOrderFixture(true);
        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $syncOrder3->setStatus(Statuses::SYNCED->value);
        $syncOrder3->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder3);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--sync-status' => [
                    Statuses::NOT_REGISTERED->value,
                    Statuses::ERROR->value,
                ],
                '--ignore-sync-enabled-flag' => true,
            ],
        );

        $this->syncOrderRepository->clearCache();

        $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder1->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder1->getStatus());

        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $this->assertSame((int)$order2->getEntityId(), $syncOrder2->getOrderId());
        $this->assertSame(Statuses::RETRY->value, $syncOrder2->getStatus());

        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $this->assertSame((int)$order3->getEntityId(), $syncOrder3->getOrderId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder3->getStatus());

        $this->assertStringContainsString(
            'Queueing order id #' . $order1->getEntityId() . ': OK',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            'Queueing order id #' . $order2->getEntityId() . ': OK',
            $tester->getDisplay(),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_CombinationFilters(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '1',
            storeCode: 'default',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order1 = $this->getOrderFixture(false);
        try {
            $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder1 = null;
        }
        $this->assertNull($syncOrder1);

        $order2 = $this->getOrderFixture(true);
        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $syncOrder2->setStatus(Statuses::ERROR->value);
        $syncOrder2->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder2);

        $order3 = $this->getOrderFixture(true);
        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $syncOrder3->setStatus(Statuses::SYNCED->value);
        $syncOrder3->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder3);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--order-id' => [
                    (int)$order1->getEntityId(),
                ],
                '--store-id' => [
                    1,
                ],
                '--sync-status' => [
                    Statuses::NOT_REGISTERED->value,
                    Statuses::ERROR->value,
                ],
            ],
        );

        $this->syncOrderRepository->clearCache();

        $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder1->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder1->getStatus());

        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $this->assertSame((int)$order2->getEntityId(), $syncOrder2->getOrderId());
        $this->assertSame(Statuses::ERROR->value, $syncOrder2->getStatus());

        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $this->assertSame((int)$order3->getEntityId(), $syncOrder3->getOrderId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder3->getStatus());

        $this->assertStringContainsString(
            'Queueing order id #' . $order1->getEntityId() . ': OK',
            $tester->getDisplay(),
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testExecute_CombinationFilters_NoOrdersToSync(): void
    {
        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
        $testStore = $this->storeFixturesPool->get('test_store_1');

        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '1',
            storeCode: 'default',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
            storeCode: 'default',
        );

        $order1 = $this->getOrderFixture(false);
        try {
            $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder1 = null;
        }
        $this->assertNull($syncOrder1);

        $order2 = $this->getOrderFixture(true);
        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $syncOrder2->setStatus(Statuses::ERROR->value);
        $syncOrder2->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder2);

        $order3 = $this->getOrderFixture(true);
        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $syncOrder3->setStatus(Statuses::SYNCED->value);
        $syncOrder3->setAttempts(1);
        $this->syncOrderRepository->save($syncOrder3);

        $queueOrdersForSyncCommand = $this->instantiateTestObject();
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--order-id' => [
                    (int)$order1->getEntityId(),
                ],
                '--store-id' => [
                    (int)$testStore->getId(),
                ],
                '--sync-status' => [
                    Statuses::NOT_REGISTERED->value,
                    Statuses::ERROR->value,
                ],
                '--ignore-sync-enabled-flag' => true,
            ],
        );

        $this->syncOrderRepository->clearCache();

        try {
            $syncOrder1 = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder1 = null;
        }
        $this->assertNull($syncOrder1);

        $syncOrder2 = $this->syncOrderRepository->getByOrderId((int)$order2->getEntityId());
        $this->assertSame((int)$order2->getEntityId(), $syncOrder2->getOrderId());
        $this->assertSame(Statuses::ERROR->value, $syncOrder2->getStatus());

        $syncOrder3 = $this->syncOrderRepository->getByOrderId((int)$order3->getEntityId());
        $this->assertSame((int)$order3->getEntityId(), $syncOrder3->getOrderId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder3->getStatus());

        $this->assertStringContainsString(
            'No matching orders found to queue',
            $tester->getDisplay(),
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_QueueOrderThrowsException(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC,
            value: '',
        );
        ConfigFixture::setForStore(
            path: Constants::XML_PATH_ORDER_SYNC_ENABLED,
            value: '1',
            storeCode: 'default',
        );

        $order = $this->getOrderFixture(false);

        $mockQueueOrderForSyncAction = $this->getMockBuilder(QueueOrderForSyncActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockQueueOrderForSyncAction->method('execute')
            ->willThrowException(new LocalizedException(
                __('Test Exception Message'),
            ));

        $queueOrdersForSyncCommand = $this->instantiateTestObject([
            'queueOrderForSyncAction' => $mockQueueOrderForSyncAction,
        ]);
        $tester = new CommandTester(
            command: $queueOrdersForSyncCommand,
        );

        $tester->execute(
            input: [
                '--order-id' => [
                    (int)$order->getEntityId(),
                ],
                '--ignore-sync-enabled-flag' => true,
            ],
        );

        $this->assertStringContainsString(
            'Queueing order id #' . $order->getEntityId() . ': ERROR',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString(
            'Test Exception Message',
            $tester->getDisplay(),
        );
    }
}

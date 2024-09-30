<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Setup\Patch\Data;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSync\Setup\Patch\Data\MigrateLegacyOrderSyncRecords;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @method MigrateLegacyOrderSyncRecords instantiateTestObject(?array $arguments = null)
 * @method MigrateLegacyOrderSyncRecords instantiateTestObjectFromInterface(?array $arguments = null)
 * @magentoDbIsolation disabled
 */
class MigrateLegacyOrderSyncRecordsTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use OrderTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resourceConnection = null;
    /**
     * @var SchemaSetupInterface|null
     */
    private ?SchemaSetupInterface $schemaSetup = null;
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

        $this->implementationFqcn = MigrateLegacyOrderSyncRecords::class;
        $this->interfaceFqcn = DataPatchInterface::class;

        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->schemaSetup = $this->objectManager->get(SchemaSetupInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);
        $this->syncOrderHistoryRepository = $this->objectManager->get(SyncOrderHistoryRepositoryInterface::class);

        $this->createLegacyOrderSyncTable();
        $this->orderFixtures = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->dropLegacyOrderSyncTable();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_Pending(): void
    {

        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: 0,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::QUEUED->value, $syncOrder->getStatus());
        $this->assertSame(0, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_Success(): void
    {

        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: 1,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::SYNCED->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_Error(): void
    {

        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: 2,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::ERROR->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_QueuedAndSuccess(): void
    {
        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $i => $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: $i ? 1 : 0,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::PARTIAL->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_Mixed_QueuedAndError(): void
    {
        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $i => $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: $i ? 2 : 0,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::PARTIAL->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_Mixed_SuccessAndError(): void
    {
        $order1 = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 2,
        );
        $orderLines = $order1->getItems();
        foreach ($orderLines as $i => $orderLine) {
            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderLine->getItemId(),
                klevuSessionId: 'abcde12345',
                ipAddress: $order1->getRemoteIp() ?? '127.0.0.1',
                date: $order1->getCreatedAt(),
                idcode: 'ABCDE12345',
                checkoutdate: (string)time(),
                send: $i ? 2 : 1,
            );
        }

        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        }
        $this->assertNull($syncOrder);
        $this->syncOrderRepository->clearCache();

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsPatch->apply();

        $syncOrder = $this->syncOrderRepository->getByOrderId((int)$order1->getEntityId());
        $this->assertSame((int)$order1->getEntityId(), $syncOrder->getOrderId());
        $this->assertSame(Statuses::ERROR->value, $syncOrder->getStatus());
        $this->assertSame(1, $syncOrder->getAttempts());

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_order_id',
            value: $syncOrder->getEntityId(),
        );
        $syncOrderHistoryResults = $this->syncOrderHistoryRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $this->assertSame(1, $syncOrderHistoryResults->getTotalCount());
        /** @var SyncOrderHistoryInterface $syncOrderHistory */
        $syncOrderHistory = current($syncOrderHistoryResults->getItems());
        $this->assertSame(Actions::MIGRATE->value, $syncOrderHistory->getAction());
        $this->assertSame(Results::SUCCESS->value, $syncOrderHistory->getResult());
        $this->assertSame('Database Migration', $syncOrderHistory->getVia());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testApply_OrderNotExists(): void
    {
        $this->addLegacyOrderSyncLine(
            orderItemId: 12345,
            klevuSessionId: 'abcde12345',
            ipAddress: '127.0.0.1',
            date: date('Y-m-d H:i:s'),
            idcode: 'ABCDE12345',
            checkoutdate: (string)time(),
            send: 0,
        );

        $mockLogger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockLogger->expects($this->once())
            ->method('error');

        $migrateLegacyOrderSyncRecordsPatch = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $migrateLegacyOrderSyncRecordsPatch->apply();
    }

    /**
     * @return void
     */
    private function createLegacyOrderSyncTable(): void
    {
        $this->dropLegacyOrderSyncTable();
        $this->schemaSetup->run(sprintf(
            <<<'SQL'
            CREATE TABLE `%s` (
                `order_item_id` int(10) unsigned NOT NULL,
                `klevu_session_id` VARCHAR(255) NOT NULL,
                `ip_address` VARCHAR(255) NOT NULL,
                `date` DATETIME NOT NULL,
                `idcode` VARCHAR(255) NOT NULL,
                `checkoutdate` VARCHAR(255) NOT NULL,
                `send` BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (`order_item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            SQL,
            $this->schemaSetup->getTable('klevu_order_sync'),
        ));
    }

    /**
     * @return void
     */
    private function dropLegacyOrderSyncTable(): void
    {
        $this->schemaSetup->run(sprintf(
            <<<'SQL'
            DROP TABLE IF EXISTS `%s`;
            SQL,
            $this->schemaSetup->getTable('klevu_order_sync'),
        ));
    }

    /**
     * @param int $orderItemId
     * @param string $klevuSessionId
     * @param string $ipAddress
     * @param string $date
     * @param string $idcode
     * @param string $checkoutdate
     * @param int $send
     * @return void
     */
    private function addLegacyOrderSyncLine(
        int $orderItemId,
        string $klevuSessionId,
        string $ipAddress,
        string $date,
        string $idcode,
        string $checkoutdate,
        int $send,
    ): void {
        $connection = $this->resourceConnection->getConnection();

        $connection->insert(
            table: $this->schemaSetup->getTable('klevu_order_sync'),
            bind: [
                'order_item_id' => $orderItemId,
                'klevu_session_id' => $klevuSessionId,
                'ip_address' => $ipAddress,
                'date' => $date,
                'idcode' => $idcode,
                'checkoutdate' => $checkoutdate,
                'send' => $send,
            ],
        );
    }
}

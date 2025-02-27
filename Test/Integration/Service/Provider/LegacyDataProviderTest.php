<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Service\Provider\LegacyDataProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\LegacyDataProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @method LegacyDataProvider instantiateTestObject(?array $arguments = null)
 * @method LegacyDataProvider instantiateTestObjectFromInterface(?array $arguments = null)
 */
class LegacyDataProviderTest extends TestCase
{
    use CreateOrderInStoreTrait;
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line Used by traits
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var OrderRepositoryInterface|null
     */
    private ?OrderRepositoryInterface $orderRepository = null;
    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resourceConnection = null;
    /**
     * @var SchemaSetupInterface|null
     */
    private ?SchemaSetupInterface $schemaSetup = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = LegacyDataProvider::class;
        $this->interfaceFqcn = LegacyDataProviderInterface::class;

        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->schemaSetup = $this->objectManager->get(SchemaSetupInterface::class);

        $this->createLegacyOrderSyncTable();

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->orderFixtures = [];
    }

    /**
     * @return void
     * @throws \Exception+
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();

        $this->dropLegacyOrderSyncTable();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForStoreId_LegacyTableNotPresent(): void
    {
        $this->dropLegacyOrderSyncTable();

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [LogLevel::DEBUG],
        );
        $mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains(
                    string: 'Cannot retrieve legacy order data for store #{storeId}: '
                        . 'Legacy order sync table {legacyTableName} does not exist',
                ),
                $this->callback(
                    callback: fn (array $context): bool => isset($context['storeId'])
                        && $context['storeId'] === 1
                        && isset($context['legacyTableName'])
                        && $context['legacyTableName'] === $this->schemaSetup->getTable('klevu_order_sync'),
                ),
            );

        $legacyDataProvider = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $recordsCount = 0;
        foreach ($legacyDataProvider->getForStoreId(1) as $record) { // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable, Generic.Files.LineLength.TooLong
            $recordsCount++;
        }

        $this->assertSame(
            expected: 0,
            actual: $recordsCount,
            message: 'Records count',
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForStoreId_NonExistentStore(): void
    {
        // We just expected a silent, empty return - we don't actually validate the store exists
        //  because it's not important
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [],
        );

        $legacyDataProvider = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $recordsCount = 0;
        foreach ($legacyDataProvider->getForStoreId(-1) as $record) { // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable, Generic.Files.LineLength.TooLong
            $recordsCount++;
        }

        $this->assertSame(
            expected: 0,
            actual: $recordsCount,
            message: 'Records count',
        );
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForStoreId_InvalidRow(): void
    {
        $order = $this->getOrderFixture(
            orderSyncEnabled: false,
            orderLines: 3,
        );

        $storeId = 0;
        $expectedResults = [];

        foreach ($order->getItems() as $index => $orderItem) {
            $storeId = (int)$orderItem->getStoreId();
            $expectedResults[] = [
                'order_item_id' => (int)$orderItem->getId(),
                'send' => $index,
                'order_id' => (int)$order->getId(),
            ];

            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: '12345600' . $index,
                ipAddress: '172.16.0.1',
                date: sprintf('2024-12-0%d 23:59:59', $index),
                idcode: '12345600' . $index,
                checkoutdate: sprintf('2024-12-0%d 23:59:59', $index),
                send: $index,
            );
        }

        $this->addLegacyOrderSyncLine(
            orderItemId: 0,
            klevuSessionId: '1234567890',
            ipAddress: '172.16.0.1',
            date: '2024-12-31 23:59:59',
            idcode: '1234567890',
            checkoutdate: '2024-12-31 23:59:59',
            send: 2,
        );

        // Yes, there is a case to handle invalid rows in the data return however this is defensive coding
        //  - there should be no real world way of including the above line in the data return due to the
        //  SQL join. Therefore, if we get a warning here (or the record count differs), it should be investigated
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [],
        );

        $legacyDataProvider = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $recordsCount = 0;
        foreach ($legacyDataProvider->getForStoreId($storeId) as $index => $record) {
            $this->assertSame(
                expected: $expectedResults[$index] ?? null,
                actual: $record,
                message: 'Record #' . $index,
            );
            $recordsCount++;
        }

        $this->assertSame(
            expected: 3,
            actual: $recordsCount,
            message: 'Records count',
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testGetForStoreId_Multisite(): void
    {
        $defaultWebsite = $this->storeManager->getWebsite();
        $store1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $defaultWebsite,
            klevuApiKey: null,
            syncEnabled: false,
        );

        $store2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $defaultWebsite,
            klevuApiKey: null,
            syncEnabled: false,
        );

        $orderStore1 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_1',
            status: 'pending',
            orderData: [
                'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                'remote_ip' => '172.16.1.0',
                'x_forwarded_for' => '172.16.0.1',
            ],
            orderItemsData: [
                [
                    'sku' => 'test_product_1_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_2_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $expectedResultsStore1 = [];
        foreach ($orderStore1->getItems() as $index => $orderItem) {
            $expectedResultsStore1[] = [
                'order_item_id' => (int)$orderItem->getId(),
                'send' => $index,
                'order_id' => (int)$orderStore1->getId(),
            ];

            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: '12345600' . $index,
                ipAddress: '172.16.0.1',
                date: sprintf('2024-12-0%d 23:59:59', $index),
                idcode: '12345600' . $index,
                checkoutdate: sprintf('2024-12-0%d 23:59:59', $index),
                send: $index,
            );
        }

        $orderStore2 = $this->createOrderInStore(
            storeCode: 'klevu_analytics_test_store_2',
            status: 'pending',
            orderData: [],
            orderItemsData: [
                [
                    'sku' => 'test_product_3_' . rand(1, 99999),
                ],
                [
                    'sku' => 'test_product_4_' . rand(1, 99999),
                ],
            ],
            syncStatus: Statuses::QUEUED,
        );
        $expectedResultsStore2 = [];
        foreach ($orderStore2->getItems() as $index => $orderItem) {
            $expectedResultsStore2[] = [
                'order_item_id' => (int)$orderItem->getId(),
                'send' => $index,
                'order_id' => (int)$orderStore2->getId(),
            ];

            $this->addLegacyOrderSyncLine(
                orderItemId: (int)$orderItem->getId(),
                klevuSessionId: '12345600' . $index,
                ipAddress: '172.16.0.1',
                date: sprintf('2024-12-0%d 23:59:59', $index),
                idcode: '12345600' . $index,
                checkoutdate: sprintf('2024-12-0%d 23:59:59', $index),
                send: $index,
            );
        }

        $legacyDataProvider = $this->instantiateTestObject();

        $recordsCountStore1 = 0;
        $store1Id = (int)$store1->getId();
        foreach ($legacyDataProvider->getForStoreId($store1Id) as $index => $record) {
            $this->assertSame(
                expected: $expectedResultsStore1[$index] ?? null,
                actual: $record,
                message: 'Record #' . $index,
            );
            $recordsCountStore1++;
        }
        $this->assertSame(
            expected: 2,
            actual: $recordsCountStore1,
            message: 'Records count (store 1)',
        );

        $recordsCountStore2 = 0;
        $store2Id = (int)$store2->getId();
        foreach ($legacyDataProvider->getForStoreId($store2Id) as $index => $record) {
            $this->assertSame(
                expected: $expectedResultsStore2[$index] ?? null,
                actual: $record,
                message: 'Record #' . $index,
            );
            $recordsCountStore2++;
        }
        $this->assertSame(
            expected: 2,
            actual: $recordsCountStore2,
            message: 'Records count (store 2)',
        );
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
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Cron;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Cron\CheckDuplicateOrderIps;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @magentoDbIsolation disabled
 */
class CheckDuplicateOrderIpsTest extends TestCase
{
    use CreateOrderInStoreTrait;
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;

    private const FIXTURE_REST_AUTH_KEY = 'ABCDE12345';

    /**
     * @var ObjectManagerInterface|null
     */
    private ObjectManagerInterface|null $objectManager = null;
    /**
     * @var StoreManagerInterface|null
     */
    private ?StoreManagerInterface $storeManager = null;
    /**
     * @var OrderRepositoryInterface|null
     */
    private ?OrderRepositoryInterface $orderRepository = null;
    /**
     * @var SyncOrderRepositoryInterface|null
     */
    private ?SyncOrderRepositoryInterface $syncOrderRepository = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = CheckDuplicateOrderIps::class;

        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->syncOrderRepository = $this->objectManager->get(SyncOrderRepositoryInterface::class);

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
     */
    public function testExecute_SyncEnabledStores(): void
    {
        $enabledStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $enabledStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );

        $fixtures = [
            $enabledStore1->getCode() => array_merge(
                array_fill(
                    start_index: 0,
                    count: 3,
                    value: [
                        'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 2,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 10,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
            ),
            $enabledStore2->getCode() => array_fill(
                start_index: 0,
                count: 5,
                value: [
                    'created_at' => date('Y-m-d H:i:s'),
                    'remote_ip' => '127.0.0.1',
                    'x_forwarded_for' => '172.16.0.1',
                ],
            ),
        ];
        for ($i = 0; $i < 21; $i++) {
            $fixtures[$enabledStore2->getCode()][] = [
                'created_at' => date('Y-m-d H:i:s'),
                'remote_ip' => '172.16.2.' . $i,
                'x_forwarded_for' => null,
            ];
        }

        foreach ($fixtures as $storeCode => $storeFixtures) {
            foreach ($storeFixtures as $fixture) {
                $orderData = $fixture;

                $this->createOrderInStore(
                    storeCode: $storeCode,
                    status: 'pending',
                    orderData: $orderData,
                    orderItemsData: [
                        [
                            'sku' => 'test_product_' . rand(1, 99999),
                        ],
                    ],
                    syncStatus: Statuses::SYNCED,
                );
            }
        }

        /** @var CheckDuplicateOrderIps $checkDuplicateOrderIpsCron */
        $checkDuplicateOrderIpsCron = $this->instantiateTestObject([
            'eventManager' => $this->getMockEventManager(
                event: 'klevu_notifications_upsertNotification',
                expectedData: [
                    'notification_data' => [
                        'type' => 'Klevu_AnalyticsOrderSync::duplicate_order_ips',
                        'severity' => 2,
                        'status' => 4,
                        'message' => 'Klevu has detected many checkout orders originating from the same IP address '
                            . 'causing inaccuracies in Klevu sales analytics.',
                        'details' => 'Store ID: ' . $enabledStore1->getId() . PHP_EOL
                            . 'IP Address "127.0.0.1" found 10 time(s)' . PHP_EOL,
                        'date' => date('Y-m-d H:i:s'),
                        'delete_after_view' => false,
                    ],
                ],
            ),
        ]);
        $checkDuplicateOrderIpsCron->execute();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_SyncDisabledStores(): void
    {
        $enabledStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: false,
        );
        $enabledStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: false,
        );

        $fixtures = [
            $enabledStore1->getCode() => array_merge(
                array_fill(
                    start_index: 0,
                    count: 3,
                    value: [
                        'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 2,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 10,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
            ),
            $enabledStore2->getCode() => array_fill(
                start_index: 0,
                count: 5,
                value: [
                    'created_at' => date('Y-m-d H:i:s'),
                    'remote_ip' => '127.0.0.1',
                    'x_forwarded_for' => '172.16.0.1',
                ],
            ),
        ];
        for ($i = 0; $i < 21; $i++) {
            $fixtures[$enabledStore2->getCode()][] = [
                'created_at' => date('Y-m-d H:i:s'),
                'remote_ip' => '172.16.2.' . $i,
                'x_forwarded_for' => null,
            ];
        }

        foreach ($fixtures as $storeCode => $storeFixtures) {
            foreach ($storeFixtures as $fixture) {
                $orderData = $fixture;

                $this->createOrderInStore(
                    storeCode: $storeCode,
                    status: 'pending',
                    orderData: $orderData,
                    orderItemsData: [
                        [
                            'sku' => 'test_product_' . rand(1, 99999),
                        ],
                    ],
                    syncStatus: Statuses::SYNCED,
                );
            }
        }

        /** @var CheckDuplicateOrderIps $checkDuplicateOrderIpsCron */
        $checkDuplicateOrderIpsCron = $this->instantiateTestObject([
            'eventManager' => $this->getMockEventManager(
                event: 'klevu_notifications_deleteNotification',
                expectedData: [
                    'notification_data' => [
                        'type' => 'Klevu_AnalyticsOrderSync::duplicate_order_ips',
                    ],
                ],
            ),
        ]);
        $checkDuplicateOrderIpsCron->execute();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_PeriodDays(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_PERIOD_DAYS,
            value: 30,
        );

        $enabledStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $enabledStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );

        $fixtures = [
            $enabledStore1->getCode() => array_merge(
                array_fill(
                    start_index: 0,
                    count: 3,
                    value: [
                        'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 2,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 10,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
            ),
            $enabledStore2->getCode() => array_fill(
                start_index: 0,
                count: 5,
                value: [
                    'created_at' => date('Y-m-d H:i:s'),
                    'remote_ip' => '127.0.0.1',
                    'x_forwarded_for' => '172.16.0.1',
                ],
            ),
        ];
        for ($i = 0; $i < 21; $i++) {
            $fixtures[$enabledStore2->getCode()][] = [
                'created_at' => date('Y-m-d H:i:s'),
                'remote_ip' => '172.16.2.' . $i,
                'x_forwarded_for' => null,
            ];
        }

        foreach ($fixtures as $storeCode => $storeFixtures) {
            foreach ($storeFixtures as $fixture) {
                $orderData = $fixture;

                $this->createOrderInStore(
                    storeCode: $storeCode,
                    status: 'pending',
                    orderData: $orderData,
                    orderItemsData: [
                        [
                            'sku' => 'test_product_' . rand(1, 99999),
                        ],
                    ],
                    syncStatus: Statuses::SYNCED,
                );
            }
        }

        /** @var CheckDuplicateOrderIps $checkDuplicateOrderIpsCron */
        $checkDuplicateOrderIpsCron = $this->instantiateTestObject([
            'eventManager' => $this->getMockEventManager(
                event: 'klevu_notifications_upsertNotification',
                expectedData: [
                    'notification_data' => [
                        'type' => 'Klevu_AnalyticsOrderSync::duplicate_order_ips',
                        'severity' => 2,
                        'status' => 4,
                        'message' => 'Klevu has detected many checkout orders originating from the same IP address '
                            . 'causing inaccuracies in Klevu sales analytics.',
                        'details' => 'Store ID: ' . $enabledStore1->getId() . PHP_EOL
                            . 'IP Address "127.0.0.1" found 10 time(s)' . PHP_EOL
                            . 'IP Address "172.16.1.0" found 5 time(s)' . PHP_EOL,
                        'date' => date('Y-m-d H:i:s'),
                        'delete_after_view' => false,
                    ],
                ],
            ),
        ]);
        $checkDuplicateOrderIpsCron->execute();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_Threshold(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_THRESHOLD,
            value: 0.1,
        );

        $enabledStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $enabledStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );

        $fixtures = [
            $enabledStore1->getCode() => array_merge(
                array_fill(
                    start_index: 0,
                    count: 3,
                    value: [
                        'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 2,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 10,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
            ),
            $enabledStore2->getCode() => array_fill(
                start_index: 0,
                count: 5,
                value: [
                    'created_at' => date('Y-m-d H:i:s'),
                    'remote_ip' => '127.0.0.1',
                    'x_forwarded_for' => '172.16.0.1',
                ],
            ),
        ];
        for ($i = 0; $i < 21; $i++) {
            $fixtures[$enabledStore2->getCode()][] = [
                'created_at' => date('Y-m-d H:i:s'),
                'remote_ip' => '172.16.2.' . $i,
                'x_forwarded_for' => null,
            ];
        }

        foreach ($fixtures as $storeCode => $storeFixtures) {
            foreach ($storeFixtures as $fixture) {
                $orderData = $fixture;

                $this->createOrderInStore(
                    storeCode: $storeCode,
                    status: 'pending',
                    orderData: $orderData,
                    orderItemsData: [
                        [
                            'sku' => 'test_product_' . rand(1, 99999),
                        ],
                    ],
                    syncStatus: Statuses::SYNCED,
                );
            }
        }

        /** @var CheckDuplicateOrderIps $checkDuplicateOrderIpsCron */
        $checkDuplicateOrderIpsCron = $this->instantiateTestObject([
            'eventManager' => $this->getMockEventManager(
                event: 'klevu_notifications_upsertNotification',
                expectedData: [
                    'notification_data' => [
                        'type' => 'Klevu_AnalyticsOrderSync::duplicate_order_ips',
                        'severity' => 2,
                        'status' => 4,
                        'message' => 'Klevu has detected many checkout orders originating from the same IP address '
                            . 'causing inaccuracies in Klevu sales analytics.',
                        'details' => 'Store ID: ' . $enabledStore1->getId() . PHP_EOL
                            . 'IP Address "127.0.0.1" found 10 time(s)' . PHP_EOL
                            . 'Store ID: ' . $enabledStore2->getId() . PHP_EOL
                            . 'IP Address "127.0.0.1" found 5 time(s)' . PHP_EOL,
                        'date' => date('Y-m-d H:i:s'),
                        'delete_after_view' => false,
                    ],
                ],
            ),
        ]);
        $checkDuplicateOrderIpsCron->execute();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testExecute_XForwardedFor(): void
    {
        ConfigFixture::setGlobal(
            path: Constants::XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE,
            value: 'x_forwarded_for',
        );

        $enabledStore1 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );
        $enabledStore2 = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_2',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-9876543210',
            syncEnabled: true,
        );
        $this->initOrderSyncEnabled(true);

        $fixtures = [
            $enabledStore1->getCode() => array_merge(
                array_fill(
                    start_index: 0,
                    count: 3,
                    value: [
                        'created_at' => date('Y-m-d H:i:s', time() - (86400 * 20)),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 2,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.16.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
                array_fill(
                    start_index: 0,
                    count: 10,
                    value: [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                    ],
                ),
            ),
            $enabledStore2->getCode() => array_fill(
                start_index: 0,
                count: 5,
                value: [
                    'created_at' => date('Y-m-d H:i:s'),
                    'remote_ip' => '127.0.0.1',
                    'x_forwarded_for' => '172.16.0.1',
                ],
            ),
        ];
        for ($i = 0; $i < 21; $i++) {
            $fixtures[$enabledStore2->getCode()][] = [
                'created_at' => date('Y-m-d H:i:s'),
                'remote_ip' => '172.16.2.' . $i,
                'x_forwarded_for' => null,
            ];
        }

        foreach ($fixtures as $storeCode => $storeFixtures) {
            foreach ($storeFixtures as $fixture) {
                $orderData = $fixture;

                $this->createOrderInStore(
                    storeCode: $storeCode,
                    status: 'pending',
                    orderData: $orderData,
                    orderItemsData: [
                        [
                            'sku' => 'test_product_' . rand(1, 99999),
                        ],
                    ],
                    syncStatus: Statuses::SYNCED,
                );
            }
        }

        /** @var CheckDuplicateOrderIps $checkDuplicateOrderIpsCron */
        $checkDuplicateOrderIpsCron = $this->instantiateTestObject([
            'eventManager' => $this->getMockEventManager(
                event: 'klevu_notifications_upsertNotification',
                expectedData: [
                    'notification_data' => [
                        'type' => 'Klevu_AnalyticsOrderSync::duplicate_order_ips',
                        'severity' => 2,
                        'status' => 4,
                        'message' => 'Klevu has detected many checkout orders originating from the same IP address '
                            . 'causing inaccuracies in Klevu sales analytics.',
                        'details' => 'Store ID: ' . $enabledStore1->getId() . PHP_EOL
                            . 'IP Address "127.0.0.1" found 10 time(s)' . PHP_EOL,
                        'date' => date('Y-m-d H:i:s'),
                        'delete_after_view' => false,
                    ],
                ],
            ),
        ]);
        $checkDuplicateOrderIpsCron->execute();
    }

    /**
     * @param string $event
     * @param mixed[] $expectedData
     *
     * @return MockObject
     */
    private function getMockEventManager(string $event, array $expectedData): MockObject
    {
        $mockEventManager = $this->getMockBuilder(EventManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockEventManager->expects($this->once())
            ->method('dispatch')
            ->with(
                $event,
                $this->callback(function (mixed $data) use ($expectedData): bool {
                    $this->assertIsArray($data);
                    $dataDate = $data['date'] ?? null;
                    $expectedDataDate = $expectedData['date'] ?? null;

                    unset(
                        $data['date'],
                        $expectedData['date'],
                    );

                    $this->assertSame($expectedData, $data);
                    if ($dataDate && $expectedDataDate) {
                        $dataDateUnixtime = strtotime($dataDate);
                        $expectedDataDateUnixtime = strtotime($expectedDataDate);

                        $this->assertLessThanOrEqual($expectedDataDateUnixtime, $dataDateUnixtime - 60);
                        $this->assertGreaterThanOrEqual($expectedDataDateUnixtime, $dataDateUnixtime + 60);
                    } else {
                        $this->assertSame($expectedDataDate, $dataDate);
                    }

                    return true;
                }),
            );

        return $mockEventManager;
    }
}

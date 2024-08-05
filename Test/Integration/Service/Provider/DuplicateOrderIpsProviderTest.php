<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Service\Provider\DuplicateOrderIpsProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\AnalyticsOrderSync\Test\Integration\Traits\CreateOrderInStoreTrait;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\DuplicateOrderIpsProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

class DuplicateOrderIpsProviderTest extends TestCase
{
    use CreateOrderInStoreTrait;
    use ObjectInstantiationTrait;
    use OrderTrait;
    use StoreTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

    private const FIXTURE_REST_AUTH_KEY = 'ABCDE12345';

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
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

        $this->implementationFqcn = DuplicateOrderIpsProvider::class;
        $this->interfaceFqcn = DuplicateOrderIpsProviderInterface::class;

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
     * @return mixed[][]
     */
    public static function dataProvider_testGet(): array
    {
        return [
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'remote_ip',
                7,
                0.1,
                3,
                [
                    '127.0.0.1' => 3,
                ],
            ],
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'remote_ip',
                7,
                0.1,
                null, // Default minimum orders to report is 5
                [],
            ],
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'remote_ip',
                7,
                0.1,
                1,
                [
                    '172.0.1.0' => 3,
                    '127.0.0.1' => 2,
                ],
            ],
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'x_forwarded_for',
                7,
                0.1,
                3,
                [],
            ],
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'x_forwarded_for',
                7,
                0.1,
                null, // Default minimum orders to report is 5
                [],
            ],
            [
                [
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '127.0.0.1',
                        'x_forwarded_for' => '172.16.0.2',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => date('Y-m-d H:i:s'),
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                    [
                        'created_at' => '2024-01-01 00:00:00', // Out of date range
                        'remote_ip' => '172.0.1.0',
                        'x_forwarded_for' => '172.16.0.1',
                        'sync_status' => Statuses::SYNCED,
                    ],
                ],
                'x_forwarded_for',
                7,
                0.1,
                3,
                [
                    '172.16.0.1' => 4,
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testGet
     *
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     *
     * @param mixed[] $fixtures
     * @param string $ipField
     * @param int $periodDays
     * @param float $threshold
     * @param int|null $minimumOrdersToReportThreshold
     * @param mixed[] $expectedResult
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function testGet(
        array $fixtures,
        string $ipField,
        int $periodDays,
        float $threshold,
        ?int $minimumOrdersToReportThreshold,
        array $expectedResult,
    ): void {
        $testStore = $this->createStoreFixture(
            storeCode: 'klevu_analytics_test_store_1',
            website: $this->storeManager->getWebsite('base'),
            klevuApiKey: 'klevu-1234567890',
            syncEnabled: true,
        );

        foreach ($fixtures as $fixture) {
            $orderData = $fixture;
            unset($orderData['sync_status']);

            $this->createOrderInStore(
                storeCode: 'klevu_analytics_test_store_1',
                status: 'pending',
                orderData: $orderData,
                orderItemsData: [
                    [
                        'sku' => 'test_product_' . rand(1, 99999),
                    ],
                ],
                syncStatus: $fixture['sync_status'] ?? Statuses::SYNCED,
            );
        }

        /** @var DuplicateOrderIpsProvider $duplicateOrderIdsProvider */
        $duplicateOrderIdsProvider = $this->instantiateTestObject(
            arguments: array_filter(
                array: [
                    'minimumOrdersToReportThreshold' => $minimumOrdersToReportThreshold,
                ],
                callback: static fn (mixed $value): bool => null !== $value,
            ),
        );
        $actualResult = $duplicateOrderIdsProvider->get(
            storeId: (int)$testStore->getId(),
            ipField: $ipField,
            periodDays: $periodDays,
            threshold: $threshold,
        );

        $this->assertSame($expectedResult, $actualResult);
    }
}

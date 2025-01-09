<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Service\Provider\PermittedOrderStatusProvider;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\PermittedOrderStatusProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

class PermittedOrderStatusProviderTest extends TestCase
{
    use StoreTrait;
    use WebsiteTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    public function testInterfaceGeneration(): void
    {
        $this->assertInstanceOf(
            PermittedOrderStatusProvider::class,
            $this->objectManager->get(PermittedOrderStatusProviderInterface::class),
        );
    }

    public function testGetForStore(): void
    {
        $this->createWebsiteAndStoreFixtures();
        $this->setConfigForStoreFixtures();

        $allOrderStatuses = $this->getAllOrderStatuses();
        $this->assertNotEmpty($allOrderStatuses);

        /** @var PermittedOrderStatusProvider $permittedOrderStatusProvider */
        $permittedOrderStatusProvider = $this->objectManager->get(PermittedOrderStatusProvider::class);

        // test_store_1
        $store = $this->storeFixturesPool->get('test_store_1');
        $this->assertSame(
            expected: $allOrderStatuses,
            actual: $permittedOrderStatusProvider->getForStore((int)$store->getId()),
            message: $store->getCode(),
        );

        // test_store_2
        $store = $this->storeFixturesPool->get('test_store_2');
        $this->assertSame(
            expected: array_filter(
                $allOrderStatuses,
                static fn (string $orderStatus): bool => match ($orderStatus) {
                    'fraud', 'holded' => false,
                    default => true,
                },
            ),
            actual: $permittedOrderStatusProvider->getForStore((int)$store->getId()),
            message: $store->getCode(),
        );

        // test_store_3
        $store = $this->storeFixturesPool->get('test_store_3');
        $this->assertSame(
            expected: array_filter(
                $allOrderStatuses,
                static fn (string $orderStatus): bool => match ($orderStatus) {
                    'canceled',
                    'fraud',
                    'holded',
                    'payment_review',
                    'pending',
                    'pending_payment' => false,
                    default => true,
                },
            ),
            actual: $permittedOrderStatusProvider->getForStore((int)$store->getId()),
            message: $store->getCode(),
        );
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function createWebsiteAndStoreFixtures(): void
    {
        $this->createWebsite([
            'code' => 'klevu_analytics_test_website_1',
            'key' => 'test_website_1',
        ]);
        $website1 = $this->websiteFixturesPool->get('test_website_1');

        $this->createWebsite([
            'code' => 'klevu_analytics_test_website_2',
            'key' => 'test_website_2',
        ]);
        $website2 = $this->websiteFixturesPool->get('test_website_2');

        $this->createStore([
            'website_id' => $website1->getId(),
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
            'is_active' => false,
        ]);
        $this->createStore([
            'website_id' => $website1->getId(),
            'code' => 'klevu_analytics_test_store_2',
            'key' => 'test_store_2',
            'is_active' => true,
        ]);
        $this->createStore([
            'website_id' => $website2->getId(),
            'code' => 'klevu_analytics_test_store_3',
            'key' => 'test_store_3',
            'is_active' => true,
        ]);
    }

    /**
     * @return void
     */
    private function setConfigForStoreFixtures(): void
    {
        ConfigFixture::setGlobal(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: 'fraud,holded',
        );

        // test_store_1
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: '',
            storeCode: 'klevu_analytics_test_store_1',
        );
        // test_store_2 : Nothing - check that cascade from default works as expected
        // test_store_3
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: 'canceled,fraud,holded,payment_review,pending,pending_payment',
            storeCode: 'klevu_analytics_test_store_3',
        );
    }

    /**
     * @return string[]
     */
    private function getAllOrderStatuses(): array
    {
        /** @var OrderStatusCollection $orderStatusCollection */
        $orderStatusCollection = $this->objectManager->create(OrderStatusCollection::class);

        return array_keys(
            $orderStatusCollection->toOptionHash(),
        );
    }
}

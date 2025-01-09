<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Service\Provider\SyncEnabledStoresProvider;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method SyncEnabledStoresProvider instantiateTestObject(?array $arguments = null)
 * @method SyncEnabledStoresProvider instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncEnabledStoresProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
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

        $this->implementationFqcn = SyncEnabledStoresProvider::class;
        $this->interfaceFqcn = SyncEnabledStoresProviderInterface::class;

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGet(): void
    {
        $this->createWebsiteAndStoreFixtures();
        $this->setConfigForStoreFixtures();

        $syncEnabledStoresProvider = $this->instantiateTestObject();

        $this->assertEquals(
            expected: [
                'klevu_analytics_test_store_2',
                'klevu_analytics_test_store_5',
            ],
            actual: array_values(array_map(
                static fn (StoreInterface $store): string => $store->getCode(),
                $syncEnabledStoresProvider->get(),
            )),
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
            'website_id' => $website1->getId(),
            'code' => 'klevu_analytics_test_store_3',
            'key' => 'test_store_3',
            'is_active' => true,
        ]);
        $this->createStore([
            'website_id' => $website2->getId(),
            'code' => 'klevu_analytics_test_store_4',
            'key' => 'test_store_4',
            'is_active' => true,
        ]);
        $this->createStore([
            'website_id' => $website2->getId(),
            'code' => 'klevu_analytics_test_store_5',
            'key' => 'test_store_5',
            'is_active' => true,
        ]);
    }

    /**
     * @return void
     */
    private function setConfigForStoreFixtures(): void
    {
        ConfigFixture::setGlobal(
            path: 'klevu/analytics_order_sync/enabled',
            value: '1',
        );
        ConfigFixture::setGlobal(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-9876543210',
        );

        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);
        foreach ($storeManager->getStores() as $store) {
            ConfigFixture::setForStore(
                path: 'klevu/analytics_order_sync/enabled',
                value: '0',
                storeCode: $store->getCode(),
            );
            ConfigFixture::setForStore(
                path: 'klevu_configuration/auth_keys/js_api_key',
                value: null,
                storeCode: $store->getCode(),
            );
        }

        // Store 1 (inactive)
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/enabled',
            value: '1',
            storeCode: 'klevu_analytics_test_store_1',
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_analytics_test_store_1',
        );

        // Store 2 (active)
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/enabled',
            value: '1',
            storeCode: 'klevu_analytics_test_store_2',
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_analytics_test_store_2',
        );

        // Store 3 (active)
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/enabled',
            value: '1',
            storeCode: 'klevu_analytics_test_store_3',
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: '',
            storeCode: 'klevu_analytics_test_store_3',
        );

        // Store 4 (active)
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/enabled',
            value: '0',
            storeCode: 'klevu_analytics_test_store_4',
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_analytics_test_store_4',
        );

        // Store 5 (active)
        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/enabled',
            value: '1',
            storeCode: 'klevu_analytics_test_store_5',
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_analytics_test_store_5',
        );
    }
}

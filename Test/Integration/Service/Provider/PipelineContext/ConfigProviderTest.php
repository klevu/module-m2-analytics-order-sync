<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider\PipelineContext;

use Klevu\AnalyticsOrderSync\Service\Provider\PipelineContext\ConfigProvider;
use Klevu\Configuration\Service\Provider\StoreScopeProviderInterface;
use Klevu\PlatformPipelines\Api\PipelineContextProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @method ConfigProvider instantiateTestObject(?array $arguments = null)
 */
class ConfigProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use StoreTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var StoreScopeProviderInterface|null
     */
    private ?StoreScopeProviderInterface $storeScopeProvider = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = ConfigProvider::class;
        $this->interfaceFqcn = PipelineContextProviderInterface::class;

        $this->storeScopeProvider = $this->objectManager->get(StoreScopeProviderInterface::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->storeFixturesPool->rollback();
    }

    public function testGet(): void
    {
        $configProvider = $this->instantiateTestObject();

        $this->assertSame($configProvider, $configProvider->get());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetForCurrentStore(): void
    {
        $this->createStoreFixtures();
        $this->setConfigForStoreFixtures();

        $configProvider = $this->instantiateTestObject();

        $this->storeScopeProvider->setCurrentStoreById(1);
        $this->assertSame(
            expected: [
                'ip_address_accessor' => 'getRemoteIp()',
                'excluded_order_statuses' => [
                    'holded',
                    'fraud',
                ],
            ],
            actual: $configProvider->getForCurrentStore(),
        );

        $testStore = $this->storeFixturesPool->get('test_store_1');
        $this->storeScopeProvider->setCurrentStoreById((int)$testStore->getId());
        $this->assertSame(
            expected: [
                'ip_address_accessor' => 'getXForwardedFor()',
                'excluded_order_statuses' => [],
            ],
            actual: $configProvider->getForCurrentStore(),
        );
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function createStoreFixtures(): void
    {
        $this->createStore([
            'code' => 'klevu_analytics_test_store_1',
            'key' => 'test_store_1',
        ]);
    }

    /**
     * @return void
     */
    private function setConfigForStoreFixtures(): void
    {
        ConfigFixture::setGlobal(
            path: 'klevu/analytics_order_sync/ip_address_attribute',
            value: 'x_forwarded_for',
        );
        ConfigFixture::setGlobal(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: 'holded,fraud',
        );

        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/ip_address_attribute',
            value: 'remote_ip',
            storeCode: 'default',
        );

        ConfigFixture::setForStore(
            path: 'klevu/analytics_order_sync/exclude_status_from_sync',
            value: '',
            storeCode: 'klevu_analytics_test_store_1',
        );
    }
}

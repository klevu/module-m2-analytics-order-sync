<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Observer\Admin\System\Config;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Observer\Admin\System\Config\UpdateOrderSyncCron;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ConsolidateCronConfigSettingsActionInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @method UpdateOrderSyncCron instantiateTestObject(?array $arguments = null)
 * @magentoAppArea adminhtml
 */
class UpdateOrderSyncCronTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;

    private const OBSERVER_NAME = 'klevu_analyticsOrderSync_adminSystemConfig_updateOrderSyncCron';
    private const EVENT_NAME = 'admin_system_config_changed_section_klevu_data_sync';

    private ?ObjectManagerInterface $objectManager = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = UpdateOrderSyncCron::class;
        $this->interfaceFqcn = ObserverInterface::class;
    }

    /**
     * @magentoAppArea global
     */
    public function testObserver_IsNotConfigured_InGlobalScope(): void
    {
        $observerConfig = $this->objectManager->create(EventConfig::class);
        $observers = $observerConfig->getObservers(self::EVENT_NAME);

        $this->assertArrayNotHasKey(
            key: self::OBSERVER_NAME,
            array: $observers,
        );
    }

    public function testObserver_IsConfiguredInAdminScope(): void
    {
        $observerConfig = $this->objectManager->create(EventConfig::class);
        $observers = $observerConfig->getObservers(self::EVENT_NAME);

        $this->assertArrayHasKey(
            key: self::OBSERVER_NAME,
            array: $observers,
        );
        $this->assertSame(
            expected: ltrim(
                string: UpdateOrderSyncCron::class,
                characters: '\\',
            ),
            actual: $observers[self::OBSERVER_NAME]['instance'],
        );
    }

    public function testExecute_NoPathsChanged(): void
    {
        $mockConsolidateCronConfigSettings = $this->getMockConsolidateCronConfigSettingsAction();
        $mockConsolidateCronConfigSettings->expects($this->never())
            ->method('execute');

        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class, [
            'data' => [
                'changed_paths' => [],
            ],
        ]);

        $updateOrderSyncCron = $this->instantiateTestObject([
            'consolidateCronConfigSettingsAction' => $mockConsolidateCronConfigSettings,
        ]);

        $updateOrderSyncCron->execute($observer);
    }

    public function testExecute_UnrelatedPathsChanged(): void
    {
        $mockConsolidateCronConfigSettings = $this->getMockConsolidateCronConfigSettingsAction();
        $mockConsolidateCronConfigSettings->expects($this->never())
            ->method('execute');

        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class, [
            'data' => [
                'changed_paths' => [
                    Constants::XML_PATH_ORDER_SYNC_ENABLED,
                ],
            ],
        ]);

        $updateOrderSyncCron = $this->instantiateTestObject([
            'consolidateCronConfigSettingsAction' => $mockConsolidateCronConfigSettings,
        ]);

        $updateOrderSyncCron->execute($observer);
    }

    public function testExecute_FrequencyPathChanged(): void
    {
        $mockConsolidateCronConfigSettings = $this->getMockConsolidateCronConfigSettingsAction();
        $mockConsolidateCronConfigSettings->expects($this->once())
            ->method('execute');

        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class, [
            'data' => [
                'changed_paths' => [
                    Constants::XML_PATH_ORDER_SYNC_ENABLED,
                    Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                ],
            ],
        ]);

        $updateOrderSyncCron = $this->instantiateTestObject([
            'consolidateCronConfigSettingsAction' => $mockConsolidateCronConfigSettings,
        ]);

        $updateOrderSyncCron->execute($observer);
    }

    public function testExecute_ExpressionPathChanged(): void
    {
        $mockConsolidateCronConfigSettings = $this->getMockConsolidateCronConfigSettingsAction();
        $mockConsolidateCronConfigSettings->expects($this->once())
            ->method('execute');

        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class, [
            'data' => [
                'changed_paths' => [
                    Constants::XML_PATH_ORDER_SYNC_ENABLED,
                    Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ],
            ],
        ]);

        $updateOrderSyncCron = $this->instantiateTestObject([
            'consolidateCronConfigSettingsAction' => $mockConsolidateCronConfigSettings,
        ]);

        $updateOrderSyncCron->execute($observer);
    }

    public function testExecute_BothPathsChanges(): void
    {
        $mockConsolidateCronConfigSettings = $this->getMockConsolidateCronConfigSettingsAction();
        $mockConsolidateCronConfigSettings->expects($this->once())
            ->method('execute');

        /** @var Observer $observer */
        $observer = $this->objectManager->create(Observer::class, [
            'data' => [
                'changed_paths' => [
                    Constants::XML_PATH_ORDER_SYNC_CRON_FREQUENCY,
                    Constants::XML_PATH_ORDER_SYNC_CRON_EXPR,
                ],
            ],
        ]);

        $updateOrderSyncCron = $this->instantiateTestObject([
            'consolidateCronConfigSettingsAction' => $mockConsolidateCronConfigSettings,
        ]);

        $updateOrderSyncCron->execute($observer);
    }

    /**
     * @return MockObject&ConsolidateCronConfigSettingsActionInterface
     */
    private function getMockConsolidateCronConfigSettingsAction(): MockObject&ConsolidateCronConfigSettingsActionInterface // phpcs:ignore Generic.Files.LineLength.TooLong
    {
        return $this->getMockBuilder(ConsolidateCronConfigSettingsActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

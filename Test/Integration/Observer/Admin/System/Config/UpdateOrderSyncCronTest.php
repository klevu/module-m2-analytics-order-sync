<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Observer\Admin\System\Config;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Observer\Admin\System\Config\UpdateOrderSyncCron;
use Klevu\AnalyticsOrderSync\Observer\Sales\Order\QueueOrderForSync;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ConsolidateCronConfigSettingsActionInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @method QueueOrderForSync instantiateTestObject(?array $arguments = null)
 */
class UpdateOrderSyncCronTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;

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

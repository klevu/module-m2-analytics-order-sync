<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Observer\Admin\System\Config;

use Klevu\AnalyticsOrderSync\Cron\MigrateLegacyOrderSyncRecords as MigrateLegacyOrderSyncRecordsCron;
use Klevu\AnalyticsOrderSync\Observer\Admin\System\Config\MigrateLegacyOrderSyncRecordsAfterIntegration;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Cron\Model\ResourceModel\Schedule\Collection as CronScheduleCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronScheduleCollectionFactory;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @method MigrateLegacyOrderSyncRecordsAfterIntegration instantiateTestObject(?array $arguments = null)
 * @magentoAppArea adminhtml
 */
class MigrateLegacyOrderSyncRecordsAfterIntegrationTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;

    private const OBSERVER_NAME = 'klevu_analyticsOrderSync_migrateLegacyOrderSyncRecords';
    private const EVENT_NAME = 'klevu_integrate_api_keys_after';

    /**
     * @var ObjectManagerInterface
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var CronScheduleCollectionFactory|null
     */
    private ?CronScheduleCollectionFactory $cronScheduleCollectionFactory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = MigrateLegacyOrderSyncRecordsAfterIntegration::class;
        $this->interfaceFqcn = ObserverInterface::class;

        $this->cronScheduleCollectionFactory = $this->objectManager->get(CronScheduleCollectionFactory::class);
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
                string: MigrateLegacyOrderSyncRecordsAfterIntegration::class,
                characters: '\\',
            ),
            actual: $observers[self::OBSERVER_NAME]['instance'],
        );
    }

    /**
     * @return void
     */
    public function testExecute(): void
    {
        $initialCronScheduleCollection = $this->getCronScheduleCollection();
        $this->assertSame(
            expected: 0,
            actual: $initialCronScheduleCollection->getSize(),
            message: 'Initial Cron Schedule count',
        );

        $mockObserver = $this->getMockObserver();

        $migrateLegacyOrderSyncRecordsAfterIntegration = $this->instantiateTestObject();
        $migrateLegacyOrderSyncRecordsAfterIntegration->execute(
            observer: $mockObserver,
        );

        $finalCronScheduleCollection = $this->getCronScheduleCollection();
        $this->assertSame(
            expected: 1,
            actual: $finalCronScheduleCollection->getSize(),
            message: 'Final Cron Schedule count',
        );

        $cronSchedule = $finalCronScheduleCollection->getFirstItem();
        $scheduledAtUnixtime = strtotime($cronSchedule->getScheduledAt());
        $this->assertGreaterThanOrEqual(
            expected: time(),
            actual: $scheduledAtUnixtime,
        );
        $this->assertLessThanOrEqual(
            expected: time() + 300,
            actual: $scheduledAtUnixtime,
        );
    }

    /**
     * @return CronScheduleCollection
     */
    private function getCronScheduleCollection(): CronScheduleCollection
    {
        $cronScheduleCollection = $this->cronScheduleCollectionFactory->create();
        $cronScheduleCollection->addFieldToFilter(
            field: 'job_code',
            condition: ['eq' => MigrateLegacyOrderSyncRecordsCron::JOB_CODE],
        );
        $cronScheduleCollection->addFieldToFilter(
            field: 'status',
            condition: ['eq' => 'pending'],
        );
        $cronScheduleCollection->addFieldToFilter(
            field: 'created_at',
            condition: [
                'gt' => date('Y-m-d H:i:s', time() - 30),
            ],
        );

        return $cronScheduleCollection;
    }

    /**
     * @return MockObject&Observer
     */
    private function getMockObserver(): MockObject
    {
        return $this->getMOckBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

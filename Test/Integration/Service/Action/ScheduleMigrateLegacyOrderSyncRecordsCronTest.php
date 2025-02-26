<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Action;

use Klevu\AnalyticsOrderSync\Service\Action\ScheduleMigrateLegacyOrderSyncRecordsCron;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ScheduleMigrateLegacyOrderSyncRecordsCronActionInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Cron\Model\ResourceModel\Schedule\Collection as CronScheduleCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronScheduleCollectionFactory;
use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory as CronScheduleFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @method ScheduleMigrateLegacyOrderSyncRecordsCron instantiateTestObject(?array $arguments = null)
 * @method ScheduleMigrateLegacyOrderSyncRecordsCron instantiateTestObjectFromInterface(?array $arguments = null)
 */
class ScheduleMigrateLegacyOrderSyncRecordsCronTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

    /**
     * @var ObjectManagerInterface|null
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

        $this->implementationFqcn = ScheduleMigrateLegacyOrderSyncRecordsCron::class;
        $this->interfaceFqcn = ScheduleMigrateLegacyOrderSyncRecordsCronActionInterface::class;

        $this->cronScheduleCollectionFactory = $this->objectManager->get(CronScheduleCollectionFactory::class);
    }

    public function testExecute_SaveThrowsException(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [LogLevel::ERROR],
        );
        $mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Encountered error scheduling migration of legacy order sync records',
                $this->callback(
                    static function (array $context): bool {
                        return isset($context['exception'])
                            && $context['exception'] instanceof CouldNotSaveException
                            && isset($context['error'])
                            && $context['error'] === 'Test exception'
                            && isset($context['cronSchedule'])
                            && is_array($context['cronSchedule']);
                    },
                ),
            );

        $mockCronScheduleObject = $this->getMockCronScheduleObject();
        $mockCronScheduleObject->expects($this->once())
            ->method('save')
            ->willThrowException(
                exception: new CouldNotSaveException(
                    phrase: __('Test exception'),
                ),
            );

        $mockCronScheduleFactory = $this->getMockCronScheduleFactory();
        $mockCronScheduleFactory->expects($this->once())
            ->method('create')
            ->willReturn($mockCronScheduleObject);

        $scheduleMigrateLegacyOrderSyncRecordsCronAction = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
                'cronScheduleFactory' => $mockCronScheduleFactory,
            ],
        );

        $cronScheduleCollectionBefore = $this->createCronScheduleCollection();
        $countBefore = $cronScheduleCollectionBefore->getSize();

        $scheduleMigrateLegacyOrderSyncRecordsCronAction->execute();

        $cronScheduleCollectionAfter = $this->createCronScheduleCollection();
        $this->assertSame(
            expected: $countBefore,
            actual: $cronScheduleCollectionAfter->getSize(),
            message: 'Cron schedule collection after',
        );
    }

    public function testExecute_WithSpecifiedScheduleAt(): void
    {
        $scheduleAt = new \DateTimeImmutable(
            datetime: '2024-12-31 23:59:59',
        );

        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [LogLevel::INFO],
        );
        $mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Scheduled migration of legacy order sync records for {scheduleTime}',
                $this->callback(
                    static function (array $context): bool {
                        return isset($context['scheduleTime'])
                            && $context['scheduleTime'] === '2024-12-31 23:59:59'
                            && isset($context['cronSchedule'])
                            && is_array($context['cronSchedule']);
                    },
                ),
            );

        $scheduleMigrateLegacyOrderSyncRecordsCronAction = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $cronScheduleCollectionBefore = $this->createCronScheduleCollection();
        $idsBefore = $cronScheduleCollectionBefore->getAllIds();

        $scheduleMigrateLegacyOrderSyncRecordsCronAction->execute(
            scheduleAt: $scheduleAt,
        );

        $cronScheduleCollectionAfter = $this->createCronScheduleCollection();
        $this->assertSame(
            expected: count($idsBefore) + 1,
            actual: $cronScheduleCollectionAfter->getSize(),
        );

        $newCronSchedule = current(
            array: array_filter(
                array: $cronScheduleCollectionAfter->getItems(),
                callback: static fn (CronSchedule $cronSchedule): bool => !in_array(
                    needle: $cronSchedule->getId(),
                    haystack: $idsBefore,
                    strict: true,
                ),
            ),
        );
        $createdAtUnixtime = strtotime($newCronSchedule->getCreatedAt());
        $this->assertLessThanOrEqual(
            expected: 30,
            actual: time() - $createdAtUnixtime,
            message: 'Cron schedule created within 30 seconds',
        );
        $this->assertGreaterThanOrEqual(
            expected: 0,
            actual: time() - $createdAtUnixtime,
            message: 'Cron schedule created after 0 seconds',
        );

        $this->assertSame(
            expected: '2024-12-31 23:59:59',
            actual: $newCronSchedule->getScheduledAt(),
        );
    }

    public function testExecute(): void
    {
        $mockLogger = $this->getMockLogger(
            expectedLogLevels: [LogLevel::INFO],
        );
        $mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Scheduled migration of legacy order sync records for {scheduleTime}',
                $this->callback(
                    static function (array $context): bool {
                        return isset($context['scheduleTime'])
                            && isset($context['cronSchedule'])
                            && is_array($context['cronSchedule']);
                    },
                ),
            );

        $scheduleMigrateLegacyOrderSyncRecordsCronAction = $this->instantiateTestObject(
            arguments: [
                'logger' => $mockLogger,
            ],
        );

        $cronScheduleCollectionBefore = $this->createCronScheduleCollection();
        $idsBefore = $cronScheduleCollectionBefore->getAllIds();

        $scheduleMigrateLegacyOrderSyncRecordsCronAction->execute();

        $cronScheduleCollectionAfter = $this->createCronScheduleCollection();
        $this->assertSame(
            expected: count($idsBefore) + 1,
            actual: $cronScheduleCollectionAfter->getSize(),
        );

        $newCronSchedule = current(
            array: array_filter(
                array: $cronScheduleCollectionAfter->getItems(),
                callback: static fn (CronSchedule $cronSchedule): bool => !in_array(
                    needle: $cronSchedule->getId(),
                    haystack: $idsBefore,
                    strict: true,
                ),
            ),
        );
        $createdAtUnixtime = strtotime($newCronSchedule->getCreatedAt());
        $this->assertLessThanOrEqual(
            expected: 30,
            actual: time() - $createdAtUnixtime,
            message: 'Cron schedule created within 30 seconds',
        );
        $this->assertGreaterThanOrEqual(
            expected: 0,
            actual: time() - $createdAtUnixtime,
            message: 'Cron schedule created after 0 seconds',
        );

        $this->assertSame(
            expected: date(
                format: 'Y-m-d H:i:s',
                timestamp: $createdAtUnixtime + 60,
            ),
            actual: $newCronSchedule->getScheduledAt(),
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

    /**
     * @return MockObject&CronScheduleFactory
     */
    private function getMockCronScheduleFactory(): MockObject
    {
        return $this->getMockBuilder(CronScheduleFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return MockObject&CronSchedule
     */
    private function getMockCronScheduleObject(): MockObject
    {
        return $this->getMockBuilder(CronSchedule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
    }

    /**
     * @return CronScheduleCollection
     */
    private function createCronScheduleCollection(): CronScheduleCollection
    {
        $cronScheduleCollection = $this->cronScheduleCollectionFactory->create();

        $cronScheduleCollection->addFieldToFilter(
            field: 'job_code',
            condition: [
                'eq' => 'klevu_analytics_migrate_legacy_order_sync_records',
            ],
        );
        $cronScheduleCollection->addFieldToFilter(
            field: 'status',
            condition: [
                'eq' => 'pending',
            ],
        );

        return $cronScheduleCollection;
    }
}

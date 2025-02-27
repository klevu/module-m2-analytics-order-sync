<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Action;

use Klevu\AnalyticsOrderSync\Cron\MigrateLegacyOrderSyncRecords as MigrateLegacyOrderSyncRecordsCron;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ScheduleMigrateLegacyOrderSyncRecordsCronActionInterface;
use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory as CronScheduleFactory;
use Psr\Log\LoggerInterface;

class ScheduleMigrateLegacyOrderSyncRecordsCron implements ScheduleMigrateLegacyOrderSyncRecordsCronActionInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var CronScheduleFactory
     */
    private readonly CronScheduleFactory $cronScheduleFactory;

    /**
     * @param LoggerInterface $logger
     * @param CronScheduleFactory $cronScheduleFactory
     */
    public function __construct(
        LoggerInterface $logger,
        CronScheduleFactory $cronScheduleFactory,
    ) {
        $this->logger = $logger;
        $this->cronScheduleFactory = $cronScheduleFactory;
    }

    public function execute(
        ?\DateTimeInterface $scheduleAt = null,
    ): bool {
        $cronSchedule = $this->createCronScheduleObject($scheduleAt);

        $return = true;
        try {
            // No repository for cron schedule
            $cronSchedule->save();

            $this->logger->info(
                message: 'Scheduled migration of legacy order sync records for {scheduleTime}',
                context: [
                    'method' => __METHOD__,
                    'scheduleTime' => $cronSchedule->getScheduledAt(),
                    'cronSchedule' => $cronSchedule->getData(),
                ],
            );
        } catch (\Exception $exception) {
            $return = false;
            $this->logger->error(
                message: 'Encountered error scheduling migration of legacy order sync records',
                context: [
                    'method' => __METHOD__,
                    'exception' => $exception,
                    'error' => $exception->getMessage(),
                    'scheduleTime' => $cronSchedule->getScheduledAt(),
                    'cronSchedule' => $cronSchedule->getData(),
                ],
            );
        }

        return $return;
    }

    /**
     * @param \DateTimeInterface|null $scheduleAt
     *
     * @return CronSchedule
     */
    private function createCronScheduleObject(
        ?\DateTimeInterface $scheduleAt = null,
    ): CronSchedule {
        $cronSchedule = $this->cronScheduleFactory->create();

        $now = new \DateTimeImmutable();
        $scheduleAt ??= $now->add(
            interval: new \DateInterval('PT1M'),
        );

        $cronSchedule->setJobCode(MigrateLegacyOrderSyncRecordsCron::JOB_CODE);
        $cronSchedule->setCreatedAt($now->format('Y-m-d H:i:s'));
        $cronSchedule->setScheduledAt($scheduleAt->format('Y-m-d H:i:s'));
        $cronSchedule->setStatus($cronSchedule::STATUS_PENDING);

        return $cronSchedule;
    }
}
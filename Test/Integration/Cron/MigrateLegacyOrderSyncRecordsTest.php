<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Cron;

use Klevu\AnalyticsOrderSync\Cron\MigrateLegacyOrderSyncRecords;
use Klevu\AnalyticsOrderSync\Service\MigrateLegacyOrderSyncRecords as MigrateLegacyOrderSyncRecordsService;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @magentoAppIsolation enabled
 * @method MigrateLegacyOrderSyncRecords instantiateTestObject(?array $arguments = null)
 */
class MigrateLegacyOrderSyncRecordsTest extends TestCase
{
    use ObjectInstantiationTrait;

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

        $this->implementationFqcn = MigrateLegacyOrderSyncRecords::class;
    }

    /**
     * @return void
     */
    public function testExecute(): void
    {
        $mockMigrateLegacyOrderSyncRecordsService = $this->getMockMigrateLegacyOrderSyncRecordsService();
        $mockMigrateLegacyOrderSyncRecordsService->expects($this->once())
            ->method('executeForAllStores');

        $migrateLegacyOrderSyncRecords = $this->instantiateTestObject(
            arguments: [
                'migrateLegacyOrderSyncRecordsService' => $mockMigrateLegacyOrderSyncRecordsService,
            ],
        );
        $migrateLegacyOrderSyncRecords->execute();
    }

    /**
     * @return MockObject|MigrateLegacyOrderSyncRecordsService
     */
    private function getMockMigrateLegacyOrderSyncRecordsService(): MockObject
    {
        return $this->getMockBuilder(MigrateLegacyOrderSyncRecordsService::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
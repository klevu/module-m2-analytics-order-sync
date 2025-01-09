<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model;

use Klevu\AnalyticsOrderSync\Model\MarkOrderActionResult;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Klevu\AnalyticsOrderSyncApi\Api\Data\MarkOrderActionResultInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method MarkOrderActionResult instantiateTestObject(?array $arguments = null)
 * @method MarkOrderActionResult instantiateTestObjectFromInterface(?array $arguments = null)
 */
class MarkOrderActionResultTest extends TestCase
{
    use ObjectInstantiationTrait {
        testFqcnResolvesToExpectedImplementation as trait_testFqcnResolvesToExpectedImplementation;
    }
    use TestFactoryGenerationTrait {
        testImplementationGeneratesFromFactory as trait_testImplementationGeneratesFromFactory;
    }

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

        $this->implementationFqcn = MarkOrderActionResult::class;
        $this->interfaceFqcn = MarkOrderActionResultInterface::class;

        $syncOrderRecord = $this->objectManager->create(SyncOrderModel::class);
        $syncOrderHistoryRecord = $this->objectManager->create(SyncOrderHistoryModel::class);
        $messages = [
            'foo',
            __('bar'),
        ];
        $this->constructorArgumentDefaults = [
            'success' => true,
            'syncOrderRecord' => $syncOrderRecord,
            'syncOrderHistoryRecord' => $syncOrderHistoryRecord,
            'messages' => $messages,
        ];
    }

    /**
     * @group objectInstantiation
     */
    public function testCreate(): void
    {
        $syncOrderRecord = $this->objectManager->create(SyncOrderModel::class);
        $syncOrderHistoryRecord = $this->objectManager->create(SyncOrderHistoryModel::class);
        $messages = [
            'foo',
            __('bar'),
        ];

        $markOrderActionResult = new MarkOrderActionResult(
            success: true,
            syncOrderRecord: $syncOrderRecord,
            syncOrderHistoryRecord: $syncOrderHistoryRecord,
            messages: $messages,
        );

        $this->assertTrue($markOrderActionResult->isSuccess());
        $this->assertSame($syncOrderRecord, $markOrderActionResult->getSyncOrderRecord());
        $this->assertSame($syncOrderHistoryRecord, $markOrderActionResult->getSyncOrderHistoryRecord());
        $this->assertSame($messages, $markOrderActionResult->getMessages());
    }

    /**
     * @group objectInstantiation
     */
    public function testFqcnResolvesToExpectedImplementation(): object
    {
        /** @var MarkOrderActionResult $testObject */
        $testObject = $this->trait_testFqcnResolvesToExpectedImplementation();

        $this->assertTrue($testObject->isSuccess());
        $this->assertSame(
            $this->constructorArgumentDefaults['syncOrderRecord'],
            $testObject->getSyncOrderRecord(),
        );
        $this->assertSame(
            $this->constructorArgumentDefaults['syncOrderHistoryRecord'],
            $testObject->getSyncOrderHistoryRecord(),
        );
        $this->assertSame(
            $this->constructorArgumentDefaults['messages'],
            $testObject->getMessages(),
        );

        return $testObject;
    }

    /**
     * @group objectInstantiation
     */
    public function testImplementationGeneratesFromFactory(): object
    {
        /** @var MarkOrderActionResult $testObject */
        $testObject = $this->trait_testImplementationGeneratesFromFactory();

        $this->assertTrue($testObject->isSuccess());
        $this->assertSame(
            $this->constructorArgumentDefaults['syncOrderRecord'],
            $testObject->getSyncOrderRecord(),
        );
        $this->assertSame(
            $this->constructorArgumentDefaults['syncOrderHistoryRecord'],
            $testObject->getSyncOrderHistoryRecord(),
        );
        $this->assertSame(
            $this->constructorArgumentDefaults['messages'],
            $testObject->getMessages(),
        );

        return $testObject;
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model\ResourceModel\SyncOrderHistory;

use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory\Collection as SyncOrderHistoryCollection;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderHistoryCollection instantiateTestObject(?array $arguments = null)
 */
class CollectionTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestFactoryGenerationTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line Used by traits

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = SyncOrderHistoryCollection::class;
        // \Magento\Theme\Plugin\Data\Collection
        $this->expectPlugins = true;
    }

    public function testAddFieldToFilter_GeneratedSql_NestingDepthOne(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: 'entity_id',
            condition: ['eq' => 1],
        );
        $collection->addFieldToFilter(
            field: 'sync_order_id',
            condition: ['in' => [1, 2, 3]],
        );

        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order_history` AS `main_table` "
            . "WHERE \(`main_table`\.`entity_id` = 1\) AND \(`main_table`\.`sync_order_id` IN\(1, 2, 3\)\)$/",
            (string)$collection->getSelect(),
        );
    }

    public function testAddFieldToFilter_GeneratedSql_NestingDepthTwo(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: [
                'entity_id',
                'sync_order_id',
            ],
            condition: [
                ['eq' => 1],
                ['in' => [1, 2, 3]],
            ],
        );

        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order_history` AS `main_table` "
            . "WHERE \(\(`main_table`\.`entity_id` = 1\) OR \(`main_table`\.`sync_order_id` IN\(1, 2, 3\)\)\)$/",
            (string)$collection->getSelect(),
        );
    }

    public function testAddFieldToFilter_GeneratedSql_NestingDepthThree(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: [
                [
                    'entity_id',
                    'sync_order_id',
                ],
            ],
            condition: [
                [
                    ['eq' => 1],
                    ['in' => [1, 2, 3]],
                ],
            ],
        );

        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order_history` AS `main_table` "
            . "WHERE \(\(\(`main_table`\.`entity_id` = 1\) AND \(`main_table`\.`sync_order_id` IN\(1, 2, 3\)\)\)\)$/",
            (string)$collection->getSelect(),
        );
    }

    public function testAddFieldToFilter_GeneratedSql_MixedNestingDepth(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: 'entity_id',
            condition: '1',
        );
        $collection->addFieldToFilter(
            field: [
                'sync_order_id',
                'via',
                [
                    'action',
                    'result',
                ],
            ],
            condition: [
                ['in' => [1, 2, 3]],
                ['nlike' => 'Cron%'],
                [
                    'queue',
                    ['in' => ['success']],
                ],
            ],
        );

        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order_history` AS `main_table` "
            . "WHERE \(`main_table`\.`entity_id` = '1'\) "
                . "AND \("
                    . "\(`main_table`\.`sync_order_id` IN\(1, 2, 3\)\) "
                    . "OR \(`main_table`\.`via` NOT LIKE 'Cron%'\) "
                    . "OR \("
                        . "\(`main_table`\.`action` = 'queue'\) "
                        . "AND \(`main_table`\.`result` IN\('success'\)\)"
                    . "\)"
                . "\)"
            . "$/",
            (string)$collection->getSelect(),
        );
    }

    public function testAddFieldToFilter_GeneratedSql_JoinedTables(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: 'sync_status',
            condition: ['in' => ['synced']],
        );
        $collection->addFieldToFilter(
            field: 'sync_status',
            condition: ['neq' => 'retry'],
        );

        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order_history` AS `main_table`\s+"
            . "INNER JOIN `.*klevu_sync_order` ON klevu_sync_order\.entity_id = main_table\.sync_order_id "
            . "WHERE \(`klevu_sync_order`.`status` IN\('synced'\)\) "
            . "AND \(`klevu_sync_order`\.`status` != 'retry'\)"
            . "$/",
            (string)$collection->getSelect(),
        );
    }
}

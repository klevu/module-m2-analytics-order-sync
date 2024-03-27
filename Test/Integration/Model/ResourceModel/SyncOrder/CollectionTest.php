<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Classes.ClassStructure.IncorrectGroupOrder
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Klevu\AnalyticsOrderSync\Test\Integration\Model\ResourceModel\SyncOrder;

use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder\Collection as SyncOrderCollection;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestFactoryGenerationTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncOrderCollection instantiateTestObject(?array $arguments)
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

        $this->implementationFqcn = SyncOrderCollection::class;
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
            field: 'status',
            condition: ['in' => ['queued', 'synced']],
        );

        /*
         * SELECT `main_table`.*
         * FROM `klevu_sync_order` AS `main_table`
         * WHERE (`entity_id` = 1)
         */
        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
                . "FROM `.*klevu_sync_order` AS `main_table` "
                . "WHERE \(`main_table`\.`entity_id` = 1\) AND \(`main_table`\.`status` IN\('queued', 'synced'\)\)$/",
            (string)$collection->getSelect(),
        );
    }

    public function testAddFieldToFilter_GeneratedSql_NestingDepthTwo(): void
    {
        $collection = $this->instantiateTestObject([]);

        $collection->addFieldToFilter(
            field: [
                'entity_id',
                'status',
            ],
            condition: [
                ['eq' => 1],
                ['in' => ['queued', 'synced']],
            ],
        );

        /*
         * SELECT `main_table`.*
         * FROM `klevu_sync_order` AS `main_table`
         * WHERE ((`entity_id` = 1) OR (`status` IN('queued')))
         */
        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
                . "FROM `.*klevu_sync_order` AS `main_table` "
                . "WHERE \(\(`main_table`\.`entity_id` = 1\) OR \(`main_table`\.`status` IN\('queued', 'synced'\)\)\)$/", // phpcs:ignore Generic.Files.LineLength.TooLong
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
                    'status',
                ],
            ],
            condition: [
                [
                    ['eq' => 1],
                    ['in' => ['queued', 'synced']],
                ],
            ],
        );

        /*
         * SELECT `main_table`.*
         * FROM `klevu_sync_order` AS `main_table`
         * WHERE (((`entity_id` = 1) AND (`status` IN('queued', 'synced'))))
         */
        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
                . "FROM `.*klevu_sync_order` AS `main_table` "
                . "WHERE \(\(\(`main_table`\.`entity_id` = 1\) AND \(`main_table`\.`status` IN\('queued', 'synced'\)\)\)\)$/", // phpcs:ignore Generic.Files.LineLength.TooLong
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
                'order_id',
                'attempts',
                [
                    'store_id',
                    'status',
                ],
            ],
            condition: [
                ['in' => [1, 2, 3]],
                ['lteq' => 5],
                [
                    1,
                    ['in' => ['queued', 'synced']],
                ],
            ],
        );

        /*
            SELECT `main_table`.*
            FROM `klevu_sync_order` AS `main_table`
            WHERE (`main_table`.`entity_id` = '1')
                AND (
                    `main_table`.`order_id` IN (1, 2, 3)
                    OR `main_table`.`attempts` <= 5
                    OR (
                        `main_table`.`store_id` = '1'
                        AND `main_table`.`status` IN ('queued', 'synced')
                    )
                )
         */
        // Quoted integers are expected when condition is not specified as ['eq' => (int)]
        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\* "
            . "FROM `.*klevu_sync_order` AS `main_table` "
            . "WHERE \(`main_table`\.`entity_id` = '1'\) "
                . "AND \("
                    . "\(`main_table`\.`order_id` IN\(1, 2, 3\)\) "
                    . "OR \(`main_table`\.`attempts` <= 5\) "
                    . "OR \("
                        . "\(`main_table`\.`store_id` = '1'\) "
                        . "AND \(`main_table`\.`status` IN\('queued', 'synced'\)\)"
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
            field: 'order_status',
            condition: ['in' => ['processing', 'pending']],
        );
        $collection->addFieldToFilter(
            field: 'order_status',
            condition: ['nin' => ['fraud']],
        );
        $collection->addFieldToFilter(
            field: 'last_history_timestamp',
            condition: ['lt' => '2000-01-01 00:00:00'],
        );

        /*
        SELECT `main_table`.*
        FROM `klevu_sync_order` AS `main_table`\n
        INNER JOIN `sales_order`
        ON sales_order.entity_id = main_table.order_id
        WHERE (`sales_order`.`status` IN ('processing', 'pending'))
          AND (`sales_order`.`status` NOT IN ('fraud'))
          AND (
            (
                SELECT MAX(timestamp)
                FROM klevu_sync_order_history
                WHERE klevu_sync_order_history.sync_order_id = main_table.entity_id
            ) < '2000-01-01 00:00:00'
        )
         */
        $this->assertMatchesRegularExpression(
            "/^SELECT `main_table`\.\*\s+"
            . "FROM `.*klevu_sync_order` AS `main_table`\s+"
            . "INNER JOIN `.*sales_order` ON sales_order\.entity_id = main_table\.order_id\s+"
            . "WHERE \(`sales_order`\.`status` IN\('processing', 'pending'\)\) "
                . "AND \(`sales_order`\.`status` NOT IN\('fraud'\)\) "
                . "AND \(\(SELECT MAX\(timestamp\) FROM .*klevu_sync_order_history WHERE .*klevu_sync_order_history\.sync_order_id = main_table\.entity_id\) < '2000-01-01 00:00:00'\)" // phpcs:ignore Generic.Files.LineLength.TooLong
            . "$/",
            (string)$collection->getSelect(),
        );
    }
}

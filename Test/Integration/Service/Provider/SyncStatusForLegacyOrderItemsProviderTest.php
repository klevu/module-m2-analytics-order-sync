<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Service\Provider\SyncStatusForLegacyOrderItemsProvider;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncStatusForLegacyOrderItemsProviderInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * @method SyncStatusForLegacyOrderItemsProvider instantiateTestObject(?array $arguments = null)
 * @method SyncStatusForLegacyOrderItemsProvider instantiateTestObjectFromInterface(?array $arguments = null)
 */
class SyncStatusForLegacyOrderItemsProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

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

        $this->implementationFqcn = SyncStatusForLegacyOrderItemsProvider::class;
        $this->interfaceFqcn = SyncStatusForLegacyOrderItemsProviderInterface::class;
    }

    public function testGet_WithEmptyArray(): void
    {
        $provider = $this->instantiateTestObject();

        $result = $provider->get([]);

        $this->assertSame(
            expected: Statuses::QUEUED->value,
            actual: $result->value,
        );
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testGet_WithInvalidItems(): array
    {
        return [
            'Single item with missing send value' => [
                'orderItems' => [
                    ['foo' => 'bar'],
                ],
            ],
            'Single item with invalid send value' => [
                'orderItems' => [
                    ['send' => 'invalid'],
                ],
            ],
            'Array of strings' => [
                'orderItems' => [
                    'foo',
                    'bar',
                ],
            ],
            'Array of ints' => [
                'orderItems' => [
                    0,
                    -9999999,
                    42,
                ],
            ],
            'Array of floats' => [
                'orderItems' => [
                    0.0,
                    -9999999.0,
                    3.14,
                ],
            ],
            'Array of bool' => [
                'orderItems' => [
                    true,
                    false,
                ],
            ],
            'Array of arrays' => [
                'orderItems' => [
                    ['foo' => 'bar'],
                    ['baz' => 'qux'],
                ],
            ],
            'Array of objects' => [
                'orderItems' => [
                    (object) ['foo' => 'bar'],
                    (object) ['baz' => 'qux'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testGet_WithInvalidItems
     *
     * @param int[]|string[] $orderItems
     *
     * @return void
     */
    public function testGet_WithInvalidItems(
        array $orderItems,
    ): void {
        $provider = $this->instantiateTestObject();

        $this->expectException(\InvalidArgumentException::class);
        $provider->get($orderItems);
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testGet_WithSingleOrderItemStatus(): array
    {
        return [
            '0 (int)' => [
                'orderItems' => [
                    ['send' => 0],
                ],
                'expectedResult' => Statuses::QUEUED,
            ],
            '0 (string)' => [
                'orderItems' => [
                    ['send' => '0'],
                ],
                'expectedResult' => Statuses::QUEUED,
            ],
            '0 (ints)' => [
                'orderItems' => [
                    ['send' => 0],
                    ['send' => 0],
                ],
                'expectedResult' => Statuses::QUEUED,
            ],
            '0 (strings)' => [
                'orderItems' => [
                    ['send' => '0'],
                    ['send' => '0'],
                ],
                'expectedResult' => Statuses::QUEUED,
            ],
            '0 (mixed)' => [
                'orderItems' => [
                    ['send' => 0],
                    ['send' => '0'],
                ],
                'expectedResult' => Statuses::QUEUED,
            ],
            '1 (int)' => [
                'orderItems' => [
                    ['send' => 1],
                ],
                'expectedResult' => Statuses::SYNCED,
            ],
            '1 (string)' => [
                'orderItems' => [
                    ['send' => '1'],
                ],
                'expectedResult' => Statuses::SYNCED,
            ],
            '1 (ints)' => [
                'orderItems' => [
                    ['send' => 1],
                    ['send' => 1],
                ],
                'expectedResult' => Statuses::SYNCED,
            ],
            '1 (strings)' => [
                'orderItems' => [
                    ['send' => '1'],
                    ['send' => '1'],
                ],
                'expectedResult' => Statuses::SYNCED,
            ],
            '1 (mixed)' => [
                'orderItems' => [
                    ['send' => 1],
                    ['send' => '1'],
                ],
                'expectedResult' => Statuses::SYNCED,
            ],
            '2 (int)' => [
                'orderItems' => [
                    ['send' => 2],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '2 (string)' => [
                'orderItems' => [
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '2 (ints)' => [
                'orderItems' => [
                    ['send' => 2],
                    ['send' => 2],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '2 (strings)' => [
                'orderItems' => [
                    ['send' => '2'],
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '2 (mixed)' => [
                'orderItems' => [
                    ['send' => 2],
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testGet_WithSingleOrderItemStatus
     *
     * @param int[]|string[] $orderItems
     * @param Statuses $expectedResult
     *
     * @return void
     */
    public function testGet_WithSingleOrderItemStatus(
        array $orderItems,
        Statuses $expectedResult,
    ): void {

        $provider = $this->instantiateTestObject();

        $result = $provider->get($orderItems);

        $this->assertSame(
            expected: $expectedResult->value,
            actual: $result->value,
        );
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testGet_WithMixedOrderItemStatuses(): array
    {
        return [
            '0,1 (int)' => [
                'orderItems' => [
                    ['send' => 0],
                    ['send' => 1],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
            '0,1 (string)' => [
                'orderItems' => [
                    ['send' => '0'],
                    ['send' => '1'],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
            '0,2 (int)' => [
                'orderItems' => [
                    ['send' => 0],
                    ['send' => 2],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
            '0,2 (string)' => [
                'orderItems' => [
                    ['send' => '0'],
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
            '1,2 (int)' => [
                'orderItems' => [
                    ['send' => 1],
                    ['send' => 2],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '1,2 (string)' => [
                'orderItems' => [
                    ['send' => '1'],
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::ERROR,
            ],
            '0,1,2 (int)' => [
                'orderItems' => [
                    ['send' => 0],
                    ['send' => 1],
                    ['send' => 2],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
            '0,1,2 (string)' => [
                'orderItems' => [
                    ['send' => '0'],
                    ['send' => '1'],
                    ['send' => '2'],
                ],
                'expectedResult' => Statuses::PARTIAL,
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testGet_WithMixedOrderItemStatuses
     *
     * @param int[]|string[] $orderItems
     * @param Statuses $expectedResult
     *
     * @return void
     */
    public function testGet_WithMixedOrderItemStatuses(
        array $orderItems,
        Statuses $expectedResult,
    ): void {

        $provider = $this->instantiateTestObject();

        $result = $provider->get($orderItems);

        $this->assertSame(
            expected: $expectedResult->value,
            actual: $result->value,
        );
    }
}

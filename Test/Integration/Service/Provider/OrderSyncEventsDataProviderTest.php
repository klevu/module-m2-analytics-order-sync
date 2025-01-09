<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration\Service\Provider;

use Klevu\AnalyticsApi\Api\EventsDataProviderInterface;
use Klevu\AnalyticsOrderSync\Service\Action\MarkOrderAsProcessing;
use Klevu\AnalyticsOrderSync\Service\Provider\OrderSyncEventsDataProvider;
use Klevu\AnalyticsOrderSync\Test\Fixtures\Order\OrderTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Sales\InvoiceBuilder;

/**
 * @method OrderSyncEventsDataProvider instantiateTestObject(?array $arguments = null)
 */
class OrderSyncEventsDataProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use OrderTrait;
    use StoreTrait;
    use WebsiteTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();

        $this->implementationFqcn = OrderSyncEventsDataProvider::class;
        $this->interfaceFqcn = EventsDataProviderInterface::class;

        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);

        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
        $this->orderFixtures = [];
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->rollbackOrderFixtures();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    public function testGet_NoOrders(): void
    {
        $orderSyncEventsDataProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'entity_id',
            value: -1,
        );
        $eventsData = $orderSyncEventsDataProvider->get(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $returnedEntityIds = [];
        /** @var OrderInterface $eventsDataItem */
        foreach ($eventsData as $eventsDataItem) {
            $this->assertInstanceOf(OrderInterface::class, $eventsDataItem);
            $returnedEntityIds[] = $eventsDataItem->getEntityId();
        }

        $this->assertCount(0, $returnedEntityIds);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGet(): void
    {
        /** @var OrderInterface&Order $order1 */
        $order1 = $this->getOrderFixture(true);
        /** @var OrderInterface&Order $order2 */
        $order2 = $this->getOrderFixture(false); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable, Generic.Files.LineLength.TooLong
        /** @var OrderInterface&Order $order3 */
        $order3 = $this->getOrderFixture(true);
        InvoiceBuilder::forOrder($order3)->build();
        $order4 = $this->getOrderFixture(true);

        $orderSyncEventsDataProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_status',
            value: 'pending',
        );
        $eventsData = $orderSyncEventsDataProvider->get(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $returnedEntityIds = [];
        /** @var OrderInterface $eventsDataItem */
        foreach ($eventsData as $eventsDataItem) {
            $this->assertInstanceOf(OrderInterface::class, $eventsDataItem);
            $returnedEntityIds[] = $eventsDataItem->getEntityId();
        }

        $this->assertCount(2, $returnedEntityIds);
        $this->assertContains($order1->getEntityId(), $returnedEntityIds);
        $this->assertContains($order4->getEntityId(), $returnedEntityIds);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGet_OrderDeletedDuringProcess(): void
    {
        /** @var OrderInterface&Order $order1 */
        $order1 = $this->getOrderFixture(true);
        /** @var OrderInterface&Order $order2 */
        $order2 = $this->getOrderFixture(false); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable, Generic.Files.LineLength.TooLong
        /** @var OrderInterface&Order $order3 */
        $order3 = $this->getOrderFixture(true);
        InvoiceBuilder::forOrder($order3)->build();
        $order4 = $this->getOrderFixture(true);

        $orderSyncEventsDataProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'order_status',
            value: 'pending',
        );
        $eventsData = $orderSyncEventsDataProvider->get(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $returnedEntityIds = [];
        /** @var OrderInterface $eventsDataItem */
        foreach ($eventsData as $i => $eventsDataItem) {
            if (!$i) {
                $this->registerSecureArea();
                $this->deleteOrderFixture($order4);
                $this->unregisterSecureArea();
            }

            $this->assertInstanceOf(OrderInterface::class, $eventsDataItem);
            $returnedEntityIds[] = $eventsDataItem->getEntityId();
        }

        $this->assertCount(1, $returnedEntityIds);
        $this->assertContains($order1->getEntityId(), $returnedEntityIds);
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGet_OrderTransitionDuringProcess(): void
    {
        /** @var OrderInterface&Order $order1 */
        $order1 = $this->getOrderFixture(true);
        /** @var OrderInterface&Order $order2 */
        $order2 = $this->getOrderFixture(false); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable, Generic.Files.LineLength.TooLong
        /** @var OrderInterface&Order $order3 */
        $order3 = $this->getOrderFixture(true);
        InvoiceBuilder::forOrder($order3)->build();
        $order4 = $this->getOrderFixture(true);

        $orderSyncEventsDataProvider = $this->instantiateTestObject();

        $this->searchCriteriaBuilder->addFilter(
            field: 'sync_status',
            value: 'queued',
        );
        $eventsData = $orderSyncEventsDataProvider->get(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        /** @var MarkOrderAsProcessing $markOrderAsProcessingAction */
        $markOrderAsProcessingAction = $this->objectManager->get(MarkOrderAsProcessing::class);

        $returnedEntityIds = [];
        /** @var OrderInterface $eventsDataItem */
        foreach ($eventsData as $i => $eventsDataItem) {
            if (!$i) {
                $markOrderAsProcessingAction->execute(
                    (int)$order3->getId(),
                );
            }

            $this->assertInstanceOf(OrderInterface::class, $eventsDataItem);
            $returnedEntityIds[] = $eventsDataItem->getEntityId();
        }

        $this->assertCount(2, $returnedEntityIds);
        $this->assertContains($order1->getEntityId(), $returnedEntityIds);
        $this->assertContains($order4->getEntityId(), $returnedEntityIds);
    }
}

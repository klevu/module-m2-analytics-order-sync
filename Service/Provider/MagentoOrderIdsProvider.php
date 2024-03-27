<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Provider;

use Klevu\Analytics\Traits\FilterCollectionTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\MagentoOrderIdsProviderInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

class MagentoOrderIdsProvider implements MagentoOrderIdsProviderInterface
{
    use FilterCollectionTrait;

    /**
     * @var OrderCollectionFactory
     */
    private readonly OrderCollectionFactory $orderCollectionFactory;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     */
    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return int[]
     */
    public function getByCriteria(SearchCriteriaInterface $searchCriteria): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection = $this->filterCollection(
            collection: $collection,
            searchCriteria: $searchCriteria,
        );

        return array_map(
            'intval',
            $collection->getColumnValues(
                $collection->getIdFieldName(),
            ),
        );
    }
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\Analytics\Traits\FilterCollectionTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder\CollectionFactory as SyncOrderCollectionFactory;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsFactory;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

class SyncOrderRepository implements SyncOrderRepositoryInterface
{
    use FilterCollectionTrait;

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SyncOrderInterfaceFactory
     */
    private readonly SyncOrderInterfaceFactory $syncOrderModelFactory;
    /**
     * @var SyncOrderResource
     */
    private readonly SyncOrderResource $syncOrderResource;
    /**
     * @var SyncOrderCollectionFactory
     */
    private readonly SyncOrderCollectionFactory $syncOrderCollectionFactory;
    /**
     * @var SearchResultsFactory
     */
    private readonly SearchResultsFactory $searchResultsFactory;
    /**
     * @var SyncOrderInterface[][]
     */
    private array $instances = [
        'id' => [],
        'order_id' => [],
    ];

    /**
     * @param LoggerInterface $logger
     * @param SyncOrderInterfaceFactory $syncOrderModelFactory
     * @param SyncOrderResource $syncOrderResource
     * @param SyncOrderCollectionFactory $syncOrderCollectionFactory
     * @param SearchResultsFactory $searchResultsFactory
     */
    public function __construct(
        LoggerInterface $logger,
        SyncOrderInterfaceFactory $syncOrderModelFactory,
        SyncOrderResource $syncOrderResource,
        SyncOrderCollectionFactory $syncOrderCollectionFactory,
        SearchResultsFactory $searchResultsFactory,
    ) {
        $this->logger = $logger;
        $this->syncOrderModelFactory = $syncOrderModelFactory;
        $this->syncOrderResource = $syncOrderResource;
        $this->syncOrderCollectionFactory = $syncOrderCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
    }

    /**
     * @param int $syncOrderId
     * @return SyncOrderInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $syncOrderId): SyncOrderInterface
    {
        if (!isset($this->instances['id'][$syncOrderId])) {
            $syncOrderModel = $this->syncOrderModelFactory->create();
            if (!($syncOrderModel instanceof AbstractModel)) {
                throw new \LogicException(sprintf(
                    'Sync Order model must be instance of %s; received %s from %s',
                    AbstractModel::class,
                    get_debug_type($syncOrderModel),
                    $this->syncOrderModelFactory::class,
                ));
            }

            $this->syncOrderResource->load(
                object: $syncOrderModel,
                value: $syncOrderId,
                field: SyncOrderModel::FIELD_ENTITY_ID,
            );
            if (!$syncOrderModel->getEntityId()) {
                throw NoSuchEntityException::singleField(
                    fieldName: SyncOrderModel::FIELD_ENTITY_ID,
                    fieldValue: $syncOrderId,
                );
            }

            $this->instances['id'][$syncOrderId] = $syncOrderModel;
        }

        return $this->instances['id'][$syncOrderId];
    }

    /**
     * @param int $orderId
     * @return SyncOrderInterface
     * @throws NoSuchEntityException
     */
    public function getByOrderId(int $orderId): SyncOrderInterface
    {
        if (!isset($this->instances['order_id'][$orderId])) {
            $syncOrderModel = $this->syncOrderModelFactory->create();
            if (!($syncOrderModel instanceof AbstractModel)) {
                throw new \LogicException(sprintf(
                    'Sync Order model must be instance of %s; received %s from %s',
                    AbstractModel::class,
                    get_debug_type($syncOrderModel),
                    $this->syncOrderModelFactory::class,
                ));
            }

            $this->syncOrderResource->load(
                object: $syncOrderModel,
                value: $orderId,
                field: SyncOrderModel::FIELD_ORDER_ID,
            );
            if (!$syncOrderModel->getEntityId()) {
                throw NoSuchEntityException::singleField(
                    fieldName: SyncOrderModel::FIELD_ORDER_ID,
                    fieldValue: $orderId,
                );
            }

            $this->instances['order_id'][$orderId] = $syncOrderModel;
        }

        return $this->instances['order_id'][$orderId];
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $syncOrderCollection = $this->filterCollection(
            collection: $this->syncOrderCollectionFactory->create(),
            searchCriteria: $searchCriteria,
        );

        [$syncOrderItems, $totalCount] = $this->getSearchResultData(
            collection: $syncOrderCollection,
            searchCriteria: $searchCriteria,
        );

        $searchResult = $this->searchResultsFactory->create();
        $searchResult->setSearchCriteria($searchCriteria);
        $searchResult->setItems($syncOrderItems);
        $searchResult->setTotalCount($totalCount);

        return $searchResult;
    }

    /**
     * @param SyncOrderInterface $syncOrder
     * @return SyncOrderInterface
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     */
    public function save(SyncOrderInterface $syncOrder): SyncOrderInterface
    {
        if (!($syncOrder instanceof AbstractModel)) {
            throw new \InvalidArgumentException(sprintf(
                'Sync Order model must be instance of %s; received %s in %s',
                AbstractModel::class,
                get_debug_type($syncOrder),
                __METHOD__,
            ));
        }

        try {
            $this->syncOrderResource->save($syncOrder);
        } catch (LocalizedException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $message = __('Could not save sync order record: %1', $exception->getMessage());
            $this->logger->error(
                message: (string)$message,
                context: [
                    'exception' => $exception::class,
                    'method' => __METHOD__,
                    'order' => [
                        'entityId' => $syncOrder->getEntityId(),
                        'orderId' => $syncOrder->getOrderId(),
                        'status' => $syncOrder->getStatus(),
                    ],
                ],
            );
            throw new CouldNotSaveException(
                phrase: $message,
                cause: $exception,
                code: $exception->getCode(),
            );
        }

        unset(
            $this->instances['id'][$syncOrder->getEntityId()],
            $this->instances['order_id'][$syncOrder->getOrderId()],
        );

        return $this->getById(
            (int)$syncOrder->getEntityId(),
        );
    }

    /**
     * @param SyncOrderInterface $syncOrder
     * @return void
     * @throws CouldNotDeleteException
     * @throws LocalizedException
     */
    public function delete(SyncOrderInterface $syncOrder): void
    {
        if (!($syncOrder instanceof AbstractModel)) {
            throw new \InvalidArgumentException(sprintf(
                'Sync Order model must be instance of %s; received %s in %s',
                AbstractModel::class,
                get_debug_type($syncOrder),
                __METHOD__,
            ));
        }

        try {
            $this->syncOrderResource->delete($syncOrder);
            unset(
                $this->instances['id'][$syncOrder->getEntityId()],
                $this->instances['order_id'][$syncOrder->getOrderId()],
            );
        } catch (LocalizedException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $message = __('Could not delete sync order record: %1', $exception->getMessage());
            $this->logger->error(
                message: (string)$message,
                context: [
                    'exception' => $exception::class,
                    'method' => __METHOD__,
                    'order' => [
                        'entityId' => $syncOrder->getEntityId(),
                        'orderId' => $syncOrder->getOrderId(),
                        'status' => $syncOrder->getStatus(),
                    ],
                ],
            );
            throw new CouldNotDeleteException(
                phrase: $message,
                cause: $exception,
                code: $exception->getCode(),
            );
        }
    }

    /**
     * @param int $syncOrderId
     * @return void
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function deleteById(int $syncOrderId): void
    {
        $this->delete(
            $this->getById($syncOrderId),
        );
    }

    /**
     * @return void
     */
    public function clearCache(): void
    {
        // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedPassingByReference
        array_walk($this->instances, static function (array &$instances): void {
            $instances = [];
        });
    }
}

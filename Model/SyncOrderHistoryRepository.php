<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\Analytics\Traits\FilterCollectionTrait;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory as SyncOrderHistoryResource;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrderHistory\CollectionFactory as SyncOrderHistoryCollectionFactory; // phpcs:ignore Generic.Files.LineLength.TooLong
use Klevu\AnalyticsOrderSync\Model\SyncOrderHistory as SyncOrderHistoryModel;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
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

class SyncOrderHistoryRepository implements SyncOrderHistoryRepositoryInterface
{
    use FilterCollectionTrait;

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var SyncOrderHistoryInterfaceFactory
     */
    private readonly SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory;
    /**
     * @var SyncOrderHistoryResource
     */
    private readonly SyncOrderHistoryResource $syncOrderHistoryResource;
    /**
     * @var SyncOrderHistoryCollectionFactory
     */
    private readonly SyncOrderHistoryCollectionFactory $syncOrderHistoryCollectionFactory;
    /**
     * @var SearchResultsFactory
     */
    private readonly SearchResultsFactory $searchResultsFactory;
    /**
     * @var SyncOrderHistoryInterface[][]
     */
    private array $instances = [
        'id' => [],
    ];

    /**
     * @param LoggerInterface $logger
     * @param SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory
     * @param SyncOrderHistoryResource $syncOrderHistoryResource
     * @param SyncOrderHistoryCollectionFactory $syncOrderHistoryCollectionFactory
     * @param SearchResultsFactory $searchResultsFactory
     */
    public function __construct(
        LoggerInterface $logger,
        SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory,
        SyncOrderHistoryResource $syncOrderHistoryResource,
        SyncOrderHistoryCollectionFactory $syncOrderHistoryCollectionFactory,
        SearchResultsFactory $searchResultsFactory,
    ) {
        $this->logger = $logger;
        $this->syncOrderHistoryModelFactory = $syncOrderHistoryModelFactory;
        $this->syncOrderHistoryResource = $syncOrderHistoryResource;
        $this->syncOrderHistoryCollectionFactory = $syncOrderHistoryCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
    }

    /**
     * @param int $syncOrderHistoryId
     * @return SyncOrderHistoryInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $syncOrderHistoryId): SyncOrderHistoryInterface
    {
        if (!isset($this->instances['id'][$syncOrderHistoryId])) {
            $syncOrderHistoryModel = $this->syncOrderHistoryModelFactory->create();
            if (!($syncOrderHistoryModel instanceof AbstractModel)) {
                throw new \LogicException(sprintf(
                    'Sync Order History model must be instance of %s; received %s from %s',
                    AbstractModel::class,
                    get_debug_type($syncOrderHistoryModel),
                    $this->syncOrderHistoryModelFactory::class,
                ));
            }

            $this->syncOrderHistoryResource->load(
                object: $syncOrderHistoryModel,
                value: $syncOrderHistoryId,
                field: SyncOrderHistoryModel::FIELD_ENTITY_ID,
            );
            if (!$syncOrderHistoryModel->getEntityId()) {
                throw NoSuchEntityException::singleField(
                    fieldName: SyncOrderHistoryModel::FIELD_ENTITY_ID,
                    fieldValue: $syncOrderHistoryId,
                );
            }

            $this->instances['id'][$syncOrderHistoryId] = $syncOrderHistoryModel;
        }

        return $this->instances['id'][$syncOrderHistoryId];
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $syncOrderHistoryCollection = $this->syncOrderHistoryCollectionFactory->create();
        $syncOrderHistoryCollection = $this->filterCollection(
            collection: $syncOrderHistoryCollection,
            searchCriteria: $searchCriteria,
        );

        [$syncOrderHistoryItems, $totalCount] = $this->getSearchResultData(
            collection: $syncOrderHistoryCollection,
            searchCriteria: $searchCriteria,
        );

        $searchResult = $this->searchResultsFactory->create();
        $searchResult->setSearchCriteria($searchCriteria);
        $searchResult->setItems($syncOrderHistoryItems);
        $searchResult->setTotalCount($totalCount);

        return $searchResult;
    }

    /**
     * @param SyncOrderHistoryInterface $syncOrderHistory
     * @return SyncOrderHistoryInterface
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     */
    public function save(SyncOrderHistoryInterface $syncOrderHistory): SyncOrderHistoryInterface
    {
        if (!($syncOrderHistory instanceof AbstractModel)) {
            throw new \InvalidArgumentException(sprintf(
                'Sync Order History model must be instance of %s; received %s in %s',
                AbstractModel::class,
                get_debug_type($syncOrderHistory),
                __METHOD__,
            ));
        }

        try {
            $this->syncOrderHistoryResource->save($syncOrderHistory);
        } catch (LocalizedException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $this->syncOrderHistoryResource->unserializeFields($syncOrderHistory);

            $message = __('Could not save sync order history record: %1', $exception->getMessage());
            $this->logger->error(
                message: (string)$message,
                context: [
                    'exception' => $exception::class,
                    'method' => __METHOD__,
                    'orderHistory' => [
                        'entityId' => $syncOrderHistory->getEntityId(),
                        'syncOrderId' => $syncOrderHistory->getSyncOrderId(),
                        'timestamp' => $syncOrderHistory->getTimestamp(),
                        'action' => $syncOrderHistory->getAction(),
                        'result' => $syncOrderHistory->getResult(),
                        'additionalInfo' => $syncOrderHistory->getAdditionalInformation(),
                    ],
                ],
            );
            throw new CouldNotSaveException(
                phrase: $message,
                cause: $exception,
                code: $exception->getCode(),
            );
        }

        unset($this->instances['id'][$syncOrderHistory->getEntityId()]);

        return $this->getById(
            $syncOrderHistory->getEntityId(),
        );
    }

    /**
     * @param SyncOrderHistoryInterface $syncOrderHistory
     * @return void
     * @throws CouldNotDeleteException
     * @throws LocalizedException
     */
    public function delete(SyncOrderHistoryInterface $syncOrderHistory): void
    {
        if (!($syncOrderHistory instanceof AbstractModel)) {
            throw new \InvalidArgumentException(sprintf(
                'Sync Order History model must be instance of %s; received %s in %s',
                AbstractModel::class,
                get_debug_type($syncOrderHistory),
                __METHOD__,
            ));
        }

        try {
            $this->syncOrderHistoryResource->delete($syncOrderHistory);
            unset($this->instances['id'][$syncOrderHistory->getEntityId()]);
        } catch (LocalizedException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $message = __('Could not delete sync order history record: %1', $exception->getMessage());
            $this->logger->error(
                message: (string)$message,
                context: [
                    'exception' => $exception::class,
                    'method' => __METHOD__,
                    'orderHistory' => [
                        'entityId' => $syncOrderHistory->getEntityId(),
                        'syncOrderId' => $syncOrderHistory->getSyncOrderId(),
                        'timestamp' => $syncOrderHistory->getTimestamp(),
                        'action' => $syncOrderHistory->getAction(),
                        'result' => $syncOrderHistory->getResult(),
                        'additionalInfo' => $syncOrderHistory->getAdditionalInformation(),
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
     * @param int $syncOrderHistoryId
     * @return void
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function deleteById(int $syncOrderHistoryId): void
    {
        $this->delete(
            $this->getById($syncOrderHistoryId),
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

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Console\Command;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsApi\Api\Data\ProcessEventsResultInterface;
use Klevu\AnalyticsApi\Api\ProcessEventsServiceInterface;
use Klevu\AnalyticsApi\Model\Source\ProcessEventsResultStatuses;
use Klevu\AnalyticsOrderSync\Console\FilterSearchCriteriaOptionsTrait;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\OrderSyncSearchCriteriaProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Klevu\Pipelines\Exception\ExtractionExceptionInterface;
use Klevu\Pipelines\Exception\TransformationExceptionInterface;
use Klevu\Pipelines\Exception\ValidationExceptionInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncOrdersCommand extends Command
{
    use FilterSearchCriteriaOptionsTrait;
    use OptionSourceToHashTrait;

    public const SUCCESS = 0; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const FAILURE = 1; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const INVALID = 2; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const COMMAND_NAME = 'klevu:analytics:sync-orders';
    public const OPTION_ORDER_ID = 'order-id';
    public const OPTION_STORE_ID = 'store-id';
    public const OPTION_IGNORE_SYNC_ENABLED_FLAG = 'ignore-sync-enabled-flag';

    /**
     * @var SerializerInterface
     */
    private readonly SerializerInterface $serializer;
    /**
     * @var OrderSyncSearchCriteriaProviderInterface
     */
    private readonly OrderSyncSearchCriteriaProviderInterface $syncOrderIdsForConsoleSearchCriteriaProvider;
    /**
     * @var ProcessEventsServiceInterface
     */
    private readonly ProcessEventsServiceInterface $syncOrdersServiceForRetry;
    /**
     * @var ProcessEventsServiceInterface
     */
    private readonly ProcessEventsServiceInterface $syncOrdersServiceForQueued;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $processEventsResultStatusOptionSource;
    /**
     * @var int
     */
    private readonly int $batchSize;

    /**
     * @param SerializerInterface $serializer
     * @param OrderSyncSearchCriteriaProviderInterface $syncOrderIdsForConsoleSearchCriteriaProvider
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param ProcessEventsServiceInterface $syncOrdersServiceForRetry
     * @param ProcessEventsServiceInterface $syncOrdersServiceForQueued
     * @param OptionSourceInterface $processEventsResultStatusOptionSource
     * @param string|null $name
     * @param int|null $batchSize
     */
    public function __construct(
        SerializerInterface $serializer,
        OrderSyncSearchCriteriaProviderInterface $syncOrderIdsForConsoleSearchCriteriaProvider,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        ProcessEventsServiceInterface $syncOrdersServiceForRetry,
        ProcessEventsServiceInterface $syncOrdersServiceForQueued,
        OptionSourceInterface $processEventsResultStatusOptionSource,
        ?string $name = null,
        ?int $batchSize = 250,
    ) {
        $this->serializer = $serializer;
        $this->syncOrderIdsForConsoleSearchCriteriaProvider = $syncOrderIdsForConsoleSearchCriteriaProvider;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->syncOrdersServiceForRetry = $syncOrdersServiceForRetry;
        $this->syncOrdersServiceForQueued = $syncOrdersServiceForQueued;
        $this->processEventsResultStatusOptionSource = $processEventsResultStatusOptionSource;
        $this->batchSize = $batchSize;

        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setName(static::COMMAND_NAME);
        $this->setDescription(
            (string)__('Sends queued orders to Klevu analytics'),
        );

        $this->addOption(
            name: static::OPTION_ORDER_ID,
            mode: InputOption::VALUE_IS_ARRAY + InputOption::VALUE_OPTIONAL,
            description: (string)__('Order ids to be processed'),
        );
        $this->addOption(
            name: static::OPTION_STORE_ID,
            mode: InputOption::VALUE_OPTIONAL + InputOption::VALUE_IS_ARRAY,
            description: (string)__('Store(s) to sync orders for'),
        );
        $this->addOption(
            name: static::OPTION_IGNORE_SYNC_ENABLED_FLAG,
            mode: InputOption::VALUE_NONE,
            description: (string)__('Forces processing of orders in stores which have sync disabled'),
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws ExtractionExceptionInterface
     * @throws TransformationExceptionInterface
     * @throws ValidationExceptionInterface
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $return = static::SUCCESS;
        $filterStoreIds = $this->getStoreIdsToFilter(
            storeIds: $input->getOption(static::OPTION_STORE_ID),
            ignoreSyncEnabledFlag: $input->getOption(static::OPTION_IGNORE_SYNC_ENABLED_FLAG),
        );
        if ([] === $filterStoreIds) {
            $output->writeln(
                sprintf('<error>%s</error>', __('No stores enabled for sync')),
            );
            $output->writeln(
                sprintf('<comment>%s</comment>', __(
                    'Enable sync for selected stores or run with the --%1 option',
                    static::OPTION_IGNORE_SYNC_ENABLED_FLAG,
                )),
            );

            return static::INVALID;
        }

        $filterOrderIds = $this->getOrderIdsToFilter(
            orderIds: $input->getOption(static::OPTION_ORDER_ID),
        );
        if ([] === $filterOrderIds) {
            $output->writeln(
                sprintf('<error>%s</error>', __('No valid order ids provided for sync')),
            );

            return self::INVALID;
        }

        $output->writeln(
            sprintf('<info>%s</info>', __('Processing RETRY orders')),
        );
        $retryResults = $this->executeSync(
            syncOrdersService: $this->syncOrdersServiceForRetry,
            filterOrderIds: $filterOrderIds,
            filterSyncStatus: Statuses::RETRY,
            filterStoreIds: $filterStoreIds,
        );
        $this->writePipelineResultToConsole(
            output: $output,
            results: $retryResults,
        );

        $output->writeln(
            sprintf('<info>%s</info>', __('Processing QUEUED orders')),
        );
        $queuedResults = $this->executeSync(
            syncOrdersService: $this->syncOrdersServiceForQueued,
            filterOrderIds: $filterOrderIds,
            filterSyncStatus: Statuses::QUEUED,
            filterStoreIds: $filterStoreIds,
        );
        $this->writePipelineResultToConsole(
            output: $output,
            results: $queuedResults,
        );

        return $return;
    }

    /**
     * @param ProcessEventsServiceInterface $syncOrdersService
     * @param int[]|null $filterOrderIds
     * @param Statuses $filterSyncStatus
     * @param int[]|null $filterStoreIds
     *
     * @return ProcessEventsResultInterface[]
     * @throws ExtractionExceptionInterface
     * @throws TransformationExceptionInterface
     * @throws ValidationExceptionInterface
     */
    private function executeSync(
        ProcessEventsServiceInterface $syncOrdersService,
        ?array $filterOrderIds,
        Statuses $filterSyncStatus,
        ?array $filterStoreIds,
    ): array {
        $results = [];
        if (!$filterSyncStatus->canInitiateSync()) {
            return $results;
        }

        // Note on currentPage / allowed filterSyncStatus: (ref: KS-23736)
        // We should never end with the same status after an iteration, as we pass in a syncable status
        //  to this private method and the service should always end with a non-syncable status
        // The exception would be where an exception prevents the sync order updating but does not kill
        //  this loop. This scenario is exceptional and those orders will be picked up in the next execution
        // Therefore, we do not pass the page to generate a searchCriteria, but do use it to record results

        $currentPage = 0;
        $lastProcessedOrderIds = []; // To prevent infinite loop should the same batch be attempted multiple times
        do {
            $currentPage++;

            $searchCriteria = $this->syncOrderIdsForConsoleSearchCriteriaProvider->getSearchCriteria(
                orderIds: $filterOrderIds,
                syncStatuses: [
                    $filterSyncStatus->value,
                ],
                storeIds: $filterStoreIds,
                currentPage: 1,
                pageSize: $this->batchSize,
            );

            $result = $syncOrdersService->execute(
                searchCriteria: $searchCriteria,
                via: (string)__('CLI: %1', static::COMMAND_NAME),
            );

            $results[$currentPage] = $result;

            $processedOrderIds = array_column(
                array: $result->getPipelineResult(),
                column_key: 'orderId',
            );
            if (!array_diff($processedOrderIds, $lastProcessedOrderIds)) {
                break;
            }

            $lastProcessedOrderIds = $processedOrderIds;
        } while (ProcessEventsResultStatuses::NOOP !== $result->getStatus());

        return $results;
    }

    /**
     * @param OutputInterface $output
     * @param ProcessEventsResultInterface[] $results
     * @return void
     */
    private function writePipelineResultToConsole(
        OutputInterface $output,
        array $results,
    ): void {
        $totalBatches = count($results);
        $resultStatuses = $this->getHashForOptionSource(
            optionSource: $this->processEventsResultStatusOptionSource,
        );

        foreach ($results as $batchNumber => $result) {
            $resultStatus = $result->getStatus();

            $statusString = $resultStatuses[$resultStatus->value] ?? $resultStatus->value;
            if (ProcessEventsResultStatuses::ERROR === $resultStatus) {
                $statusString = sprintf('<error>%s</error>', $statusString);
            }

            $output->writeln(
                sprintf(
                    '  <comment>%s</comment> : %s',
                    __('Batch %1 / %2', $batchNumber, $totalBatches),
                    $statusString,
                ),
            );

            if (OutputInterface::VERBOSITY_VERBOSE < $output->getVerbosity()) {
                foreach ($result->getMessages() as $message) {
                    $output->writeln('  * ' . $message);
                }
            }

            if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $output->getVerbosity()) {
                $output->writeln(
                    sprintf(
                        'Result: %s',
                        $this->serializer->serialize($result->getPipelineResult()),
                    ),
                );
            }
        }
        $output->writeln('');
    }
}

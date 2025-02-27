<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Console\Command;

use Klevu\AnalyticsOrderSync\Console\FilterSearchCriteriaOptionsTrait;
use Klevu\AnalyticsOrderSyncApi\Api\QueueOrderForSyncActionInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\MagentoOrderIdsProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\OrderSyncSearchCriteriaProviderInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueOrdersForSyncCommand extends Command
{
    use FilterSearchCriteriaOptionsTrait;

    public const SUCCESS = 0; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const FAILURE = 1; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const COMMAND_NAME = 'klevu:analytics:queue-orders-for-sync';
    public const OPTION_ORDER_ID = 'order-id';
    public const OPTION_SYNC_STATUS = 'sync-status';
    public const OPTION_STORE_ID = 'store-id';
    public const OPTION_IGNORE_SYNC_ENABLED_FLAG = 'ignore-sync-enabled-flag';

    /**
     * @var OrderSyncSearchCriteriaProviderInterface
     */
    private readonly OrderSyncSearchCriteriaProviderInterface $orderIdsForConsoleSearchCriteriaProvider;
    /**
     * @var MagentoOrderIdsProviderInterface
     */
    private readonly MagentoOrderIdsProviderInterface $magentoOrderIdsProvider;
    /**
     * @var QueueOrderForSyncActionInterface
     */
    private readonly QueueOrderForSyncActionInterface $queueOrderForSyncAction;

    /**
     * @param OrderSyncSearchCriteriaProviderInterface $orderIdsForConsoleSearchCriteriaProvider
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param QueueOrderForSyncActionInterface $queueOrderForSyncAction
     * @param MagentoOrderIdsProviderInterface $magentoOrderIdsProvider
     * @param string|null $name
     */
    public function __construct(
        OrderSyncSearchCriteriaProviderInterface $orderIdsForConsoleSearchCriteriaProvider,
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        QueueOrderForSyncActionInterface $queueOrderForSyncAction,
        MagentoOrderIdsProviderInterface $magentoOrderIdsProvider,
        ?string $name = null,
    ) {
        $this->orderIdsForConsoleSearchCriteriaProvider = $orderIdsForConsoleSearchCriteriaProvider;
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->queueOrderForSyncAction = $queueOrderForSyncAction;
        $this->magentoOrderIdsProvider = $magentoOrderIdsProvider;

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
            (string)__('Adds Magento order record(s) to queue for synchronisation'),
        );
        $this->addOption(
            name: static::OPTION_ORDER_ID,
            mode: InputOption::VALUE_IS_ARRAY + InputOption::VALUE_OPTIONAL,
            description: (string)__('Order ids to be queued for sync'),
        );
        $this->addOption(
            name: static::OPTION_SYNC_STATUS,
            mode: InputOption::VALUE_IS_ARRAY + InputOption::VALUE_OPTIONAL,
            description: (string)__('Sync statuses to (re)queue for sync'),
        );
        $this->addOption(
            name: static::OPTION_STORE_ID,
            mode: InputOption::VALUE_IS_ARRAY + InputOption::VALUE_OPTIONAL,
            description: (string)__('Store IDs to limit queued order options'),
        );
        $this->addOption(
            name: static::OPTION_IGNORE_SYNC_ENABLED_FLAG,
            mode: InputOption::VALUE_NONE,
            description: (string)__('Forces queueing of orders in stores which have sync disabled'),
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $output->writeln(
            sprintf('<info>%s</info>', __('Queueing orders for sync')),
        );

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

            return static::FAILURE;
        }

        $filterOrderIds = $this->getOrderIdsToFilter(
            orderIds: $input->getOption(static::OPTION_ORDER_ID),
        );
        $filterSyncStatuses = $this->getSyncStatusesToFilter(
            syncStatuses: $input->getOption(static::OPTION_SYNC_STATUS),
        );

        $currentPage = 1;
        do {
            $searchCriteria = $this->orderIdsForConsoleSearchCriteriaProvider->getSearchCriteria(
                orderIds: $filterOrderIds,
                syncStatuses: $filterSyncStatuses,
                storeIds: $filterStoreIds,
                currentPage: $currentPage,
            );
            $orderIds = $this->magentoOrderIdsProvider->getByCriteria($searchCriteria);
            if (!$orderIds) {
                break;
            }

            foreach ($orderIds as $orderId) {
                $result = $this->processOrderId(
                    orderId: $orderId,
                    output: $output,
                );
                if (!$result) {
                    $return = static::FAILURE;
                }
            }

            $currentPage++;
        } while ($orderIds);

        if ($currentPage === 1) {
            $output->writeln(
                sprintf('<comment>%s</comment>', __('No matching orders found to queue')),
            );
        }

        return $return;
    }

    /**
     * @param int $orderId
     * @param OutputInterface $output
     * @return bool
     */
    private function processOrderId(
        int $orderId,
        OutputInterface $output,
    ): bool {
        $output->write(
            __('Queueing order id #%1: ', $orderId)->render(),
        );

        try {
            $result = $this->queueOrderForSyncAction->execute(
                orderId: $orderId,
                via: (string)__('CLI: %1', static::COMMAND_NAME),
            );
            $success = $result->isSuccess();
            $messages = $result->getMessages();
        } catch (LocalizedException $exception) {
            $success = false;
            $messages = [$exception->getMessage()];
        }

        if ($success) {
            $output->writeln(
                __('OK')->render(),
            );
        } else {
            $output->writeln(
                sprintf('<error>%s</error>', __('ERROR')),
            );
        }
        foreach ($messages as $message) {
            $output->writeln(
                sprintf('  - %s', $message),
            );
        }

        return $success;
    }
}

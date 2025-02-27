<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Console\Command;

use Klevu\AnalyticsOrderSync\Console\FilterSearchCriteriaOptionsTrait;
use Klevu\AnalyticsOrderSyncApi\Api\MigrateLegacyOrderSyncRecordsInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncEnabledStoresProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateLegacyOrderSyncRecordsCommand extends Command
{
    use FilterSearchCriteriaOptionsTrait;

    public const SUCCESS = 0; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const FAILURE = 1; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const INVALID = 2; // Magento requires symfony/console 4.4, however this const was not added until 5.1
    public const COMMAND_NAME = 'klevu:analytics:migrate-legacy-order-sync-records';
    public const OPTION_STORE_ID = 'store-id';

    /**
     * @var MigrateLegacyOrderSyncRecordsInterface|mixed
     */
    private readonly MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService;

    /**
     * @param SyncEnabledStoresProviderInterface $syncEnabledStoresProvider
     * @param MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService
     * @param string|null $name
     */
    public function __construct(
        SyncEnabledStoresProviderInterface $syncEnabledStoresProvider,
        MigrateLegacyOrderSyncRecordsInterface $migrateLegacyOrderSyncRecordsService,
        ?string $name = null,
    ) {
        $this->syncEnabledStoresProvider = $syncEnabledStoresProvider;
        $this->migrateLegacyOrderSyncRecordsService = $migrateLegacyOrderSyncRecordsService;

        parent::__construct($name);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $return = static::SUCCESS;
        $inputStoreIds = $input->getOption(static::OPTION_STORE_ID);
        $filterStoreIds = $this->getStoreIdsToFilter(
            storeIds: $inputStoreIds,
            ignoreSyncEnabledFlag: false,
        );

        switch (true) {
            case $inputStoreIds && $filterStoreIds:
                foreach ($filterStoreIds as $storeId) {
                    $output->write(
                        messages: sprintf(
                            '<info>%s',
                            __('Migrating legacy order sync records for store ID %1... ', $storeId),
                        ),
                    );

                    $startTime = microtime(true);
                    $this->migrateLegacyOrderSyncRecordsService->executeForStoreId(
                        storeId: (int)$storeId,
                    );
                    $endTime = microtime(true);

                    $output->writeln(
                        messages: sprintf(
                            '%s</info>',
                            __(
                                'Complete in %1 seconds.',
                                number_format($endTime - $startTime, 2),
                            ),
                        ),
                    );
                }
                break;

            case $inputStoreIds:
                $output->writeln(
                    messages: sprintf(
                        '<error>%s</error>',
                        __('All specified store ids are invalid or not enabled for sync'),
                    ),
                );
                $return = static::INVALID;
                break;

            default:
                $output->write(
                    messages: sprintf(
                        '<info>%s',
                        __('Migrating legacy order sync records for all integrated stores... '),
                    ),
                );

                $startTime = microtime(true);
                $this->migrateLegacyOrderSyncRecordsService->executeForAllStores();
                $endTime = microtime(true);

                $output->writeln(
                    messages: sprintf(
                        '%s</info>',
                        __(
                            'Complete in %1 seconds.',
                            number_format($endTime - $startTime, 2),
                        ),
                    ),
                );
        }

        $output->writeln(
            messages: [
                '',
                __('Check analytics logs for further details'),
            ],
        );

        return $return;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setName(
            name: static::COMMAND_NAME,
        );
        $this->setDescription(
            description: __(
                'Imports order items from older versions (pre 4.0.0) '
                . 'of the Klevu extension and queues for sync',
            )->render(),
        );

        $this->addOption(
            name: static::OPTION_STORE_ID,
            mode: InputOption::VALUE_OPTIONAL + InputOption::VALUE_IS_ARRAY,
            description: __('Store(s) to sync orders for')->render(),
        );
    }
}

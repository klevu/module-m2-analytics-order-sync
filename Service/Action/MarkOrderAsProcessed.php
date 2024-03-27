<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Service\Action;

use Klevu\AnalyticsOrderSync\Exception\OrderNotFoundException;
use Klevu\AnalyticsOrderSync\Model\MarkOrderActionResult;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Klevu\AnalyticsOrderSyncApi\Api\Data\MarkOrderActionResultInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\MarkOrderActionResultInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterfaceFactory;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderHistoryRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Provider\SyncOrderForOrderProviderInterface;
use Magento\Framework\Exception\LocalizedException;

class MarkOrderAsProcessed implements MarkOrderAsProcessedActionInterface
{
    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;
    /**
     * @var SyncOrderForOrderProviderInterface
     */
    private readonly SyncOrderForOrderProviderInterface $syncOrderForOrderProvider;
    /**
     * @var SyncOrderHistoryInterfaceFactory
     */
    private readonly SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory;
    /**
     * @var SyncOrderHistoryRepositoryInterface
     */
    private readonly SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository;
    /**
     * @var MarkOrderActionResultInterfaceFactory
     */
    private readonly MarkOrderActionResultInterfaceFactory $markOrderActionResultFactory;

    /**
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     * @param SyncOrderForOrderProviderInterface $syncOrderForOrderProvider
     * @param SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory
     * @param SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository
     * @param MarkOrderActionResultInterfaceFactory $markOrderActionResultFactory
     */
    public function __construct(
        SyncOrderRepositoryInterface $syncOrderRepository,
        SyncOrderForOrderProviderInterface $syncOrderForOrderProvider,
        SyncOrderHistoryInterfaceFactory $syncOrderHistoryModelFactory,
        SyncOrderHistoryRepositoryInterface $syncOrderHistoryRepository,
        MarkOrderActionResultInterfaceFactory $markOrderActionResultFactory,
    ) {
        $this->syncOrderRepository = $syncOrderRepository;
        $this->syncOrderForOrderProvider = $syncOrderForOrderProvider;
        $this->syncOrderHistoryModelFactory = $syncOrderHistoryModelFactory;
        $this->syncOrderHistoryRepository = $syncOrderHistoryRepository;
        $this->markOrderActionResultFactory = $markOrderActionResultFactory;
    }

    /**
     * @param int $orderId
     * @param string $resultStatus
     * @param string $via
     * @param mixed[] $additionalInformation
     * @return MarkOrderActionResultInterface
     * @throws \InvalidArgumentException
     */
    public function execute(
        int $orderId,
        string $resultStatus,
        string $via = '',
        array $additionalInformation = [],
    ): MarkOrderActionResultInterface {
        $success = true;
        $messages = [];

        try {
            if ($orderId <= 0) {
                throw new OrderNotFoundException(
                    phrase: __('Invalid orderId %1', $orderId),
                );
            }

            $syncOrderRecord = $this->syncOrderForOrderProvider->getForOrderId($orderId);
        } catch (OrderNotFoundException $exception) {
            return new MarkOrderActionResult(
                success: false,
                syncOrderRecord: null,
                syncOrderHistoryRecord: null,
                messages: [
                    $exception->getMessage(),
                ],
            );
        }

        $newStatus = $this->getResultStatusFromArgument($resultStatus);

        $additionalInformation['original_status'] = $syncOrderRecord->getStatus();

        $currentStatus = Statuses::tryFrom($syncOrderRecord->getStatus());
        if ($newStatus === $currentStatus) {
            $success = false;
            $syncOrderHistoryResult = Results::NOOP;
            $messages[] = sprintf('Order is already marked as %s', $newStatus->value);
        } else {
            $syncOrderRecord->setStatus($newStatus->value);
            if (0 === $syncOrderRecord->getAttempts()) {
                $syncOrderRecord->setAttempts(1);
            }
            try {
                $this->syncOrderRepository->save($syncOrderRecord);
                $syncOrderHistoryResult = Results::SUCCESS;
            } catch (LocalizedException $exception) {
                $success = false;
                $messages[] = $exception->getMessage();
                $syncOrderHistoryResult = Results::ERROR;
            }

            $additionalInformation['new_status'] = $syncOrderRecord->getStatus();
        }

        if ($messages) {
            $additionalInformation['messages'] = array_merge(
                $additionalInformation['messages'] ?? [],
                $messages,
            );
        }
        if ($syncOrderRecord->getEntityId()) {
            try {
                $syncOrderHistoryRecord = $this->syncOrderHistoryModelFactory->createFromSyncOrder(
                    syncOrder: $syncOrderRecord,
                    action: $newStatus->canInitiateSync()
                        ? Actions::QUEUE
                        : Actions::PROCESS_END,
                    via: $via,
                    result: $syncOrderHistoryResult,
                    additionalInformation: $additionalInformation,
                );

                $syncOrderHistoryRecord = $this->syncOrderHistoryRepository->save($syncOrderHistoryRecord);
            } catch (LocalizedException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        return $this->markOrderActionResultFactory->create([
            'success' => $success,
            'syncOrderRecord' => $syncOrderRecord,
            'syncOrderHistoryRecord' => $syncOrderHistoryRecord ?? null,
            'messages' => $messages,
        ]);
    }

    /**
     * @param string $resultStatus
     * @return Statuses
     */
    private function getResultStatusFromArgument(string $resultStatus): Statuses
    {
        $status = Statuses::tryFrom($resultStatus);
        if (!$status) {
            throw new \InvalidArgumentException(sprintf(
                'Result Status "%s" is not a recognised status value',
                $resultStatus,
            ));
        }

        $acceptedStatuses = [
            Statuses::PARTIAL,
            Statuses::SYNCED,
            Statuses::ERROR,
        ];
        if (!in_array($status, $acceptedStatuses, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Result status "%s" is not one of accepted values: %s',
                $resultStatus,
                implode(',', array_map(
                    static fn (Statuses $acceptedStatus): string => $acceptedStatus->value,
                    $acceptedStatuses,
                )),
            ));
        }

        return $status;
    }
}

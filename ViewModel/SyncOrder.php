<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\ViewModel;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class SyncOrder implements ArgumentInterface
{
    use OptionSourceToHashTrait;

    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;
    /**
     * @var OptionSourceInterface
     */
    private readonly OptionSourceInterface $statusOptions;

    /**
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     * @param OptionSourceInterface $statusOptions
     */
    public function __construct(
        SyncOrderRepositoryInterface $syncOrderRepository,
        OptionSourceInterface $statusOptions,
    ) {
        $this->syncOrderRepository = $syncOrderRepository;
        $this->statusOptions = $statusOptions;
    }

    /**
     * @param int $orderId
     * @return SyncOrderInterface|null
     */
    public function getSyncOrderRecordForOrderId(int $orderId): ?SyncOrderInterface
    {
        // SyncOrderRepository implements internal caching, so we don't need to here
        $syncOrderRecord = null;
        if ($orderId) {
            try {
                $syncOrderRecord = $this->syncOrderRepository->getByOrderId($orderId);
            } catch (NoSuchEntityException) {
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            }
        }

        return $syncOrderRecord;
    }

    /**
     * @param string $status
     * @return Phrase
     */
    public function getStatusForDisplay(string $status): Phrase
    {
        $options = $this->getHashForOptionSource(
            $this->statusOptions,
        );

        return $options[$status] ?? __($status);
    }
}

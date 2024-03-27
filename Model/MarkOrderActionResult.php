<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\AnalyticsOrderSyncApi\Api\Data\MarkOrderActionResultInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderHistoryInterface;
use Klevu\AnalyticsOrderSyncApi\Api\Data\SyncOrderInterface;
use Magento\Framework\Phrase;

class MarkOrderActionResult implements MarkOrderActionResultInterface
{
    /**
     * @param bool $success
     * @param SyncOrderInterface|null $syncOrderRecord
     * @param SyncOrderHistoryInterface|null $syncOrderHistoryRecord
     * @param array<string|Phrase> $messages
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?SyncOrderInterface $syncOrderRecord,
        public readonly ?SyncOrderHistoryInterface $syncOrderHistoryRecord,
        public readonly array $messages = [],
    ) {
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return SyncOrderInterface|null
     */
    public function getSyncOrderRecord(): ?SyncOrderInterface
    {
        return $this->syncOrderRecord;
    }

    /**
     * @return SyncOrderHistoryInterface|null
     */
    public function getSyncOrderHistoryRecord(): ?SyncOrderHistoryInterface
    {
        return $this->syncOrderHistoryRecord;
    }

    /**
     * @return string[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}

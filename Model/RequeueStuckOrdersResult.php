<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model;

use Klevu\AnalyticsOrderSyncApi\Api\Data\RequeueStuckOrdersResultInterface;

class RequeueStuckOrdersResult implements RequeueStuckOrdersResultInterface
{
    /**
     * @var bool
     */
    private bool $isSuccess = false;
    /**
     * @var string[]
     */
    private array $messages = [];
    /**
     * @var int
     */
    private int $successCount = 0;
    /**
     * @var int
     */
    private int $errorCount = 0;

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    /**
     * @param bool $isSuccess
     * @return void
     */
    public function setIsSuccess(bool $isSuccess): void
    {
        $this->isSuccess = $isSuccess;
    }

    /**
     * @return string[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @param string $message
     * @return void
     */
    public function addMessage(string $message): void
    {
        if (!in_array($message, $this->messages, true)) {
            $this->messages[] = $message;
        }
    }

    /**
     * @return int
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * @param int $successCount
     * @return void
     */
    public function setSuccessCount(int $successCount): void
    {
        $this->successCount = $successCount;
    }

    /**
     * @return int
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * @param int $errorCount
     * @return void
     */
    public function setErrorCount(int $errorCount): void
    {
        $this->errorCount = $errorCount;
    }
}

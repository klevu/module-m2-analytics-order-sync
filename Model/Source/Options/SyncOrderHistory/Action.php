<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\Options\SyncOrderHistory;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Actions;
use Magento\Framework\Data\OptionSourceInterface;

class Action implements OptionSourceInterface
{
    /**
     * @var mixed[][]|null
     */
    private ?array $options = null;

    /**
     * @return mixed[][]
     */
    public function toOptionArray(): array
    {
        if (null === $this->options) {
            $this->options = [
                [
                    'value' => Actions::MIGRATE->value,
                    'label' => __('Migrated from previous Klevu version'),
                ],
                [
                    'value' => Actions::QUEUE->value,
                    'label' => __('Queued'),
                ],
                [
                    'value' => Actions::PROCESS_START->value,
                    'label' => __('Processing Started'),
                ],
                [
                    'value' => Actions::PROCESS_END->value,
                    'label' => __('Processing Finished'),
                ],
            ];
        }

        return $this->options;
    }
}

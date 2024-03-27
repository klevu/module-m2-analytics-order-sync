<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\Options\SyncOrder;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
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
                    'value' => Statuses::NOT_REGISTERED->value,
                    'label' => __('Not Registered'),
                ],
                [
                    'value' => Statuses::QUEUED->value,
                    'label' => __('Queued'),
                ],
                [
                    'value' => Statuses::PROCESSING->value,
                    'label' => __('Processing'),
                ],
                [
                    'value' => Statuses::SYNCED->value,
                    'label' => __('Synced'),
                ],
                [
                    'value' => Statuses::RETRY->value,
                    'label' => __('Queued (Retry)'),
                ],
                [
                    'value' => Statuses::PARTIAL->value,
                    'label' => __('Partial (Queued)'),
                ],
                [
                    'value' => Statuses::ERROR->value,
                    'label' => __('Error'),
                ],
            ];
        }

        return $this->options;
    }
}

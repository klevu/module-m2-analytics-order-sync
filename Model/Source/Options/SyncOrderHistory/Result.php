<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\Options\SyncOrderHistory;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrderHistory\Results;
use Magento\Framework\Data\OptionSourceInterface;

class Result implements OptionSourceInterface
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
                    'value' => Results::NOOP->value,
                    'label' => __('No Action'),
                ],
                [
                    'value' => Results::SUCCESS->value,
                    'label' => __('Success'),
                ],
                [
                    'value' => Results::ERROR->value,
                    'label' => __('Error'),
                ],
            ];
        }

        return $this->options;
    }
}

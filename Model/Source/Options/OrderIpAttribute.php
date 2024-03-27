<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\Options;

use Magento\Framework\Data\OptionSourceInterface;

class OrderIpAttribute implements OptionSourceInterface
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
                    'value' => 'remote_ip',
                    'label' => __('Remote IP'),
                ],
                [
                    'value' => 'x_forwarded_for',
                    'label' => __('X Forwarded For'),
                ],
            ];
        }

        return $this->options;
    }
}

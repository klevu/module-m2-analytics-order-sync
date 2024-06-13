<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Model\Source\Options;

use Magento\Framework\Data\OptionSourceInterface;

class CronFrequency implements OptionSourceInterface
{
    // 30th Feb, as recommended by devdocs:
    // https://experienceleague.adobe.com/docs/commerce-operations/configuration-guide/crons/custom-cron-reference.html
    public const OPTION_DISABLED = '0 0 30 2 *';
    public const OPTION_CUSTOM = '';

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
                    'value' => static::OPTION_DISABLED,
                    'label' => __('Disabled'),
                ],
                [
                    'value' => '* * * * *',
                    'label' => __('Every minute'),
                ],
                [
                    'value' => '*/5 * * * *',
                    'label' => __('Every 5 minutes'),
                ],
                [
                    'value' => '*/30 * * * *',
                    'label' => __('Every 30 minutes'),
                ],
                [
                    'value' => '0 * * * *',
                    'label' => __('Hourly'),
                ],
                [
                    'value' => '0 2,5,8,11,14,17,20,23 * * *',
                    'label' => __('Every 3 hours (from 2am)'),
                ],
                [
                    'value' => '0 2,8,14,20 * * *',
                    'label' => __('Every 6 hours (from 2am)'),
                ],
                [
                    'value' => '0 2,14 * * *',
                    'label' => __('Every 12 hours (from 2am)'),
                ],
                [
                    'value' => '0 2 * * *',
                    'label' => __('Daily (at 2am)'),
                ],
                [
                    'value' => static::OPTION_CUSTOM,
                    'label' => __('Custom'),
                ],
            ];
        }

        return $this->options;
    }
}

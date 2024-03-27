<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Ui\Component\Listing\Column;

use Klevu\Analytics\Traits\OptionSourceToHashTrait;
use Klevu\AnalyticsOrderSync\Constants;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SyncStatus extends Column
{
    use OptionSourceToHashTrait;

    /**
     * @var OptionSourceInterface
     */
    private OptionSourceInterface $syncOrderStatusOptions;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param OptionSourceInterface $syncOrderStatusOptions
     * @param mixed[] $components
     * @param mixed[] $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        OptionSourceInterface $syncOrderStatusOptions,
        array $components = [],
        array $data = [],
    ) {
        $this->syncOrderStatusOptions = $syncOrderStatusOptions;

        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param mixed[][] $dataSource
     * @return mixed[][]
     */
    public function prepareDataSource(array $dataSource): array
    {
        $dataSource = parent::prepareDataSource($dataSource);

        if ($dataSource['data']['items'] ?? null) {
            $syncOrderStatusHash = $this->getHashForOptionSource($this->syncOrderStatusOptions);
            array_walk(
                $dataSource['data']['items'],
                // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedPassingByReference
                static function (array &$item) use ($syncOrderStatusHash): void {
                    $item[Constants::EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS]
                        = $syncOrderStatusHash[$item[Constants::EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS]]
                        ?? __($item[Constants::EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS]);
                },
            );
        }

        return $dataSource;
    }
}

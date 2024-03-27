<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Block\Adminhtml\Order\View\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Widget\Tab\TabInterface;

class OrderSyncHistory extends Template implements TabInterface
{
    /**
     * @var string
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    protected $_template = 'Klevu_AnalyticsOrderSync::order/view/tab/order_sync_history.phtml';

    /**
     * @return string
     */
    public function getTabLabel(): string
    {
        return __('Klevu Order Sync History')->render();
    }

    /**
     * @return string
     */
    public function getTabTitle(): string
    {
        return __('Klevu Order Sync History')->render();
    }

    /**
     * @return bool
     */
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return false;
    }
}

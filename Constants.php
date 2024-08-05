<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong

namespace Klevu\AnalyticsOrderSync;

class Constants
{
    public const XML_PATH_ORDER_SYNC_ENABLED = 'klevu/analytics_order_sync/enabled';
    public const XML_PATH_ORDER_SYNC_CRON_FREQUENCY = 'klevu/analytics_order_sync/cron_frequency';
    public const XML_PATH_ORDER_SYNC_CRON_EXPR = 'klevu/analytics_order_sync/cron_expr';
    public const XML_PATH_ORDER_SYNC_EXCLUDE_STATUS_FROM_SYNC = 'klevu/analytics_order_sync/exclude_status_from_sync';
    public const XML_PATH_ORDER_SYNC_IP_ADDRESS_ATTRIBUTE = 'klevu/analytics_order_sync/ip_address_attribute';
    public const XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_PERIOD_DAYS = 'klevu/analytics_order_sync/duplicate_ip_address_period_days';
    public const XML_PATH_ORDER_SYNC_DUPLICATE_IP_ADDRESS_THRESHOLD = 'klevu/analytics_order_sync/duplicate_ip_address_threshold';
    public const XML_PATH_ORDER_SYNC_SYNC_ORDER_MAX_ATTEMPTS = 'klevu/analytics_order_sync/sync_order_max_attempts';
    public const XML_PATH_ORDER_SYNC_REQUEUE_PROCESSING_ORDERS_AFTER_MINUTES = 'klevu/analytics_order_sync/requeue_processing_orders_after_minutes';
    public const XML_PATH_ORDER_SYNC_REMOVE_SYNCED_ORDER_HISTORY_AFTER_DAYS = 'klevu/analytics_order_sync/remove_synced_order_history_after_days';
    public const XML_PATH_ORDER_SYNC_REMOVE_ERROR_ORDER_HISTORY_AFTER_DAYS = 'klevu/analytics_order_sync/remove_error_order_history_after_days';
    public const EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS = 'klevu_order_sync_status';
    public const EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_ATTEMPTS = 'klevu_order_sync_attempts';
    public const NOTIFICATION_TYPE_DUPLICATE_ORDER_IPS = 'Klevu_AnalyticsOrderSync::duplicate_order_ips';
}

<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Plugin\Sales\OrderGridCollection;

use Klevu\AnalyticsOrderSync\Constants;
use Klevu\AnalyticsOrderSync\Model\ResourceModel\SyncOrder as SyncOrderResource;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSync\Model\SyncOrder as SyncOrderModel;
use Magento\Framework\DB\Sql\ExpressionFactory;
use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;
use Psr\Log\LoggerInterface;

class JoinOrderSyncStatusPlugin
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ExpressionFactory
     */
    private readonly ExpressionFactory $expressionFactory;

    /**
     * @param LoggerInterface $logger
     * @param ExpressionFactory $expressionFactory
     */
    public function __construct(
        LoggerInterface $logger,
        ExpressionFactory $expressionFactory,
    ) {
        $this->logger = $logger;
        $this->expressionFactory = $expressionFactory;
    }

    /**
     * @param OrderGridCollection $subject
     * @param bool $printQuery
     * @param bool $logQuery
     * @return bool[]
     */
    public function beforeLoad(
        OrderGridCollection $subject,
        bool $printQuery = false,
        bool $logQuery = false,
    ): array {
        $return = [$printQuery, $logQuery];
        if ($subject->isLoaded()) {
            return $return;
        }

        try {
            $resource = $subject->getResource();
            $magentoOrderIdField = $resource->getIdFieldName();
            $syncOrderTableName = $resource->getTable(
                tableName: SyncOrderResource::TABLE,
            );

            $select = $subject->getSelect();
            $syncStatusExpression = $this->expressionFactory->create([
                'expression' => sprintf(
                    'IFNULL(%s.%s, "%s")',
                    $syncOrderTableName,
                    SyncOrderModel::FIELD_STATUS,
                    Statuses::NOT_REGISTERED->value,
                ),
            ]);
            $select->joinLeft(
                name: $syncOrderTableName,
                cond: sprintf(
                    '%s.%s = main_table.%s',
                    $syncOrderTableName,
                    SyncOrderModel::FIELD_ORDER_ID,
                    $magentoOrderIdField,
                ),
                cols: [
                    Constants::EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS => $syncStatusExpression,
                ],
            );
        } catch (\Exception $exception) {
            $this->logger->error(
                message: 'Encountered exception joining sync status to order grid',
                context: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'method' => __METHOD__,
                ],
            );
        }

        return $return;
    }

    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    /**
     * @param OrderGridCollection $subject
     * @param string $field
     * @param mixed[]|null $condition
     * @return mixed[]
     */
    public function beforeAddFieldToFilter(
        OrderGridCollection $subject,
        $field,
        $condition = null,
    ): array {
        if (Constants::EXTENSION_ATTRIBUTE_KLEVU_ORDER_SYNC_STATUS !== $field) {
            return [$field, $condition];
        }

        $resource = $subject->getResource();
        $syncOrderTableName = $resource->getTable(
            tableName: SyncOrderResource::TABLE,
        );

        $field = sprintf(
            '%s.%s',
            $syncOrderTableName,
            SyncOrderModel::FIELD_STATUS,
        );
        if (Statuses::NOT_REGISTERED->value === ($condition['eq'] ?? null)) {
            $condition = ['null' => true];
        }

        return [$field, $condition];
    }
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
}

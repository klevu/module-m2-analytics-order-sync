<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Plugin\Sales\OrderRepository;

use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\SyncOrderRepositoryInterface;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class SetOrderSyncExtensionAttributesPlugin
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ExtensionAttributesFactory
     */
    private readonly ExtensionAttributesFactory $extensionAttributesFactory;
    /**
     * @var SyncOrderRepositoryInterface
     */
    private readonly SyncOrderRepositoryInterface $syncOrderRepository;

    /**
     * @param LoggerInterface $logger
     * @param ExtensionAttributesFactory $extensionAttributesFactory
     * @param SyncOrderRepositoryInterface $syncOrderRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ExtensionAttributesFactory $extensionAttributesFactory,
        SyncOrderRepositoryInterface $syncOrderRepository,
    ) {
        $this->logger = $logger;
        $this->extensionAttributesFactory = $extensionAttributesFactory;
        $this->syncOrderRepository = $syncOrderRepository;
    }

    /**
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $result
     * @param mixed $id
     * @return OrderInterface
     */
    public function afterGet(
        OrderRepositoryInterface $subject, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
        OrderInterface $result,
        mixed $id, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    ): OrderInterface {
        try {
            $syncOrder = $this->syncOrderRepository->getByOrderId((int)$result->getEntityId());
        } catch (NoSuchEntityException) {
            $syncOrder = null;
        } catch (\Exception $exception) { // Catch all exceptions so plugin doesn't break other code
            $this->logger->error(
                message: 'Error retrieving sync order status for order #{orderId} extension attributes: {message}',
                context: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'method' => __METHOD__,
                    'orderId' => $result->getEntityId(),
                ],
            );

            return $result;
        }

        $syncOrderStatus = $syncOrder?->getStatus() ?: Statuses::NOT_REGISTERED->value;
        try {
            $klevuOrderSyncStatus = Statuses::from($syncOrderStatus)->value;
        } catch (\ValueError | \TypeError $exception) {
            $this->logger->error(
                message: 'SyncOrder #{syncOrderId} has unrecognised status value set: {statusValue}',
                context: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'method' => __METHOD__,
                    'syncOrderId' => $syncOrder?->getEntityId(),
                    'orderId' => $result->getEntityId(),
                    'statusValue' => $syncOrderStatus,
                ],
            );
            $klevuOrderSyncStatus = '';
        }

        /** @var OrderExtensionInterface $extensionAttributes */
        $extensionAttributes = $result->getExtensionAttributes()
            ?: $this->extensionAttributesFactory->create(
                extensibleClassName: $result::class,
            );
        if (
            !method_exists($extensionAttributes, 'setKlevuOrderSyncStatus')
            || !method_exists($extensionAttributes, 'setKlevuOrderSyncAttempts')
        ) {
            throw new \LogicException(
                'Extension attributes must contain setKlevuOrderSyncAttempts() and setKlevuOrderSyncAttempts()',
            );
        }

        $extensionAttributes->setKlevuOrderSyncStatus($klevuOrderSyncStatus);
        $extensionAttributes->setKlevuOrderSyncAttempts($syncOrder?->getAttempts() ?? 0);
        $result->setExtensionAttributes($extensionAttributes);

        return $result;
    }
}

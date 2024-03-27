<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Pipeline\OrderAnalytics\Stage;

use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessingActionInterface;
use Klevu\Pipelines\Exception\Pipeline\InvalidPipelinePayloadException;
use Klevu\Pipelines\Pipeline\PipelineInterface;
use Klevu\Pipelines\Pipeline\StagesNotSupportedTrait;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class MarkOrderAsProcessing implements PipelineInterface
{
    use StagesNotSupportedTrait;

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var OrderRepositoryInterface
     */
    private readonly OrderRepositoryInterface $orderRepository;
    /**
     * @var MarkOrderAsProcessingActionInterface
     */
    private readonly MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction;
    /**
     * @var string
     */
    private readonly string $identifier;

    /**
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $orderRepository
     * @param MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction
     * @param PipelineInterface[] $stages
     * @param mixed[]|null $args
     * @param string $identifier
     */
    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        MarkOrderAsProcessingActionInterface $markOrderAsProcessingAction,
        array $stages = [],
        ?array $args = null,
        string $identifier = '',
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->markOrderAsProcessingAction = $markOrderAsProcessingAction;

        array_walk($stages, [$this, 'addStage']);
        if ($args) {
            $this->setArgs($args);
        }

        $this->identifier = $identifier;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param mixed[] $args
     * @return void
     */
    public function setArgs(
        array $args, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    ): void {
        // No args supported for this pipeline
    }

    /**
     * @param mixed $payload
     * @param \ArrayAccess<int|string, mixed>|null $context
     * @return int|string
     * @throws LocalizedException
     */
    public function execute(
        mixed $payload,
        ?\ArrayAccess $context = null,
    ): int|string {
        $this->validatePayload($payload);
        $orderId = (int)$payload;

        $result = $this->markOrderAsProcessingAction->execute(
            orderId: $orderId,
            via: $context['system']['via'] ?? '',
            additionalInformation: [
                // phpcs:ignore Magento2.PHP.LiteralNamespaces.LiteralClassUsage
                'pipeline' => 'OrderAnalytics\Stage\MarkOrderAsProcessing',
            ],
        );
        if (!$result->isSuccess()) {
            $syncOrderRecord = $result->getSyncOrderRecord();
            $this->logger->warning(
                message: 'Order #{orderId} could not be locked for processing; additional syncs may be attempted',
                context: [
                    'method' => __METHOD__,
                    'payload' => $payload,
                    'orderId' => $orderId,
                    'syncOrderRecordId' => $syncOrderRecord?->getEntityId(),
                    'messages' => $result->getMessages(),
                ],
            );
        }

        return $payload;
    }

    /**
     * @param mixed $payload
     * @return void
     * @throws InvalidPipelinePayloadException
     */
    private function validatePayload(mixed $payload): void
    {
        if (
            !is_numeric($payload)
            && !(is_string($payload) && ctype_digit($payload))
        ) {
            throw new InvalidPipelinePayloadException(
                pipelineName: static::class,
                message: (string)__(
                    'Payload must be numeric (integer only); Received %1',
                    is_scalar($payload) ? $payload : get_debug_type($payload),
                ),
            );
        }

        $orderId = (int)$payload;
        try {
            $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new InvalidPipelinePayloadException(
                pipelineName: static::class,
                message: (string)__('No order exists for payload "%1"', $payload),
            );
        }
    }
}

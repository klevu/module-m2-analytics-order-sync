<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Pipeline\OrderAnalytics\Stage;

use Klevu\AnalyticsOrderSync\Exception\OrderNotValidException;
use Klevu\AnalyticsOrderSync\Model\Source\SyncOrder\Statuses;
use Klevu\AnalyticsOrderSyncApi\Api\MarkOrderAsProcessedActionInterface;
use Klevu\AnalyticsOrderSyncApi\Service\Action\ProcessFailedOrderSyncActionInterface;
use Klevu\Pipelines\Exception\ExtractionException;
use Klevu\Pipelines\Exception\Pipeline\InvalidPipelineArgumentsException;
use Klevu\Pipelines\Exception\Pipeline\InvalidPipelinePayloadException;
use Klevu\Pipelines\Extractor\Extractor;
use Klevu\Pipelines\Model\Extraction;
use Klevu\Pipelines\Parser\ArgumentConverter;
use Klevu\Pipelines\Parser\SyntaxParser;
use Klevu\Pipelines\Pipeline\PipelineInterface;
use Klevu\Pipelines\Pipeline\StagesNotSupportedTrait;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

class MarkOrderAsProcessed implements PipelineInterface
{
    use StagesNotSupportedTrait;

    public const ARGUMENT_KEY_RESULT = 'result';

    /**
     * @var ArgumentConverter
     */
    private readonly ArgumentConverter $argumentConverter;
    /**
     * @var Extractor
     */
    private readonly Extractor $extractor;
    /**
     * @var OrderRepositoryInterface
     */
    private readonly OrderRepositoryInterface $orderRepository;
    /**
     * @var MarkOrderAsProcessedActionInterface
     */
    private readonly MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction;
    /**
     * @var ProcessFailedOrderSyncActionInterface
     */
    private readonly ProcessFailedOrderSyncActionInterface $processFailedOrderSyncAction;
    /**
     * @var string
     */
    private readonly string $identifier;
    /**
     * @var bool|Extraction|null
     */
    private bool|Extraction|null $resultArgument = null;

    /**
     * @param ArgumentConverter $argumentConverter
     * @param Extractor $extractor
     * @param OrderRepositoryInterface $orderRepository
     * @param MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction
     * @param ProcessFailedOrderSyncActionInterface $processFailedOrderSyncAction
     * @param PipelineInterface[] $stages
     * @param mixed[]|null $args
     * @param string $identifier
     */
    public function __construct(
        ArgumentConverter $argumentConverter,
        Extractor $extractor,
        OrderRepositoryInterface $orderRepository,
        MarkOrderAsProcessedActionInterface $markOrderAsProcessedAction,
        ProcessFailedOrderSyncActionInterface $processFailedOrderSyncAction,
        array $stages = [],
        ?array $args = null,
        string $identifier = '',
    ) {
        $this->argumentConverter = $argumentConverter;
        $this->extractor = $extractor;
        $this->orderRepository = $orderRepository;
        $this->markOrderAsProcessedAction = $markOrderAsProcessedAction;
        $this->processFailedOrderSyncAction = $processFailedOrderSyncAction;

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
    public function setArgs(array $args): void
    {
        if (null === ($args[static::ARGUMENT_KEY_RESULT] ?? null)) {
            throw new InvalidPipelineArgumentsException(
                pipelineName: $this::class,
                arguments: $args,
                message: sprintf(
                    'Result argument (%s) is required',
                    static::ARGUMENT_KEY_RESULT,
                ),
            );
        }

        $this->resultArgument = $this->prepareResultArgument(
            result: $args[static::ARGUMENT_KEY_RESULT],
            arguments: $args,
        );
    }

    /**
     * @param mixed $payload
     * @param \ArrayAccess<int|string, mixed>|null $context
     * @return int|string
     * @throws NoSuchEntityException
     * @throws OrderNotValidException
     */
    public function execute(
        mixed $payload,
        ?\ArrayAccess $context = null,
    ): int|string {
        $this->validatePayload($payload);
        $orderId = (int)$payload;

        $result = $this->getResult(
            resultArgument: $this->resultArgument,
            payload: $orderId,
            context: $context,
        );

        if ($result) {
            $this->processSuccessResult($orderId, $context['system']['via'] ?? '');
        } else {
            $this->processErrorResult($orderId, $context['system']['via'] ?? '');
        }

        return $payload;
    }

    /**
     * @param mixed $resultArgument
     * @param mixed $payload
     * @param ?\ArrayAccess<int|string, mixed> $context
     * @return bool
     */
    private function getResult(
        mixed $resultArgument,
        mixed $payload,
        ?\ArrayAccess $context,
    ): bool {
        $result = $resultArgument;
        if ($result instanceof Extraction) {
            try {
                $result = $this->extractor->extract(
                    source: $payload,
                    accessor: $result->accessor,
                    transformations: $result->transformations,
                    context: $context,
                );
            } catch (ExtractionException $exception) {
                throw new InvalidPipelineArgumentsException(
                    pipelineName: $this::class,
                    arguments: [
                        static::ARGUMENT_KEY_RESULT => $resultArgument,
                    ],
                    message: sprintf(
                        'Result argument (%s) value could not be extracted: %s',
                        static::ARGUMENT_KEY_RESULT,
                        $exception->getMessage(),
                    ),
                );
            }
        }

        if (!is_bool($result)) {
            throw new InvalidPipelineArgumentsException(
                pipelineName: $this::class,
                arguments: [
                    static::ARGUMENT_KEY_RESULT => $resultArgument,
                ],
                message: sprintf(
                    'Result argument (%s) must be bool|%s; Received %s',
                    static::ARGUMENT_KEY_RESULT,
                    Extraction::class,
                    get_debug_type($result),
                ),
            );
        }

        return $result;
    }

    /**
     * @param mixed $result
     * @param mixed[]|null $arguments
     * @return bool|Extraction
     */
    private function prepareResultArgument(
        mixed $result,
        ?array $arguments,
    ): bool|Extraction {
        if (
            is_string($result)
            && str_starts_with($result, SyntaxParser::EXTRACTION_START_CHARACTER)
        ) {
            $resultArgument = $this->argumentConverter->execute($result);
            $result = $resultArgument->getValue();
        }

        if (!is_bool($result) && !($result instanceof Extraction)) {
            throw new InvalidPipelineArgumentsException(
                pipelineName: $this::class,
                arguments: $arguments,
                message: sprintf(
                    'Result argument (%s) must be bool|%s; Received %s',
                    static::ARGUMENT_KEY_RESULT,
                    Extraction::class,
                    get_debug_type($result),
                ),
            );
        }

        return $result;
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
                pipelineName: $this::class,
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
                pipelineName: $this::class,
                message: (string)__('No order exists for payload "%1"', $payload),
            );
        }
    }

    /**
     * @param int $orderId
     * @param string $via
     * @return void
     */
    private function processSuccessResult(int $orderId, string $via): void
    {
        $this->markOrderAsProcessedAction->execute(
            orderId: $orderId,
            resultStatus: Statuses::SYNCED->value,
            via: $via,
            additionalInformation: [
                // phpcs:ignore Magento2.PHP.LiteralNamespaces.LiteralClassUsage
                'pipeline' => 'OrderAnalytics\Stage\MarkOrderAsProcessed',
            ],
        );
    }

    /**
     * @param int $orderId
     * @param string $via
     * @return void
     * @throws OrderNotValidException
     * @throws NoSuchEntityException
     *
     * Note: we're not handling these exceptions and letting them bubble up
     * This is because the orderId should already be validated before this method is called
     *  (NoSuchEntityException) and OrderNotValidException occurs if the order
     *  has no entity id, but we're retrieving straight from the db
     */
    private function processErrorResult(int $orderId, string $via): void
    {
        $this->processFailedOrderSyncAction->execute(
            order: $this->orderRepository->get($orderId),
            via: $via,
            additionalInformation: [
                // phpcs:ignore Magento2.PHP.LiteralNamespaces.LiteralClassUsage
                'pipeline' => 'OrderAnalytics\Stage\MarkOrderAsProcessed',
            ],
        );
    }
}

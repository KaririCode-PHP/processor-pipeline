<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Handler;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;

/**
 * Wraps one processor so its failure is recorded in a result collection.
 *
 * Fails closed by default (Spec V4.3.3 §8.3, §18): the failure is recorded
 * and rethrown, so no later stage runs on data a validator rejected. With
 * `haltOnError: false` the caller opts into collect-all mode: the failure is
 * recorded and the stage passes its input through unchanged, so later
 * stages still run — use it only when the returned value is discarded or
 * re-checked whenever `$results->hasErrors` is true.
 *
 * Side effects: every call appends the stage name to the collection's
 * execution trace before running the processor; a failure appends one
 * error under ERROR_KEY with the processor's message. The collection is
 * shared state: one per execution (ProcessingResultCollection §12).
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $collectAll = new ProcessorHandler(new RequiredValidator(), $results, haltOnError: false);
 *
 * assert($collectAll->process('') === '');
 * assert($results->errors[RequiredValidator::class][0]['errorKey'] === ProcessorHandler::ERROR_KEY);
 * ```
 *
 * @see \KaririCode\ProcessorPipeline\Result\ProcessingResultCollection
 * @see \KaririCode\ProcessorPipeline\Handler\StageName Identity recorded in the trace and errors
 */
final readonly class ProcessorHandler implements ProcessorDecorator
{
    /** Error key recorded for every failure the handler catches. */
    public const string ERROR_KEY = 'processingFailed';

    public function __construct(
        private Processor $processor,
        private ProcessingResultCollection $resultCollection,
        private bool $haltOnError = true,
    ) {}

    /**
     * Execute the wrapped processor with error collection.
     *
     * @param mixed $input Data to process
     *
     * @return mixed The processor's output, or the input itself after a failure in collect-all mode
     *
     * @throws \Throwable The processor's failure, unless `haltOnError` is false
     */
    #[\Override]
    public function process(mixed $input): mixed
    {
        $stageName = StageName::of($this->processor);
        $this->resultCollection->recordExecution($stageName);

        try {
            return $this->processor->process($input);
        } catch (\Throwable $throwable) {
            $this->resultCollection->addError(
                processorClass: $stageName,
                errorKey: self::ERROR_KEY,
                message: $throwable->getMessage(),
            );

            if ($this->haltOnError) {
                throw $throwable;
            }

            return $input;
        }
    }

    /**
     * The wrapped processor — lets the pipeline name the stage after it (ARFA P5).
     */
    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this->processor;
    }
}

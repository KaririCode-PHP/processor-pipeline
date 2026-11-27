<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Exception;

use KaririCode\Contract\Exception\Runtime;

/**
 * A stage threw while the pipeline was running.
 *
 * The stage's throwable is `getPrevious()`, never part of the message: a
 * processor's message can carry the rejected input. The message names the
 * stage index and the stage's class, which are code identifiers. A failure
 * raised by a nested pipeline stays chained, so outer and inner stages are
 * both recoverable (ADR-006).
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 *
 * try {
 *     $builder->buildPipeline('validator', ['email'])->process('nope');
 * } catch (PipelineExecutionException $e) {
 *     assert($e->context['stage'] === 0);
 *     assert($e->getPrevious()?->getMessage() === 'Invalid email.');
 * }
 * ```
 */
final class PipelineExecutionException extends ProcessorPipelineException implements Runtime
{
    public const int STAGE_FAILED = 8520;

    public static function atStage(string $processorName, int $stageIndex, \Throwable $cause): self
    {
        return new self(
            code: self::STAGE_FAILED,
            text: \sprintf('Pipeline failed at stage %d (processor: %s).', $stageIndex, $processorName),
            context: ['processor' => $processorName, 'stage' => $stageIndex, 'causeClass' => $cause::class],
            previous: $cause,
        );
    }
}

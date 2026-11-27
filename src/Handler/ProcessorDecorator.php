<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Handler;

use KaririCode\Contract\Processor\Processor;

/**
 * A processor that wraps another processor.
 *
 * Decorators add cross-cutting behaviour (error collection, retries, timing)
 * around a wrapped processor. Exposing the wrapped instance lets the pipeline
 * attribute a stage to the processor doing the real work instead of the
 * wrapper — a failure inside {@see ProcessorHandler} is reported as the
 * wrapped class, not as `ProcessorHandler`.
 *
 * ARFA 1.3 Compliance
 * ===================
 *
 * P5 (Continuous Observability):
 *   Traces, metrics and exception context name the innermost processor of a
 *   decorator chain, so every operation remains identifiable.
 *
 * Usage
 * =====
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * final readonly class RetryingProcessor implements ProcessorDecorator
 * {
 *     public function __construct(private Processor $inner, private int $attempts = 2)
 *     {
 *     }
 *
 *     public function innerProcessor(): Processor
 *     {
 *         return $this->inner;
 *     }
 *
 *     public function process(mixed $input): mixed
 *     {
 *         for ($attempt = 1; ; ++$attempt) {
 *             try {
 *                 return $this->inner->process($input);
 *             } catch (\RuntimeException $e) {
 *                 if ($attempt >= $this->attempts) {
 *                     throw $e;
 *                 }
 *             }
 *         }
 *     }
 * }
 *
 * $log = new StageLog();
 * new Pipeline([new RetryingProcessor(new TrimProcessor())], $log)->process(' a ');
 *
 * assert($log->lines === ['#0 TrimProcessor ok']); // named after the inner processor
 * ```
 *
 * @since     3.0.0
 *
 * @see \KaririCode\ProcessorPipeline\Handler\ProcessorHandler Reference decorator
 * @see \KaririCode\ProcessorPipeline\Pipeline\Pipeline        Stage attribution
 */
interface ProcessorDecorator extends Processor
{
    /**
     * The processor this decorator delegates to.
     */
    public function innerProcessor(): Processor;
}

<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Observer;

/**
 * Receives one notification per executed pipeline stage.
 *
 * The observer is the metrics half of ARFA 1.3 P5
 * (`∀operation: ∃trace(operation) ∧ ∃metrics(operation)`): every stage
 * reports its index, the processor that ran, and its wall-clock duration
 * measured with the monotonic `hrtime()` clock.
 *
 * The interface is deliberately vendor-neutral (ARFA 1.3 P4). Bridges to a
 * concrete backend — KaririCode\Observability, OpenTelemetry, StatsD —
 * implement it outside this component.
 *
 * Contract
 * ========
 *
 * - Exactly one of the two methods is called per executed stage.
 * - Stages after a failure are not executed and therefore not reported.
 * - `$processorClass` names the innermost processor of a decorator chain
 *   (see {@see \KaririCode\ProcessorPipeline\Handler\ProcessorDecorator}).
 * - Implementations must not throw. An exception raised by an observer
 *   propagates out of `Pipeline::process()` and aborts the run.
 * - Pipelines without an observer skip timing entirely (zero overhead).
 *
 * Usage
 * =====
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Observer\PipelineObserver;
 *
 * final class StageLatencyRecorder implements PipelineObserver
 * {
 *     public array $durations = []; // processor => list of durations (ns)
 *     public array $failures = [];  // processor => failure count
 *
 *     public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void
 *     {
 *         $this->durations[$processorClass][] = $durationNs;
 *     }
 *
 *     public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, \Throwable $cause): void
 *     {
 *         $this->durations[$processorClass][] = $durationNs;
 *         $this->failures[$processorClass] = ($this->failures[$processorClass] ?? 0) + 1;
 *     }
 * }
 *
 * $recorder = new StageLatencyRecorder();
 * $pipeline = $builder->buildPipeline('validator', ['required', 'email'])->withObserver($recorder);
 *
 * $pipeline->process('dev@kariricode.org');
 * try {
 *     $pipeline->process('nope');
 * } catch (\Throwable) {
 * }
 *
 * assert(count($recorder->durations[RequiredValidator::class]) === 2);
 * assert($recorder->failures === [EmailValidator::class => 1]);
 * ```
 *
 * @since     3.0.0
 *
 * @see \KaririCode\ProcessorPipeline\Pipeline\Pipeline::withObserver()
 */
interface PipelineObserver
{
    /**
     * A stage returned normally.
     *
     * @param int    $stageIndex     Zero-based stage position
     * @param string $processorClass Fully-qualified class of the processor that ran
     * @param int    $durationNs     Monotonic wall-clock duration in nanoseconds
     */
    public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void;

    /**
     * A stage threw; the pipeline aborts after this notification.
     *
     * @param int        $stageIndex     Zero-based stage position
     * @param string     $processorClass Fully-qualified class of the processor that ran
     * @param int        $durationNs     Monotonic wall-clock duration in nanoseconds
     * @param \Throwable $cause          The throwable raised by the stage
     */
    public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, \Throwable $cause): void;
}

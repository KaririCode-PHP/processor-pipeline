<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Integration;

use KaririCode\Contract\Processor\Processor;
use KaririCode\Contract\Resilience\ManualCircuitBreaker;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;

/**
 * Runs a stage only when a circuit breaker admits the call, and reports
 * how the call ended so the breaker can open after repeated faults.
 *
 * A rejected call never reaches the processor: the breaker's own
 * CallNotPermittedException propagates, carrying `$retryAfterSeconds`, and
 * the pipeline wraps it like any stage failure. An admitted call settles
 * its permit exactly once — `recordSuccess()` or `recordFailure()` with the
 * error, which the breaker classifies (validation errors need not count).
 *
 * Side effects: every call acquires and settles a permit, which writes the
 * breaker's state — possibly a shared store such as Redis. Thread-safety and
 * cross-process behaviour are the breaker's (kariricode/circuit-breaker).
 *
 * ```php
 * // illustrative — $breaker implements Contract\Resilience\ManualCircuitBreaker
 * use KaririCode\ProcessorPipeline\Integration\CircuitBreakerProcessor;
 *
 * $stage = new CircuitBreakerProcessor(new CrmLookupProcessor($client), $breaker);
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim'])->withProcessor($stage);
 * ```
 *
 * @see \KaririCode\Contract\Resilience\ManualCircuitBreaker kariricode/contract 2.10.0 (ADR-004)
 * @see https://martinfowler.com/bliki/CircuitBreaker.html
 */
final readonly class CircuitBreakerProcessor implements ProcessorDecorator
{
    public function __construct(
        private Processor $inner,
        private ManualCircuitBreaker $breaker,
    ) {}

    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this->inner;
    }

    /**
     * @throws \KaririCode\Contract\Resilience\CallNotPermittedException When the breaker rejects the call
     * @throws \Throwable The processor's own failure, after it is recorded
     */
    #[\Override]
    public function process(mixed $input): mixed
    {
        $permit = $this->breaker->acquirePermit();

        try {
            /** @psalm-suppress MixedAssignment The Processor contract is mixed → mixed. */
            $output = $this->inner->process($input);
        } catch (\Throwable $error) {
            $this->breaker->recordFailure($permit, $error);

            throw $error;
        }

        $this->breaker->recordSuccess($permit);

        return $output;
    }
}

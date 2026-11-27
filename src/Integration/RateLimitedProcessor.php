<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Integration;

use KaririCode\Contract\Processor\Processor;
use KaririCode\Contract\Resilience\RateLimiter;
use KaririCode\ProcessorPipeline\Exception\RateLimitExceededException;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
use KaririCode\ProcessorPipeline\Handler\StageName;

/**
 * Runs a stage only while its rate limit allows, to protect what the stage
 * calls (an API quota, a slow dependency).
 *
 * Each call asks the limiter for `$cost` units under `$key`. A denial is a
 * value in the contract; here it becomes RateLimitExceededException
 * (code 8530, a CallNotPermittedException) with the limiter's wait, and the
 * processor does not run. The key defaults to the stage name; pass one per
 * tenant or caller to limit them separately.
 *
 * Side effects: an allowed call consumes units in the limiter's store,
 * possibly shared across processes. A denial consumes nothing.
 *
 * ```php
 * // illustrative — $limiter implements Contract\Resilience\RateLimiter
 * use KaririCode\ProcessorPipeline\Integration\RateLimitedProcessor;
 *
 * $stage = new RateLimitedProcessor(new GeocodeProcessor($api), $limiter, key: "geocode:{$tenantId}");
 * ```
 *
 * @see \KaririCode\Contract\Resilience\RateLimiter kariricode/contract 2.10.0 (ADR-004)
 */
final readonly class RateLimitedProcessor implements ProcessorDecorator
{
    private string $key;

    public function __construct(
        private Processor $inner,
        private RateLimiter $limiter,
        ?string $key = null,
        private int $cost = 1,
    ) {
        $this->key = $key ?? StageName::of($inner);
    }

    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this->inner;
    }

    /**
     * @throws RateLimitExceededException When the limiter denies the call
     */
    #[\Override]
    public function process(mixed $input): mixed
    {
        $decision = $this->limiter->attempt($this->key, $this->cost);

        if (!$decision->allowed) {
            throw RateLimitExceededException::forStage(StageName::of($this->inner), $decision->retryAfter);
        }

        return $this->inner->process($input);
    }
}

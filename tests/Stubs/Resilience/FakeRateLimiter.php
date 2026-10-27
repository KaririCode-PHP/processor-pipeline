<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs\Resilience;

use KaririCode\Contract\Resilience\RateLimitDecision;
use KaririCode\Contract\Resilience\RateLimiter;

/** Allows a fixed number of units per key, then denies with a fixed wait. */
final class FakeRateLimiter implements RateLimiter
{
    /** @var list<array{string, int}> */
    public array $attempts = [];

    /** @var array<string, int> */
    private array $used = [];

    public function __construct(
        private readonly int $limit = 1,
        private readonly int $retryAfter = 7,
    ) {}

    #[\Override]
    public function attempt(string $key, int $cost = 1): RateLimitDecision
    {
        $this->attempts[] = [$key, $cost];
        $used = $this->used[$key] ?? 0;

        if ($used + $cost > $this->limit) {
            return new FakeDecision(false, $this->limit, $this->limit - $used, $this->retryAfter, $this->retryAfter);
        }

        $this->used[$key] = $used + $cost;

        return new FakeDecision(true, $this->limit, $this->limit - $used - $cost, 0, $this->retryAfter);
    }

    #[\Override]
    public function peek(string $key): RateLimitDecision
    {
        $used = $this->used[$key] ?? 0;

        return new FakeDecision($used < $this->limit, $this->limit, $this->limit - $used, 0, 0);
    }

    #[\Override]
    public function reset(string $key): void
    {
        unset($this->used[$key]);
    }
}

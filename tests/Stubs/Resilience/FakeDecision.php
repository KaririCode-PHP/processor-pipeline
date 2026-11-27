<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs\Resilience;

use KaririCode\Contract\Resilience\RateLimitDecision;

final readonly class FakeDecision implements RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
        public int $resetAfter,
    ) {}
}

<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs\Resilience;

use KaririCode\Contract\Resilience\RetryPolicy;

/** Retries any \RuntimeException up to $maxAttempts calls, waiting $delay × attempt ms. */
final class FakeRetryPolicy implements RetryPolicy
{
    /** @var list<int> */
    public array $askedFor = [];

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $delay = 10,
    ) {}

    #[\Override]
    public function shouldRetry(\Throwable $error, int $attempt): bool
    {
        $this->askedFor[] = $attempt;

        return $error instanceof \RuntimeException && $attempt < $this->maxAttempts;
    }

    #[\Override]
    public function delayMilliseconds(\Throwable $error, int $attempt): int
    {
        return $this->delay * $attempt;
    }
}

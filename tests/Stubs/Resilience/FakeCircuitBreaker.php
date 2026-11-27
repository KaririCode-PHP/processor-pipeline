<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs\Resilience;

use KaririCode\Contract\Resilience\CallPermit;
use KaririCode\Contract\Resilience\ManualCircuitBreaker;

/** Scripted breaker: admits or rejects, and records how each permit settled. */
final class FakeCircuitBreaker implements ManualCircuitBreaker
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(
        private readonly bool $admits = true,
        private readonly ?int $retryAfterSeconds = 30,
    ) {}

    #[\Override]
    public function execute(callable $operation): mixed
    {
        return $operation();
    }

    #[\Override]
    public function isOpen(): bool
    {
        return !$this->admits;
    }

    #[\Override]
    public function tryAcquirePermit(): ?CallPermit
    {
        return $this->admits ? new FakePermit() : null;
    }

    #[\Override]
    public function acquirePermit(): CallPermit
    {
        $this->log[] = 'acquire';

        return $this->admits ? new FakePermit() : throw new FakeCallNotPermitted($this->retryAfterSeconds);
    }

    #[\Override]
    public function recordSuccess(CallPermit $permit): void
    {
        $this->log[] = 'success';
    }

    #[\Override]
    public function recordFailure(CallPermit $permit, \Throwable $error): void
    {
        $this->log[] = 'failure:' . $error->getMessage();
    }

    #[\Override]
    public function releasePermit(CallPermit $permit): void
    {
        $this->log[] = 'release';
    }
}

<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Integration;

use KaririCode\Contract\Processor\Processor;
use KaririCode\Contract\Resilience\RetryPolicy;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;

/**
 * Runs a stage again after a failure the policy classifies as transient.
 *
 * The loop is the one kariricode/contract documents for RetryPolicy:
 * `$attempt` counts the calls already made, all failed; the policy decides
 * whether to retry and how many milliseconds to wait, and may honour a wait
 * the error carries (an open circuit's `$retryAfterSeconds`). The policy
 * bounds the number of attempts; the last error is rethrown unchanged.
 *
 * Preconditions: the processor must be safe to repeat with the same input.
 * Retrying input validation is pointless — let the policy decline it.
 *
 * Side effects: the default wait blocks the process (`time_nanosleep`). On
 * PHP-FPM that holds a worker, so keep attempts few and delays capped
 * (Spec V4.3.3 conformance level 1 has no event loop).
 *
 * Composition: wrap a CircuitBreakerProcessor so each attempt goes through
 * the breaker — the order Resilience4j applies, Retry(CircuitBreaker(
 * RateLimiter(call))).
 *
 * ```php
 * use KaririCode\Contract\Resilience\RetryPolicy;
 * use KaririCode\ProcessorPipeline\Integration\RetryingProcessor;
 *
 * $policy = new class implements RetryPolicy {
 *     public function shouldRetry(\Throwable $error, int $attempt): bool
 *     {
 *         return $error instanceof \RuntimeException && $attempt < 3;
 *     }
 *
 *     public function delayMilliseconds(\Throwable $error, int $attempt): int
 *     {
 *         return 0;
 *     }
 * };
 *
 * $stage = new RetryingProcessor(new TrimProcessor(), $policy);
 * assert($stage->process(' a ') === 'a');
 * ```
 *
 * @see \KaririCode\Contract\Resilience\RetryPolicy kariricode/contract 2.10.0 (ADR-004)
 * @see https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/
 * @see https://resilience4j.readme.io/docs/retry
 */
final readonly class RetryingProcessor implements ProcessorDecorator
{
    /** @var \Closure(int): void */
    private \Closure $wait;

    /**
     * @param (callable(int): void)|null $wait Receives each delay in milliseconds; defaults to a blocking sleep
     */
    public function __construct(
        private Processor $inner,
        private RetryPolicy $policy,
        ?callable $wait = null,
    ) {
        $this->wait = $wait === null ? self::sleep(...) : \Closure::fromCallable($wait);
    }

    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this->inner;
    }

    /**
     * @throws \Throwable The last failure, once the policy declines to retry
     */
    #[\Override]
    public function process(mixed $input): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->inner->process($input);
            } catch (\Throwable $error) {
                if (!$this->policy->shouldRetry($error, $attempt)) {
                    throw $error;
                }

                ($this->wait)(max(0, $this->policy->delayMilliseconds($error, $attempt)));
            }
        }
    }

    private static function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            time_nanosleep(intdiv($milliseconds, 1000), $milliseconds % 1000 * 1_000_000);
        }
    }
}

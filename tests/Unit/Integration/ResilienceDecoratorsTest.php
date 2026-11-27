<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Integration;

use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Processor\Processor;
use KaririCode\Contract\Resilience\CallNotPermittedException;
use KaririCode\ProcessorPipeline\Exception\RateLimitExceededException;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
use KaririCode\ProcessorPipeline\Handler\StageName;
use KaririCode\ProcessorPipeline\Integration\CircuitBreakerProcessor;
use KaririCode\ProcessorPipeline\Integration\RateLimitedProcessor;
use KaririCode\ProcessorPipeline\Integration\RetryingProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\Resilience\FakeCallNotPermitted;
use KaririCode\ProcessorPipeline\Tests\Stubs\Resilience\FakeCircuitBreaker;
use KaririCode\ProcessorPipeline\Tests\Stubs\Resilience\FakeRateLimiter;
use KaririCode\ProcessorPipeline\Tests\Stubs\Resilience\FakeRetryPolicy;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubFailingProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ResilienceDecoratorsTest extends TestCase
{
    // ── Circuit breaker ──────────────────────────────────────────────

    public function testAdmittedSuccessIsRecorded(): void
    {
        $breaker = new FakeCircuitBreaker();
        $stage = new CircuitBreakerProcessor(StubProcessor::uppercase(), $breaker);

        $this->assertSame('ABC', $stage->process('abc'));
        $this->assertSame(['acquire', 'success'], $breaker->log);
    }

    public function testAdmittedFailureIsRecordedWithItsErrorAndRethrown(): void
    {
        $breaker = new FakeCircuitBreaker();
        $error = new \RuntimeException('backend down');
        $stage = new CircuitBreakerProcessor(new StubFailingProcessor($error), $breaker);

        try {
            $stage->process('x');
            $this->fail('expected the failure to be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame(['acquire', 'failure:backend down'], $breaker->log);
    }

    #[Group('fail-closed')]
    public function testRejectedCallNeverRunsTheProcessor(): void
    {
        $inner = new CountingProcessor();
        $stage = new CircuitBreakerProcessor($inner, new FakeCircuitBreaker(admits: false, retryAfterSeconds: 30));

        try {
            $stage->process('x');
            $this->fail('expected a rejection');
        } catch (CallNotPermittedException $rejected) {
            $this->assertSame(30, $rejected->retryAfterSeconds);
        }
        $this->assertSame(0, $inner->calls);
    }

    // ── Rate limiter ─────────────────────────────────────────────────

    public function testAllowedCallRunsAndConsumesUnderTheGivenKeyAndCost(): void
    {
        $limiter = new FakeRateLimiter(limit: 5);
        $stage = new RateLimitedProcessor(StubProcessor::uppercase(), $limiter, key: 'api:crm', cost: 2);

        $this->assertSame('X', $stage->process('x'));
        $this->assertSame([['api:crm', 2]], $limiter->attempts);
    }

    public function testKeyDefaultsToTheStageName(): void
    {
        $limiter = new FakeRateLimiter(limit: 5);
        $inner = StubProcessor::uppercase();

        new RateLimitedProcessor($inner, $limiter)->process('x');

        $this->assertSame([[StageName::of($inner), 1]], $limiter->attempts);
    }

    #[Group('fail-closed')]
    public function testDenialThrowsWithTheWaitAndNeverRunsTheProcessor(): void
    {
        $inner = new CountingProcessor();
        $stage = new RateLimitedProcessor($inner, new FakeRateLimiter(limit: 1, retryAfter: 7), key: '203.0.113.9');
        $stage->process('first');

        try {
            $stage->process('second');
            $this->fail('expected a denial');
        } catch (RateLimitExceededException $denied) {
            $this->assertInstanceOf(CallNotPermittedException::class, $denied);
            $this->assertInstanceOf(Runtime::class, $denied);
            $this->assertSame(RateLimitExceededException::LIMIT_REACHED, $denied->getCode());
            $this->assertSame(7, $denied->retryAfterSeconds);
            $this->assertStringNotContainsString('203.0.113.9', $denied->getMessage());
            $this->assertNotContains('203.0.113.9', $denied->context);
        }
        $this->assertSame(1, $inner->calls);
    }

    // ── Retry ────────────────────────────────────────────────────────

    public function testRetriesUntilSuccessAndWaitsAsThePolicySays(): void
    {
        $inner = new FlakyProcessor(failures: 2);
        $policy = new FakeRetryPolicy(maxAttempts: 3, delay: 10);
        $waits = [];
        $stage = new RetryingProcessor($inner, $policy, static function (int $ms) use (&$waits): void {
            $waits[] = $ms;
        });

        $this->assertSame('ok:x', $stage->process('x'));
        $this->assertSame([1, 2], $policy->askedFor);
        $this->assertSame([10, 20], $waits);
        $this->assertSame(3, $inner->calls);
    }

    public function testStopsWhenThePolicyDeclinesAndRethrowsTheLastError(): void
    {
        $inner = new FlakyProcessor(failures: 5);
        $waits = [];
        $stage = new RetryingProcessor($inner, new FakeRetryPolicy(maxAttempts: 2), static function (int $ms) use (&$waits): void {
            $waits[] = $ms;
        });

        try {
            $stage->process('x');
            $this->fail('expected the last failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('flaky #2', $error->getMessage());
        }
        $this->assertSame(2, $inner->calls);
        $this->assertCount(1, $waits);
    }

    public function testNonRetryableErrorsAreRethrownAtOnce(): void
    {
        $inner = new StubFailingProcessor(new \LogicException('bug'));
        $policy = new FakeRetryPolicy();
        $stage = new RetryingProcessor($inner, $policy, static fn(int $ms) => null);

        $this->expectException(\LogicException::class);
        try {
            $stage->process('x');
        } finally {
            $this->assertSame([1], $policy->askedFor);
        }
    }

    public function testRetryHonoursTheBreakerRejection(): void
    {
        $policy = new FakeRetryPolicy(maxAttempts: 2);
        $stage = new RetryingProcessor(
            new CircuitBreakerProcessor(new CountingProcessor(), new FakeCircuitBreaker(admits: false)),
            $policy,
            static fn(int $ms) => null,
        );

        $this->expectException(FakeCallNotPermitted::class);
        $stage->process('x');
    }

    public function testDefaultWaitSleepsForTheDelay(): void
    {
        $stage = new RetryingProcessor(new FlakyProcessor(failures: 1), new FakeRetryPolicy(maxAttempts: 2, delay: 2));
        $started = hrtime(true);

        $this->assertSame('ok:x', $stage->process('x'));
        $this->assertGreaterThanOrEqual(2_000_000, hrtime(true) - $started);
    }

    public function testNegativeAndZeroDelaysWaitZero(): void
    {
        $policy = new class implements \KaririCode\Contract\Resilience\RetryPolicy {
            public function shouldRetry(\Throwable $error, int $attempt): bool
            {
                return $attempt < 3;
            }

            public function delayMilliseconds(\Throwable $error, int $attempt): int
            {
                return $attempt === 1 ? -5 : 0;
            }
        };
        $waits = [];
        $stage = new RetryingProcessor(new FlakyProcessor(failures: 2), $policy, static function (int $ms) use (&$waits): void {
            $waits[] = $ms;
        });

        $stage->process('x');
        $this->assertSame([0, 0], $waits);
    }

    // ── All three ────────────────────────────────────────────────────

    public function testDecoratorsExposeTheirInnerProcessorForStageNaming(): void
    {
        $inner = StubProcessor::uppercase();
        $composed = new RetryingProcessor(
            new CircuitBreakerProcessor(
                new RateLimitedProcessor($inner, new FakeRateLimiter(limit: 9)),
                new FakeCircuitBreaker(),
            ),
            new FakeRetryPolicy(),
            static fn(int $ms) => null,
        );

        $this->assertInstanceOf(ProcessorDecorator::class, $composed);
        $this->assertSame($inner::class, StageName::of($composed));
        $this->assertSame('X', $composed->process('x'));
    }
}

final class CountingProcessor implements Processor
{
    public int $calls = 0;

    #[\Override]
    public function process(mixed $input): mixed
    {
        ++$this->calls;

        return $input;
    }
}

final class FlakyProcessor implements Processor
{
    public int $calls = 0;

    public function __construct(
        private int $failures,
    ) {}

    #[\Override]
    public function process(mixed $input): mixed
    {
        ++$this->calls;
        if ($this->failures-- > 0) {
            throw new \RuntimeException('flaky #' . $this->calls);
        }

        return 'ok:' . (\is_string($input) ? $input : '');
    }
}

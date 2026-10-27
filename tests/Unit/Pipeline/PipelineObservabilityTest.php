<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Pipeline;

use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
use KaririCode\ProcessorPipeline\Tests\Stubs\RecordingObserver;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubFailingProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubSelfDecorator;
use PHPUnit\Framework\TestCase;

/**
 * ARFA 1.3 P5 — ∀operation: ∃trace(operation) ∧ ∃metrics(operation).
 */
final class PipelineObservabilityTest extends TestCase
{
    // ── Metrics per stage ───────────────────────────────────────────

    public function testObserverReceivesOneSuccessPerStageInOrder(): void
    {
        $observer = new RecordingObserver();
        $pipeline = new Pipeline([StubProcessor::trim(), StubProcessor::uppercase()], $observer);

        $this->assertSame('HI', $pipeline->process('  hi  '));

        $this->assertCount(2, $observer->events);
        $this->assertSame([0, 1], array_column($observer->events, 'stage'));
        $this->assertSame(['succeeded', 'succeeded'], array_column($observer->events, 'event'));
        foreach ($observer->events as $event) {
            $this->assertGreaterThanOrEqual(0, $event['durationNs']);
        }
    }

    public function testObserverReceivesFailureWithCauseAndNoLaterStages(): void
    {
        $observer = new RecordingObserver();
        $cause = new \RuntimeException('boom');
        $pipeline = new Pipeline([
            StubProcessor::identity(),
            StubFailingProcessor::withException($cause),
            StubProcessor::identity(),
        ], $observer);

        try {
            $pipeline->process('x');
            $this->fail('Expected PipelineExecutionException');
        } catch (PipelineExecutionException) {
        }

        $this->assertSame(['succeeded', 'failed'], array_column($observer->events, 'event'));
        $this->assertSame(1, $observer->events[1]['stage']);
        $this->assertSame(StubFailingProcessor::class, $observer->events[1]['processor']);
        $this->assertSame($cause, $observer->events[1]['cause']);
    }

    public function testObserverSeesTheDecoratedProcessorName(): void
    {
        $observer = new RecordingObserver();
        $pipeline = new Pipeline([
            new ProcessorHandler(StubProcessor::uppercase(), new ProcessingResultCollection()),
        ], $observer);

        $pipeline->process('x');

        $this->assertSame(StubProcessor::class, $observer->events[0]['processor']);
    }

    // ── Observer propagation (immutability, P1) ─────────────────────

    public function testWithObserverReturnsNewInstanceAndLeavesOriginalUnobserved(): void
    {
        $observer = new RecordingObserver();
        $original = new Pipeline([StubProcessor::identity()]);
        $observed = $original->withObserver($observer);

        $original->process('x');
        $this->assertSame([], $observer->events);

        $observed->process('x');
        $this->assertCount(1, $observer->events);
        $this->assertNotSame($original, $observed);
    }

    public function testWithProcessorAndWithPipelineKeepTheObserver(): void
    {
        $observer = new RecordingObserver();
        $base = new Pipeline([StubProcessor::identity()], $observer);

        $base->withProcessor(StubProcessor::identity())
            ->withPipeline(new Pipeline([StubProcessor::identity()]))
            ->process('x');

        $this->assertCount(3, $observer->events);
    }

    public function testWithObserverNullDetaches(): void
    {
        $observer = new RecordingObserver();
        $pipeline = new Pipeline([StubProcessor::identity()], $observer);

        $pipeline->withObserver(null)->process('x');

        $this->assertSame([], $observer->events);
    }

    // ── Termination theorem: bounded decorator walk ─────────────────

    public function testCyclicDecoratorTerminatesAndIsStillNamed(): void
    {
        $pipeline = new Pipeline([new StubSelfDecorator()]);

        try {
            $pipeline->process('x');
            $this->fail('Expected PipelineExecutionException');
        } catch (PipelineExecutionException $exception) {
            $this->assertSame(StubSelfDecorator::class, $exception->context['processor']);
            $this->assertSame(0, $exception->context['stage']);
        }
    }

    // ── Trace: exception chain keeps every level ────────────────────

    public function testTwoLevelNestingKeepsBothStagesInTheChain(): void
    {
        $inner = new Pipeline([StubProcessor::identity(), StubFailingProcessor::withMessage('deep')]);
        $outer = new Pipeline([
            StubProcessor::identity(),
            new StubProcessor(
                static fn(mixed $v): mixed => $inner->process($v),
            ),
        ]);

        try {
            $outer->process('x');
            $this->fail('Expected PipelineExecutionException');
        } catch (PipelineExecutionException $exception) {
            $this->assertSame(1, $exception->context['stage']);

            $previous = $exception->getPrevious();
            $this->assertInstanceOf(PipelineExecutionException::class, $previous);
            $this->assertSame(1, $previous->context['stage']);
            $this->assertSame(StubFailingProcessor::class, $previous->context['processor']);
            $this->assertStringNotContainsString('deep', $exception->getMessage());
        }
    }

    public function testReportedDurationsFitInsideTheMeasuredRun(): void
    {
        $observer = new \KaririCode\ProcessorPipeline\Tests\Stubs\RecordingObserver();
        $pipeline = new \KaririCode\ProcessorPipeline\Pipeline\Pipeline(
            [StubProcessor::identity(), StubFailingProcessor::withMessage('x')],
            $observer,
        );

        $started = hrtime(true);
        try {
            $pipeline->process('in');
        } catch (\Throwable) {
        }
        $elapsed = hrtime(true) - $started;

        $this->assertCount(2, $observer->events);
        foreach ($observer->events as $event) {
            $this->assertGreaterThanOrEqual(0, $event['durationNs']);
            $this->assertLessThanOrEqual($elapsed, $event['durationNs']);
        }
    }
}

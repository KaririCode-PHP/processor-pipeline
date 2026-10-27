<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Handler;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubFailingProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use PHPUnit\Framework\TestCase;

final class ProcessorHandlerTest extends TestCase
{
    private ProcessingResultCollection $results;

    protected function setUp(): void
    {
        $this->results = new ProcessingResultCollection();
    }

    // ── Successful Processing ───────────────────────────────────────

    public function testProcessDelegatesToWrappedProcessor(): void
    {
        $handler = new ProcessorHandler(
            processor: StubProcessor::uppercase(),
            resultCollection: $this->results,
        );

        $result = $handler->process('hello');

        $this->assertSame('HELLO', $result);
    }

    public function testProcessRecordsExecutionTrace(): void
    {
        $processor = StubProcessor::identity();
        $handler = new ProcessorHandler(
            processor: $processor,
            resultCollection: $this->results,
        );

        $handler->process('input');

        $trace = $this->results->getExecutionTrace();
        $this->assertCount(1, $trace);
        $this->assertSame($processor::class, $trace[0]);
    }

    public function testProcessDoesNotRecordErrorOnSuccess(): void
    {
        $handler = new ProcessorHandler(
            processor: StubProcessor::identity(),
            resultCollection: $this->results,
        );

        $handler->process('input');

        $this->assertFalse($this->results->hasErrors());
    }

    // ── Error Handling (haltOnError = false) ────────────────────────

    public function testProcessCatchesErrorAndReturnsInput(): void
    {
        $handler = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('boom'),
            resultCollection: $this->results,
            haltOnError: false,
        );

        $result = $handler->process('original');

        $this->assertSame('original', $result);
    }

    public function testProcessRecordsErrorInCollection(): void
    {
        $handler = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('validation failed'),
            resultCollection: $this->results,
            haltOnError: false,
        );

        $handler->process('input');

        $this->assertTrue($this->results->hasErrors());

        $errors = $this->results->getErrors();
        $this->assertArrayHasKey(StubFailingProcessor::class, $errors);
        $this->assertSame('processingFailed', $errors[StubFailingProcessor::class][0]['errorKey']);
        $this->assertSame('validation failed', $errors[StubFailingProcessor::class][0]['message']);
    }

    public function testProcessRecordsTraceEvenOnError(): void
    {
        $handler = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('fail'),
            resultCollection: $this->results,
            haltOnError: false,
        );

        $handler->process('input');

        $this->assertCount(1, $this->results->getExecutionTrace());
    }

    // ── Error Handling (haltOnError = true) ──────────────────────────

    public function testProcessReThrowsWhenHaltOnError(): void
    {
        $handler = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('halt!'),
            resultCollection: $this->results,
            haltOnError: true,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('halt!');

        $handler->process('input');
    }

    public function testProcessRecordsErrorBeforeReThrow(): void
    {
        $handler = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('halt!'),
            resultCollection: $this->results,
            haltOnError: true,
        );

        try {
            $handler->process('input');
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertTrue($this->results->hasErrors());
    }

    // ── Multiple Handlers Sharing Collection ────────────────────────

    public function testMultipleHandlersShareResultCollection(): void
    {
        $h1 = new ProcessorHandler(
            processor: StubProcessor::trim(),
            resultCollection: $this->results,
        );

        $h2 = new ProcessorHandler(
            processor: StubFailingProcessor::withMessage('oops'),
            resultCollection: $this->results,
            haltOnError: false,
        );

        $h1->process('  data  ');
        $h2->process('input');

        $this->assertCount(2, $this->results->getExecutionTrace());
        $this->assertTrue($this->results->hasErrors());
    }

    // ── Processor Interface Compliance ──────────────────────────────

    public function testHandlerExposesTheWrappedProcessor(): void
    {
        $inner = StubProcessor::identity();
        $handler = new ProcessorHandler(processor: $inner, resultCollection: $this->results);

        $this->assertInstanceOf(ProcessorDecorator::class, $handler);
        $this->assertSame($inner, $handler->innerProcessor());
    }

    public function testHandlerImplementsProcessorInterface(): void
    {
        $handler = new ProcessorHandler(
            processor: StubProcessor::identity(),
            resultCollection: $this->results,
        );

        $this->assertInstanceOf(\KaririCode\Contract\Processor\Processor::class, $handler);
    }

    public function testIdentityLooksThroughNestedDecorators(): void
    {
        // Pipeline names stages after the innermost processor; the handler must agree.
        $failing = new class implements Processor {
            public function process(mixed $input): mixed
            {
                throw new \RuntimeException('boom');
            }
        };
        $decorator = new class ($failing) implements ProcessorDecorator {
            public function __construct(private readonly Processor $inner) {}

            public function innerProcessor(): Processor
            {
                return $this->inner;
            }

            public function process(mixed $input): mixed
            {
                return $this->inner->process($input);
            }
        };
        $results = new ProcessingResultCollection();

        new ProcessorHandler($decorator, $results, haltOnError: false)->process('x');

        $this->assertSame([$failing::class], $results->executionTrace);
        $this->assertSame([$failing::class], array_keys($results->errors));
    }

    #[\PHPUnit\Framework\Attributes\Group('fail-closed')]
    public function testFailsClosedByDefault(): void
    {
        // Spec V4.3.3 §8.3 / §18: a failure must not let later stages run on
        // unvalidated data unless the caller opts into collect-all mode.
        $results = new ProcessingResultCollection();
        $handler = new ProcessorHandler(StubFailingProcessor::withMessage('rejected'), $results);

        $rethrown = false;
        try {
            $handler->process('x');
        } catch (\RuntimeException) {
            $rethrown = true;
        }

        $this->assertTrue($rethrown, 'A default handler must rethrow.');
        $this->assertSame(1, $results->errorCount);
    }
}

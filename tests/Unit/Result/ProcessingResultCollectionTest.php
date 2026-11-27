<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Result;

use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
use PHPUnit\Framework\TestCase;

final class ProcessingResultCollectionTest extends TestCase
{
    private ProcessingResultCollection $collection;

    protected function setUp(): void
    {
        $this->collection = new ProcessingResultCollection();
    }

    // ── Initial State ───────────────────────────────────────────────

    public function testNewCollectionHasNoErrors(): void
    {
        $this->assertFalse($this->collection->hasErrors());
        $this->assertSame([], $this->collection->getErrors());
    }

    public function testNewCollectionHasNoWarnings(): void
    {
        $this->assertFalse($this->collection->hasWarnings());
        $this->assertSame([], $this->collection->getWarnings());
    }

    public function testNewCollectionHasEmptyTrace(): void
    {
        $this->assertSame([], $this->collection->getExecutionTrace());
    }

    // ── Error Recording ─────────────────────────────────────────────

    public function testAddErrorRecordsEntry(): void
    {
        $this->collection->addError('App\\Validator\\Email', 'invalidFormat', 'Not a valid email.');

        $this->assertTrue($this->collection->hasErrors());

        $errors = $this->collection->getErrors();
        $this->assertArrayHasKey('App\\Validator\\Email', $errors);
        $this->assertCount(1, $errors['App\\Validator\\Email']);
        $this->assertSame('invalidFormat', $errors['App\\Validator\\Email'][0]['errorKey']);
        $this->assertSame('Not a valid email.', $errors['App\\Validator\\Email'][0]['message']);
    }

    public function testAddErrorKeepsTheFullyQualifiedName(): void
    {
        $this->collection->addError(
            'KaririCode\\Validator\\Processor\\Logic\\RequiredValidator',
            'required',
            'Field is required.',
        );

        $errors = $this->collection->getErrors();
        $this->assertArrayHasKey('KaririCode\\Validator\\Processor\\Logic\\RequiredValidator', $errors);
        $this->assertArrayNotHasKey('RequiredValidator', $errors);
    }

    public function testAddErrorAccumulatesMultipleErrorsPerProcessor(): void
    {
        $this->collection->addError('App\\Proc', 'err1', 'First');
        $this->collection->addError('App\\Proc', 'err2', 'Second');

        $errors = $this->collection->getErrors();
        $this->assertCount(2, $errors['App\\Proc']);
    }

    public function testAddErrorAccumulatesAcrossProcessors(): void
    {
        $this->collection->addError('App\\Alpha', 'e1', 'msg1');
        $this->collection->addError('App\\Beta', 'e2', 'msg2');

        $errors = $this->collection->getErrors();
        $this->assertCount(1, $errors['App\\Alpha']);
        $this->assertCount(1, $errors['App\\Beta']);
    }

    // ── Warning Recording ───────────────────────────────────────────

    public function testAddWarningRecordsEntry(): void
    {
        $this->collection->addWarning('App\\Proc', 'deprecatedField', 'Field is deprecated.');

        $this->assertTrue($this->collection->hasWarnings());

        $warnings = $this->collection->getWarnings();
        $this->assertArrayHasKey('App\\Proc', $warnings);
        $this->assertSame('deprecatedField', $warnings['App\\Proc'][0]['errorKey']);
    }

    public function testWarningsDoNotAffectErrors(): void
    {
        $this->collection->addWarning('App\\Proc', 'warn', 'A warning.');

        $this->assertFalse($this->collection->hasErrors());
        $this->assertTrue($this->collection->hasWarnings());
    }

    // ── Execution Trace ─────────────────────────────────────────────

    public function testRecordExecutionAddsToTrace(): void
    {
        $this->collection->recordExecution('App\\ProcessorA');
        $this->collection->recordExecution('App\\ProcessorB');

        $trace = $this->collection->getExecutionTrace();

        $this->assertSame(['App\\ProcessorA', 'App\\ProcessorB'], $trace);
    }

    public function testRecordExecutionPreservesOrder(): void
    {
        $this->collection->recordExecution('First');
        $this->collection->recordExecution('Second');
        $this->collection->recordExecution('Third');

        $this->assertSame(['First', 'Second', 'Third'], $this->collection->getExecutionTrace());
    }

    // ── Property Hooks (PHP 8.4) ────────────────────────────────────

    public function testErrorsHookReturnsErrors(): void
    {
        $this->collection->addError('App\\X', 'key', 'msg');

        $this->assertSame($this->collection->getErrors(), $this->collection->errors);
    }

    public function testWarningsHookReturnsWarnings(): void
    {
        $this->collection->addWarning('App\\X', 'key', 'msg');

        $this->assertSame($this->collection->getWarnings(), $this->collection->warnings);
    }

    public function testExecutionTraceHookReturnsTrace(): void
    {
        $this->collection->recordExecution('App\\X');

        $this->assertSame($this->collection->getExecutionTrace(), $this->collection->executionTrace);
    }

    public function testHasErrorsHookMatchesMethod(): void
    {
        $this->assertSame($this->collection->hasErrors(), $this->collection->hasErrors);

        $this->collection->addError('App\\X', 'e', 'm');

        $this->assertSame($this->collection->hasErrors(), $this->collection->hasErrors);
        $this->assertTrue($this->collection->hasErrors);
    }

    public function testHasWarningsHookMatchesMethod(): void
    {
        $this->assertFalse($this->collection->hasWarnings);

        $this->collection->addWarning('App\\X', 'w', 'm');

        $this->assertTrue($this->collection->hasWarnings);
    }

    public function testErrorCountHookReturnsTotalEntries(): void
    {
        $this->assertSame(0, $this->collection->errorCount);

        $this->collection->addError('App\\A', 'e1', 'm1');
        $this->collection->addError('App\\A', 'e2', 'm2');
        $this->collection->addError('App\\B', 'e3', 'm3');

        $this->assertSame(3, $this->collection->errorCount);
    }

    // ── Merge ───────────────────────────────────────────────────────

    public function testMergeCombinesErrors(): void
    {
        $other = new ProcessingResultCollection();

        $this->collection->addError('App\\A', 'e1', 'msg1');
        $other->addError('App\\B', 'e2', 'msg2');

        $this->collection->merge($other);

        $errors = $this->collection->getErrors();
        $this->assertArrayHasKey('App\\A', $errors);
        $this->assertArrayHasKey('App\\B', $errors);
    }

    public function testMergeCombinesWarnings(): void
    {
        $other = new ProcessingResultCollection();

        $this->collection->addWarning('App\\A', 'w1', 'msg1');
        $other->addWarning('App\\B', 'w2', 'msg2');

        $this->collection->merge($other);

        $warnings = $this->collection->getWarnings();
        $this->assertArrayHasKey('App\\A', $warnings);
        $this->assertArrayHasKey('App\\B', $warnings);
    }

    public function testMergeCombinesTraces(): void
    {
        $other = new ProcessingResultCollection();

        $this->collection->recordExecution('App\\First');
        $other->recordExecution('App\\Second');

        $this->collection->merge($other);

        $this->assertSame(['App\\First', 'App\\Second'], $this->collection->getExecutionTrace());
    }

    public function testMergeAccumulatesSameProcessorErrors(): void
    {
        $other = new ProcessingResultCollection();

        $this->collection->addError('App\\Proc', 'e1', 'msg1');
        $other->addError('App\\Proc', 'e2', 'msg2');

        $this->collection->merge($other);

        $this->assertCount(2, $this->collection->getErrors()['App\\Proc']);
    }

    public function testMergeDoesNotModifySource(): void
    {
        $other = new ProcessingResultCollection();
        $other->addError('App\\X', 'e', 'm');

        $this->collection->merge($other);

        // Source unchanged
        $this->assertCount(1, $other->getErrors()['App\\X']);
    }

    // ── Reset ───────────────────────────────────────────────────────

    public function testResetClearsEverything(): void
    {
        $this->collection->addError('App\\A', 'e', 'm');
        $this->collection->addWarning('App\\B', 'w', 'm');
        $this->collection->recordExecution('App\\C');

        $this->collection->reset();

        $this->assertFalse($this->collection->hasErrors());
        $this->assertFalse($this->collection->hasWarnings());
        $this->assertSame([], $this->collection->getExecutionTrace());
        $this->assertSame(0, $this->collection->errorCount);
    }

    // ── Regression: identity must be unambiguous (ARFA P5) ─────────

    public function testSameShortNameInDifferentNamespacesStaysSeparate(): void
    {
        $this->collection->addError('App\\Validator\\Email', 'invalid', 'from app');
        $this->collection->addError('Vendor\\Rules\\Email', 'invalid', 'from vendor');

        $this->assertCount(2, $this->collection->errors);
        $this->assertSame('from app', $this->collection->errors['App\\Validator\\Email'][0]['message']);
        $this->assertSame('from vendor', $this->collection->errors['Vendor\\Rules\\Email'][0]['message']);
    }

    public function testErrorKeyAndTraceUseTheSameIdentity(): void
    {
        $this->collection->recordExecution('App\\Validator\\Email');
        $this->collection->addError('App\\Validator\\Email', 'invalid', 'msg');

        $this->assertSame(
            $this->collection->executionTrace[0],
            array_key_first($this->collection->errors),
        );
    }

    // ── Edge Cases ──────────────────────────────────────────────────

    public function testSimpleClassNameWithoutNamespace(): void
    {
        $this->collection->addError('SimpleProcessor', 'err', 'msg');

        $errors = $this->collection->getErrors();
        $this->assertArrayHasKey('SimpleProcessor', $errors);
    }

    public function testEmptyStringProcessorClass(): void
    {
        $this->collection->addError('', 'err', 'msg');

        $errors = $this->collection->getErrors();
        $this->assertArrayHasKey('', $errors);
    }
}

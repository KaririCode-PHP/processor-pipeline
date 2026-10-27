<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit;

use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
use KaririCode\ProcessorPipeline\ProcessorRegistry;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use PHPUnit\Framework\TestCase;

final class ProcessorRegistryTest extends TestCase
{
    private ProcessorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ProcessorRegistry();
    }

    // ── Registration ────────────────────────────────────────────────

    public function testRegisterStoresProcessorInContext(): void
    {
        $processor = StubProcessor::trim();
        $this->registry->register('sanitizer', 'trim', $processor);

        $this->assertTrue($this->registry->has('sanitizer', 'trim'));
        $this->assertSame($processor, $this->registry->get('sanitizer', 'trim'));
    }

    public function testRegisterReturnsSelfForFluentApi(): void
    {
        $result = $this->registry->register('ctx', 'name', StubProcessor::identity());

        $this->assertSame($this->registry, $result);
    }

    public function testFluentChainRegistersMultipleProcessors(): void
    {
        $this->registry
            ->register('validator', 'required', StubProcessor::identity())
            ->register('validator', 'email', StubProcessor::identity())
            ->register('sanitizer', 'trim', StubProcessor::trim());

        $this->assertSame(3, $this->registry->count());
    }

    public function testRegisterOverwritesExistingProcessor(): void
    {
        $first = StubProcessor::trim();
        $second = StubProcessor::uppercase();

        $this->registry->register('ctx', 'proc', $first);
        $this->registry->register('ctx', 'proc', $second);

        $this->assertSame($second, $this->registry->get('ctx', 'proc'));
        $this->assertSame(1, $this->registry->count());
    }

    public function testSameNameInDifferentContextsDoesNotCollide(): void
    {
        $validatorTrim = StubProcessor::identity();
        $sanitizerTrim = StubProcessor::trim();

        $this->registry->register('validator', 'trim', $validatorTrim);
        $this->registry->register('sanitizer', 'trim', $sanitizerTrim);

        $this->assertSame($validatorTrim, $this->registry->get('validator', 'trim'));
        $this->assertSame($sanitizerTrim, $this->registry->get('sanitizer', 'trim'));
        $this->assertNotSame(
            $this->registry->get('validator', 'trim'),
            $this->registry->get('sanitizer', 'trim'),
        );
    }

    public function testNamedArgumentsMatchV1AndTheContract(): void
    {
        $processor = StubProcessor::trim();

        // V1.x and kariricode/contract name these parameters $context and $name;
        // since PHP 8.0 parameter names are part of the public API.
        $this->registry->register(context: 'sanitizer', name: 'trim', processor: $processor);

        $this->assertSame($processor, $this->registry->get(context: 'sanitizer', name: 'trim'));
        $this->assertSame($processor, $this->registry->find(context: 'sanitizer', name: 'trim'));
        $this->assertTrue($this->registry->has(context: 'sanitizer', name: 'trim'));
        $this->assertTrue($this->registry->remove(context: 'sanitizer', name: 'trim'));
    }

    public function testNumericNamesStayStringsInIntrospection(): void
    {
        // PHP casts numeric-string array keys to int; the registry must not leak that.
        $this->registry->register('123', '0', StubProcessor::identity());

        $this->assertSame(['123'], $this->registry->getContextNames());
        $this->assertSame(['123'], $this->registry->contextNames);
        $this->assertSame(['0'], $this->registry->getProcessorNames('123'));

        foreach ($this->registry->getContextNames() as $context) {
            foreach ($this->registry->getProcessorNames($context) as $name) {
                $this->assertTrue($this->registry->has($context, $name));
            }
        }
    }

    // ── Retrieval ───────────────────────────────────────────────────

    public function testFindReturnsProcessorWhenPresent(): void
    {
        $processor = StubProcessor::trim();
        $this->registry->register('ctx', 'trim', $processor);

        $this->assertSame($processor, $this->registry->find('ctx', 'trim'));
    }

    public function testFindReturnsNullWhenProcessorMissing(): void
    {
        $this->registry->register('ctx', 'other', StubProcessor::identity());

        $this->assertNull($this->registry->find('ctx', 'missing'));
    }

    public function testFindReturnsNullWhenContextMissing(): void
    {
        $this->assertNull($this->registry->find('nonexistent', 'proc'));
    }

    public function testGetThrowsWhenProcessorNotFound(): void
    {
        $this->expectException(ProcessorNotFoundException::class);
        $this->expectExceptionCode(ProcessorNotFoundException::NOT_REGISTERED);

        $this->registry->get('validator', 'missing');
    }

    public function testGetThrowsWhenContextNotFound(): void
    {
        $this->expectException(ProcessorNotFoundException::class);

        $this->registry->get('nonexistent', 'anything');
    }

    public function testHasReturnsFalseForMissingProcessor(): void
    {
        $this->assertFalse($this->registry->has('ctx', 'nope'));
    }

    public function testHasReturnsFalseForMissingContext(): void
    {
        $this->assertFalse($this->registry->has('nonexistent', 'proc'));
    }

    public function testGetByContextReturnsAllProcessors(): void
    {
        $p1 = StubProcessor::trim();
        $p2 = StubProcessor::uppercase();

        $this->registry->register('sanitizer', 'trim', $p1);
        $this->registry->register('sanitizer', 'upper', $p2);

        $result = $this->registry->getByContext('sanitizer');

        $this->assertCount(2, $result);
        $this->assertSame($p1, $result['trim']);
        $this->assertSame($p2, $result['upper']);
    }

    public function testGetByContextReturnsEmptyForUnknownContext(): void
    {
        $this->assertSame([], $this->registry->getByContext('unknown'));
    }

    // ── Introspection ───────────────────────────────────────────────

    public function testGetContextNamesReturnsRegisteredContexts(): void
    {
        $this->registry->register('validator', 'required', StubProcessor::identity());
        $this->registry->register('sanitizer', 'trim', StubProcessor::trim());

        $names = $this->registry->getContextNames();

        $this->assertContains('validator', $names);
        $this->assertContains('sanitizer', $names);
        $this->assertCount(2, $names);
    }

    public function testGetContextNamesReturnsEmptyWhenEmpty(): void
    {
        $this->assertSame([], $this->registry->getContextNames());
    }

    public function testGetProcessorNamesReturnsNamesInContext(): void
    {
        $this->registry->register('ctx', 'alpha', StubProcessor::identity());
        $this->registry->register('ctx', 'beta', StubProcessor::identity());

        $names = $this->registry->getProcessorNames('ctx');

        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    public function testGetProcessorNamesReturnsEmptyForUnknownContext(): void
    {
        $this->assertSame([], $this->registry->getProcessorNames('unknown'));
    }

    public function testCountReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->registry->count());
    }

    public function testCountReturnsTotalAcrossContexts(): void
    {
        $this->registry->register('a', 'p1', StubProcessor::identity());
        $this->registry->register('a', 'p2', StubProcessor::identity());
        $this->registry->register('b', 'p1', StubProcessor::identity());

        $this->assertSame(3, $this->registry->count());
    }

    public function testRegistryIsCountableViaCountFunction(): void
    {
        $this->registry->register('a', 'p1', StubProcessor::identity());
        $this->registry->register('b', 'p2', StubProcessor::identity());

        $this->assertInstanceOf(\Countable::class, $this->registry);
        $this->assertCount(2, $this->registry);
    }

    // ── Property Hooks (PHP 8.4) ────────────────────────────────────

    public function testContextNamesHookReturnsRegisteredContexts(): void
    {
        $this->registry->register('validator', 'req', StubProcessor::identity());
        $this->registry->register('sanitizer', 'trim', StubProcessor::trim());

        $this->assertContains('validator', $this->registry->contextNames);
        $this->assertContains('sanitizer', $this->registry->contextNames);
    }

    public function testTotalCountHookMatchesMethodCount(): void
    {
        $this->registry->register('a', 'x', StubProcessor::identity());
        $this->registry->register('b', 'y', StubProcessor::identity());

        $this->assertSame($this->registry->count(), $this->registry->totalCount);
        $this->assertSame(2, $this->registry->totalCount);
    }

    public function testIsEmptyHookReturnsTrueWhenEmpty(): void
    {
        $this->assertTrue($this->registry->isEmpty);
    }

    public function testIsEmptyHookReturnsFalseWhenPopulated(): void
    {
        $this->registry->register('ctx', 'proc', StubProcessor::identity());

        $this->assertFalse($this->registry->isEmpty);
    }

    // ── Mutation ────────────────────────────────────────────────────

    public function testRemoveDeletesExistingProcessor(): void
    {
        $this->registry->register('ctx', 'proc', StubProcessor::identity());

        $this->assertTrue($this->registry->remove('ctx', 'proc'));
        $this->assertFalse($this->registry->has('ctx', 'proc'));
    }

    public function testRemoveReturnsFalseForMissingProcessor(): void
    {
        $this->assertFalse($this->registry->remove('ctx', 'missing'));
    }

    public function testRemoveCleansUpEmptyContext(): void
    {
        $this->registry->register('ctx', 'only', StubProcessor::identity());
        $this->registry->remove('ctx', 'only');

        $this->assertSame([], $this->registry->getContextNames());
    }

    public function testRemovePreservesOtherProcessorsInContext(): void
    {
        $this->registry->register('ctx', 'keep', StubProcessor::identity());
        $this->registry->register('ctx', 'drop', StubProcessor::identity());

        $this->registry->remove('ctx', 'drop');

        $this->assertTrue($this->registry->has('ctx', 'keep'));
        $this->assertFalse($this->registry->has('ctx', 'drop'));
    }

    public function testClearRemovesEverything(): void
    {
        $this->registry->register('a', 'p1', StubProcessor::identity());
        $this->registry->register('b', 'p2', StubProcessor::identity());

        $this->registry->clear();

        $this->assertSame(0, $this->registry->count());
        $this->assertTrue($this->registry->isEmpty);
        $this->assertSame([], $this->registry->getContextNames());
    }
}

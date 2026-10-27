<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit;

use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
use KaririCode\ProcessorPipeline\ProcessorBuilder;
use KaririCode\ProcessorPipeline\ProcessorRegistry;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubConfigurableProcessor;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessorBuilderTest extends TestCase
{
    private ProcessorRegistry $registry;
    private ProcessorBuilder $builder;

    protected function setUp(): void
    {
        $this->registry = new ProcessorRegistry();
        $this->builder = new ProcessorBuilder($this->registry);
    }

    // ── Simple Specification (name list) ────────────────────────────

    public function testBuildPipelineFromSimpleNameList(): void
    {
        $this->registry->register('sanitizer', 'trim', StubProcessor::trim());
        $this->registry->register('sanitizer', 'upper', StubProcessor::uppercase());

        $pipeline = $this->builder->buildPipeline('sanitizer', ['trim', 'upper']);

        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertSame(2, $pipeline->count());
    }

    public function testBuildPipelineExecutesCorrectly(): void
    {
        $this->registry->register('sanitizer', 'trim', StubProcessor::trim());
        $this->registry->register('sanitizer', 'upper', StubProcessor::uppercase());

        $pipeline = $this->builder->buildPipeline('sanitizer', ['trim', 'upper']);
        $result = $pipeline->process('  hello  ');

        $this->assertSame('HELLO', $result);
    }

    public function testBuildPipelinePreservesSpecOrder(): void
    {
        $this->registry->register('ctx', 'upper', StubProcessor::uppercase());
        $this->registry->register('ctx', 'append', StubProcessor::append('!'));

        $pipeline = $this->builder->buildPipeline('ctx', ['upper', 'append']);

        $this->assertSame('HELLO!', $pipeline->process('hello'));
    }

    // ── Enable/Disable Specification ────────────────────────────────

    public function testBuildPipelineSkipsDisabledProcessors(): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());
        $this->registry->register('ctx', 'upper', StubProcessor::uppercase());

        $pipeline = $this->builder->buildPipeline('ctx', [
            'trim' => true,
            'upper' => false,
        ]);

        $this->assertSame(1, $pipeline->count());
        $this->assertSame('hello', $pipeline->process('  hello  '));
    }

    public function testBuildPipelineWithAllDisabled(): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());

        $pipeline = $this->builder->buildPipeline('ctx', [
            'trim' => false,
        ]);

        $this->assertTrue($pipeline->isEmpty());
    }

    // ── Configuration Specification ─────────────────────────────────

    public function testBuildPipelineConfiguresConfigurableProcessor(): void
    {
        $configurable = new StubConfigurableProcessor();
        $this->registry->register('validator', 'length', $configurable);

        $pipeline = $this->builder->buildPipeline('validator', [
            'length' => ['minLength' => 3, 'maxLength' => 50],
        ]);
        $configured = $pipeline->getProcessors()[0];

        // The configured copy carries the options; the registered prototype does not.
        $this->assertInstanceOf(StubConfigurableProcessor::class, $configured);
        $this->assertSame(['minLength' => 3, 'maxLength' => 50], $configured->lastConfig);
        $this->assertSame([], $configurable->lastConfig);
    }

    #[\PHPUnit\Framework\Attributes\Group('fail-closed')]
    public function testBuildPipelineThrowsWhenConfiguringNonConfigurableProcessor(): void
    {
        $this->registry->register('ctx', 'simple', StubProcessor::identity());

        $this->expectException(InvalidProcessorConfigurationException::class);
        $this->expectExceptionCode(InvalidProcessorConfigurationException::NOT_CONFIGURABLE);

        $this->builder->buildPipeline('ctx', [
            'simple' => ['key' => 'value'],
        ]);
    }

    // ── Mixed Specification ─────────────────────────────────────────

    public function testBuildPipelineMixedSpecificationFormat(): void
    {
        $configurable = new StubConfigurableProcessor();

        $this->registry->register('ctx', 'trim', StubProcessor::trim());
        $this->registry->register('ctx', 'upper', StubProcessor::uppercase());
        $this->registry->register('ctx', 'length', $configurable);
        $this->registry->register('ctx', 'skip', StubProcessor::identity());

        $pipeline = $this->builder->buildPipeline('ctx', [
            'trim',                                // sequential name
            'upper' => true,                       // enabled flag
            'length' => ['minLength' => 1],        // configured
            'skip' => false,                       // disabled
        ]);

        $this->assertSame(3, $pipeline->count());

        $configured = $pipeline->getProcessors()[2];
        $this->assertInstanceOf(StubConfigurableProcessor::class, $configured);
        $this->assertSame(['minLength' => 1], $configured->lastConfig);
        $this->assertSame([], $configurable->lastConfig);
    }

    // ── Empty Specification ─────────────────────────────────────────

    public function testBuildPipelineFromEmptySpec(): void
    {
        $pipeline = $this->builder->buildPipeline('ctx', []);

        $this->assertTrue($pipeline->isEmpty());
    }

    // ── Unknown Format Handling ─────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Group('fail-closed')]
    public function testUnknownFormatFailsTheBuildInsteadOfDroppingTheEntry(): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());
        $this->registry->register('ctx', 'upper', StubProcessor::uppercase());

        // 'unknown' => 42 matches no recognized format. Dropping it silently
        // would hide typos such as 'required' => 'yes' (fail-open).
        $this->expectException(InvalidProcessorConfigurationException::class);
        $this->expectExceptionCode(InvalidProcessorConfigurationException::UNSUPPORTED_ENTRY);

        $this->builder->buildPipeline('ctx', [
            'trim',
            'upper' => true,
            'unknown' => 42,
        ]);
    }

    // ── Error Cases ─────────────────────────────────────────────────

    public function testBuildPipelineThrowsForUnregisteredProcessor(): void
    {
        $this->expectException(ProcessorNotFoundException::class);
        $this->expectExceptionCode(ProcessorNotFoundException::NOT_REGISTERED);

        $this->builder->buildPipeline('ctx', ['missing']);
    }

    public function testBuildPipelineThrowsForUnregisteredContext(): void
    {
        $this->expectException(ProcessorNotFoundException::class);

        $this->builder->buildPipeline('nonexistent', ['anything']);
    }

    // ── Immutability of Built Pipeline ──────────────────────────────

    public function testBuildPipelineReturnsImmutablePipeline(): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());

        $pipeline = $this->builder->buildPipeline('ctx', ['trim']);
        $extended = $pipeline->withProcessor(StubProcessor::uppercase());

        $this->assertSame(1, $pipeline->count());
        $this->assertSame(2, $extended->count());
    }

    // ── Regression: shared state across builds (ARFA P1) ───────────

    public function testConfiguredBuildsDoNotShareProcessorState(): void
    {
        $this->registry->register('validator', 'length', new StubConfigurableProcessor());

        $lenient = $this->builder->buildPipeline('validator', ['length' => ['minLength' => 1]]);
        $strict = $this->builder->buildPipeline('validator', ['length' => ['minLength' => 10]]);

        // Building $strict must not reconfigure the processor inside $lenient.
        $this->assertSame('hello', $lenient->process('hello'));

        $this->expectException(PipelineExecutionException::class);
        $strict->process('hello');
    }

    public function testBuildDoesNotMutateRegisteredPrototype(): void
    {
        $prototype = new StubConfigurableProcessor();
        $this->registry->register('validator', 'length', $prototype);

        $pipeline = $this->builder->buildPipeline('validator', ['length' => ['minLength' => 3]]);
        $configured = $pipeline->getProcessors()[0];

        $this->assertSame([], $prototype->lastConfig);
        $this->assertInstanceOf(StubConfigurableProcessor::class, $configured);
        $this->assertNotSame($prototype, $configured);
        $this->assertSame(['minLength' => 3], $configured->lastConfig);
    }

    // ── Regression: malformed specs must fail closed ────────────────

    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function malformedSpecProvider(): iterable
    {
        yield 'string flag instead of bool' => [['trim' => 'yes']];
        yield 'null flag' => [['trim' => null]];
        yield 'integer flag' => [['trim' => 1]];
        yield 'options without a name' => [[['minLength' => 3]]];
        yield 'object value' => [['trim' => new \stdClass()]];
    }

    /**
     * @param array<int|string, mixed> $spec
     */
    #[DataProvider('malformedSpecProvider')]
    public function testMalformedSpecEntryIsRejected(array $spec): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());

        $this->expectException(InvalidProcessorConfigurationException::class);

        $this->builder->buildPipeline('ctx', $spec);
    }

    #[\PHPUnit\Framework\Attributes\Group('fail-closed')]
    public function testConfigurationWithIntegerKeysIsRejected(): void
    {
        $this->registry->register('ctx', 'length', new StubConfigurableProcessor());

        $this->expectException(InvalidProcessorConfigurationException::class);
        $this->expectExceptionCode(InvalidProcessorConfigurationException::OPTION_KEY_NOT_A_NAME);

        $this->builder->buildPipeline('ctx', ['length' => [3, 50]]);
    }

    public function testEmptyConfigurationMeansEnabledWithDefaults(): void
    {
        $this->registry->register('ctx', 'trim', StubProcessor::trim());

        $pipeline = $this->builder->buildPipeline('ctx', ['trim' => []]);

        $this->assertSame('x', $pipeline->process('  x  '));
    }
}

<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Handler;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
use KaririCode\ProcessorPipeline\Handler\StageName;
use KaririCode\ProcessorPipeline\Tests\Stubs\StubProcessor;
use PHPUnit\Framework\TestCase;

final class StageNameTest extends TestCase
{
    public function testWalksExactlyMaxDecoratorDepthHops(): void
    {
        $inner = StubProcessor::identity();

        $this->assertSame($inner::class, StageName::of(self::wrap($inner, StageName::MAX_DECORATOR_DEPTH)));
        $this->assertSame(Wrapper::class, StageName::of(self::wrap($inner, StageName::MAX_DECORATOR_DEPTH + 1)));
    }

    private static function wrap(Processor $processor, int $times): Processor
    {
        for ($i = 0; $i < $times; ++$i) {
            $processor = new Wrapper($processor);
        }

        return $processor;
    }
}

final readonly class Wrapper implements ProcessorDecorator
{
    public function __construct(
        private Processor $inner,
    ) {}

    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this->inner;
    }

    #[\Override]
    public function process(mixed $input): mixed
    {
        return $this->inner->process($input);
    }
}

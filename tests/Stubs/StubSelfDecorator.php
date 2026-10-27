<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;

/**
 * Pathological decorator whose inner processor is itself — a cycle.
 */
final class StubSelfDecorator implements ProcessorDecorator
{
    #[\Override]
    public function innerProcessor(): Processor
    {
        return $this;
    }

    #[\Override]
    public function process(mixed $input): mixed
    {
        throw new \RuntimeException('self-decorator failed');
    }
}

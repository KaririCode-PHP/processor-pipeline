<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs;

use KaririCode\Contract\Processor\Processor;

final class StubFailingProcessor implements Processor
{
    public function __construct(
        private readonly \Throwable $exception,
    ) {}

    #[\Override]
    public function process(mixed $input): mixed
    {
        throw $this->exception;
    }

    public static function withMessage(string $message): self
    {
        return new self(new \RuntimeException($message));
    }

    public static function withException(\Throwable $exception): self
    {
        return new self($exception);
    }
}

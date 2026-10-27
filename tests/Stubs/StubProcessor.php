<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs;

use KaririCode\Contract\Processor\Processor;

final class StubProcessor implements Processor
{
    /**
     * @param \Closure(mixed): mixed $transform
     */
    public function __construct(
        private readonly \Closure $transform,
    ) {}

    #[\Override]
    public function process(mixed $input): mixed
    {
        return ($this->transform)($input);
    }

    public static function trim(): self
    {
        return new self(static fn(mixed $v): string => trim(self::text($v)));
    }

    public static function uppercase(): self
    {
        return new self(static fn(mixed $v): string => strtoupper(self::text($v)));
    }

    public static function lowercase(): self
    {
        return new self(static fn(mixed $v): string => strtolower(self::text($v)));
    }

    public static function append(string $suffix): self
    {
        return new self(static fn(mixed $v): string => self::text($v) . $suffix);
    }

    public static function identity(): self
    {
        return new self(static fn(mixed $v): mixed => $v);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(
                'StubProcessor expects string-like input, ' . get_debug_type($value) . ' given.',
            ),
        };
    }
}

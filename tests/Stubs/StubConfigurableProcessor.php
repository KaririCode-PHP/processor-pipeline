<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs;

use KaririCode\Contract\Processor\ConfigurableProcessor;

final class StubConfigurableProcessor implements ConfigurableProcessor
{
    private int $minLength = 0;
    private int $maxLength = PHP_INT_MAX;

    /** @var array<mixed> Last configuration received */
    public array $lastConfig = [];

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function configure(array $options): void
    {
        $this->lastConfig = $options;
        $this->minLength = self::intOption($options, 'minLength', $this->minLength);
        $this->maxLength = self::intOption($options, 'maxLength', $this->maxLength);
    }

    #[\Override]
    public function process(mixed $input): mixed
    {
        if (!is_string($input)) {
            throw new \InvalidArgumentException('Expected string, ' . get_debug_type($input) . ' given.');
        }

        $length = mb_strlen($input);

        if ($length < $this->minLength || $length > $this->maxLength) {
            throw new \InvalidArgumentException(
                "Length {$length} out of range [{$this->minLength}, {$this->maxLength}].",
            );
        }

        return $input;
    }

    /**
     * @param array<mixed> $options
     */
    private static function intOption(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? $default;

        return is_int($value)
            ? $value
            : throw new \InvalidArgumentException("Option '{$name}' must be an int.");
    }
}

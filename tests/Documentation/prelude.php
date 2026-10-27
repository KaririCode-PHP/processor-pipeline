<?php

declare(strict_types=1);

/*
 * Shared fixtures for the executable examples in src/ docblocks.
 *
 * Every ```php block in a docblock runs in a fresh process after this file,
 * with assertions enabled. Classes are declared in the global namespace so
 * examples can use short names; examples must not redeclare them.
 */

use KaririCode\Contract\Processor\ConfigurableProcessor;
use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Observer\PipelineObserver;
use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
use KaririCode\ProcessorPipeline\ProcessorBuilder;
use KaririCode\ProcessorPipeline\ProcessorRegistry;

final class TrimProcessor implements Processor
{
    #[\Override]
    public function process(mixed $input): mixed
    {
        return is_string($input) ? trim($input) : $input;
    }
}

final class LowercaseProcessor implements Processor
{
    #[\Override]
    public function process(mixed $input): mixed
    {
        return is_string($input) ? strtolower($input) : $input;
    }
}

final class UpperCaseProcessor implements Processor
{
    #[\Override]
    public function process(mixed $input): mixed
    {
        return is_string($input) ? strtoupper($input) : $input;
    }
}

final class RequiredValidator implements Processor
{
    #[\Override]
    public function process(mixed $input): mixed
    {
        if ($input === null || $input === '') {
            throw new \InvalidArgumentException('Value is required.');
        }

        return $input;
    }
}

final class EmailValidator implements Processor
{
    #[\Override]
    public function process(mixed $input): mixed
    {
        if (!is_string($input) || filter_var($input, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email.');
        }

        return $input;
    }
}

final class LengthValidator implements ConfigurableProcessor
{
    private int $minLength = 0;
    private int $maxLength = PHP_INT_MAX;

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function configure(array $options): void
    {
        $min = $options['minLength'] ?? $this->minLength;
        $max = $options['maxLength'] ?? $this->maxLength;

        if (!is_int($min) || !is_int($max)) {
            throw new \InvalidArgumentException('Options "minLength" and "maxLength" must be ints.');
        }

        $this->minLength = $min;
        $this->maxLength = $max;
    }

    #[\Override]
    public function process(mixed $input): mixed
    {
        $length = is_string($input) ? mb_strlen($input) : 0;

        if ($length < $this->minLength || $length > $this->maxLength) {
            throw new \InvalidArgumentException(
                "Length must be between {$this->minLength} and {$this->maxLength}.",
            );
        }

        return $input;
    }
}

final class NestedPipelineProcessor implements Processor
{
    public function __construct(
        private readonly Pipeline $pipeline,
    ) {}

    #[\Override]
    public function process(mixed $input): mixed
    {
        return $this->pipeline->process($input);
    }
}

final class StageLog implements PipelineObserver
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<int> */
    public array $durationsNs = [];

    #[\Override]
    public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void
    {
        $this->lines[] = "#{$stageIndex} {$processorClass} ok";
        $this->durationsNs[] = $durationNs;
    }

    #[\Override]
    public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, \Throwable $cause): void
    {
        $this->lines[] = "#{$stageIndex} {$processorClass} failed: {$cause->getMessage()}";
        $this->durationsNs[] = $durationNs;
    }
}

$registry = new ProcessorRegistry()
    ->register('sanitizer', 'trim', new TrimProcessor())
    ->register('sanitizer', 'lowercase', new LowercaseProcessor())
    ->register('sanitizer', 'uppercase', new UpperCaseProcessor())
    ->register('validator', 'required', new RequiredValidator())
    ->register('validator', 'email', new EmailValidator())
    ->register('validator', 'length', new LengthValidator());

$builder = new ProcessorBuilder($registry);

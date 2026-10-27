<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Exception;

use KaririCode\Contract\Exception\Configuration;

/**
 * A processor specification was rejected while building a pipeline.
 *
 * The builder fails closed (ADR-003): every shape outside its grammar is
 * reported instead of skipped. The offending key, option key or processor
 * name is kept in `$context`, never in the message.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
 *
 * try {
 *     $builder->buildPipeline('validator', ['required' => 'yes']);
 * } catch (InvalidProcessorConfigurationException $e) {
 *     assert($e->getCode() === InvalidProcessorConfigurationException::UNSUPPORTED_ENTRY);
 *     assert($e->context['valueType'] === 'string');
 * }
 * ```
 */
final class InvalidProcessorConfigurationException extends ProcessorPipelineException implements Configuration
{
    public const int UNSUPPORTED_ENTRY = 8510;
    public const int OPTION_KEY_NOT_A_NAME = 8511;
    public const int NOT_CONFIGURABLE = 8512;

    public static function forSpecificationEntry(int|string $key, mixed $value): self
    {
        return new self(
            code: self::UNSUPPORTED_ENTRY,
            text: 'Specification entry rejected: expected a name, name => bool, or name => array of options.',
            context: ['specKey' => $key, 'valueType' => get_debug_type($value)],
        );
    }

    public static function nonStringOptionKey(string $processorName, int $optionKey): self
    {
        return new self(
            code: self::OPTION_KEY_NOT_A_NAME,
            text: 'Processor options must be keyed by option name.',
            context: ['processor' => $processorName, 'optionKey' => $optionKey],
        );
    }

    public static function notConfigurable(string $processorName): self
    {
        return new self(
            code: self::NOT_CONFIGURABLE,
            text: 'Options were given to a processor that does not implement ConfigurableProcessor.',
            context: ['processor' => $processorName],
        );
    }
}

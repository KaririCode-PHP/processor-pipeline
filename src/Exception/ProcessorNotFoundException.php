<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Exception;

use KaririCode\Contract\Exception\NotFound;

/**
 * A processor was requested with `get()` under a name its context does not
 * hold. `ProcessorRegistry::find()` is the nullable alternative (Spec V4.3.3
 * §8.1); the builder uses `get()` because a specification that names an
 * unregistered processor is a configuration bug.
 *
 * ```php
 * use KaririCode\Contract\Exception\NotFound;
 *
 * try {
 *     $registry->get('validator', 'emial');
 * } catch (NotFound $e) {
 *     assert($e->context === ['processor' => 'emial', 'context' => 'validator']);
 * }
 * ```
 */
final class ProcessorNotFoundException extends ProcessorPipelineException implements NotFound
{
    public const int NOT_REGISTERED = 8501;

    public static function forNameInContext(string $processorName, string $context): self
    {
        return new self(
            code: self::NOT_REGISTERED,
            text: 'No processor is registered under the requested name in this context.',
            context: ['processor' => $processorName, 'context' => $context],
        );
    }
}

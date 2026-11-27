<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Exception;

use KaririCode\Contract\Exception\ExceptionInterface;

/**
 * Root of every exception this component throws.
 *
 * Each concrete exception implements exactly one family of
 * kariricode/contract 2.11.0 (`Exception\NotFound`, `Exception\Configuration`
 * or `Exception\Runtime`), so an edge maps it to a response without knowing
 * this component. Codes come from the block registered to the component,
 * 8500–8599, and every message starts with `[PP_<code>]`.
 *
 * Messages never contain caller-supplied values. Processor names,
 * specification keys and option keys go to `$context`, and the caller
 * decides what reaches a log (Spec V4.3.3 §20.3; OWASP Top 10:2025 A09).
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\ProcessorPipelineException;
 *
 * try {
 *     $builder->buildPipeline('validator', ['emial']);
 * } catch (ProcessorPipelineException $e) {
 *     assert($e->getCode() >= 8500 && $e->getCode() <= 8599);
 *     assert(str_starts_with($e->getMessage(), '[PP_'));
 * }
 * ```
 *
 * @see \KaririCode\Contract\Exception\ExceptionInterface
 */
abstract class ProcessorPipelineException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param array<string, mixed> $context Structured details; may hold caller-supplied identifiers
     */
    final protected function __construct(
        int $code,
        string $text,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('[PP_%d] %s', $code, $text), $code, $previous);
    }
}

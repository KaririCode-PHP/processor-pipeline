<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Handler;

use KaririCode\Contract\Processor\Processor;

/**
 * Resolves the identity every observability channel uses for a stage.
 *
 * The stage name is the class of the innermost processor, reached by
 * following {@see ProcessorDecorator::innerProcessor()}: the processor that
 * does the work. Pipeline uses it for exception context and observer calls,
 * ProcessorHandler for the execution trace and error keys, so all four
 * channels agree.
 *
 * The walk stops after MAX_DECORATOR_DEPTH hops, which also terminates a
 * decorator that returns itself.
 *
 * @internal Shared by Pipeline, ProcessorHandler and the Integration decorators; not part of the public API.
 *
 * @since     3.0.0
 */
final class StageName
{
    /** Upper bound on the decorator chain walked when naming a stage. */
    public const int MAX_DECORATOR_DEPTH = 16;

    /** @codeCoverageIgnore Static-only; never instantiated. */
    private function __construct() {}

    public static function of(Processor $processor): string
    {
        for ($depth = 0; $depth < self::MAX_DECORATOR_DEPTH && $processor instanceof ProcessorDecorator; ++$depth) {
            $processor = $processor->innerProcessor();
        }

        return $processor::class;
    }
}

<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Exception;

use KaririCode\Contract\Resilience\CallNotPermittedException;

/**
 * A rate-limited stage was not run because its limiter denied the call.
 *
 * Implements the contract's CallNotPermittedException, a `Runtime` fault:
 * a RetryPolicy and an edge read the wait from `$retryAfterSeconds` the same
 * way they read an open circuit's. The limiter key stays out of the message
 * and the context, because keys are often IP addresses or tenant ids.
 *
 * ```php
 * // illustrative — at an HTTP edge
 * use KaririCode\Contract\Resilience\CallNotPermittedException;
 *
 * if ($e->getPrevious() instanceof CallNotPermittedException) {
 *     $response = $response->withStatus(503)->withHeader('Retry-After', (string) $e->getPrevious()->retryAfterSeconds);
 * }
 * ```
 */
final class RateLimitExceededException extends ProcessorPipelineException implements CallNotPermittedException
{
    public const int LIMIT_REACHED = 8530;

    /**
     * Whole seconds until the limiter admits the call again.
     *
     * @psalm-suppress PropertyNotSetInConstructor Virtual property computed by its get hook; Psalm 6 does not model PHP 8.4 hooks.
     */
    public ?int $retryAfterSeconds {
        get => \is_int($this->context['retryAfterSeconds'] ?? null) ? $this->context['retryAfterSeconds'] : null;
    }

    public static function forStage(string $stageName, int $retryAfterSeconds): self
    {
        return new self(
            code: self::LIMIT_REACHED,
            text: \sprintf('The rate limit of stage %s was reached.', $stageName),
            context: ['processor' => $stageName, 'retryAfterSeconds' => $retryAfterSeconds],
        );
    }
}

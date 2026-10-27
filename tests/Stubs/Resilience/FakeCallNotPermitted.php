<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs\Resilience;

use KaririCode\Contract\Resilience\CallNotPermittedException;

final class FakeCallNotPermitted extends \RuntimeException implements CallNotPermittedException
{
    public function __construct(
        public readonly ?int $retryAfterSeconds,
    ) {
        parent::__construct('circuit open', 7100);
    }
}

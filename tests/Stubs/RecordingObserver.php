<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Stubs;

use KaririCode\ProcessorPipeline\Observer\PipelineObserver;

final class RecordingObserver implements PipelineObserver
{
    /** @var list<array{event: string, stage: int, processor: string, durationNs: int, cause: ?\Throwable}> */
    public array $events = [];

    #[\Override]
    public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void
    {
        $this->events[] = [
            'event' => 'succeeded',
            'stage' => $stageIndex,
            'processor' => $processorClass,
            'durationNs' => $durationNs,
            'cause' => null,
        ];
    }

    #[\Override]
    public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, \Throwable $cause): void
    {
        $this->events[] = [
            'event' => 'failed',
            'stage' => $stageIndex,
            'processor' => $processorClass,
            'durationNs' => $durationNs,
            'cause' => $cause,
        ];
    }
}

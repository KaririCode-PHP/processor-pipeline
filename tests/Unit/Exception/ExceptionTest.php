<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Exception;

use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
use KaririCode\ProcessorPipeline\Exception\ProcessorPipelineException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testAllExceptionsShareTheComponentRoot(): void
    {
        $exceptions = [
            ProcessorNotFoundException::forNameInContext('x', 'ctx'),
            InvalidProcessorConfigurationException::forSpecificationEntry('k', null),
            InvalidProcessorConfigurationException::nonStringOptionKey('p', 1),
            InvalidProcessorConfigurationException::notConfigurable('p'),
            PipelineExecutionException::atStage('P', 0, new \RuntimeException('x')),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(ProcessorPipelineException::class, $exception);
            $this->assertInstanceOf(\RuntimeException::class, $exception);
        }
    }

    public function testNotFoundKeepsNameAndContext(): void
    {
        $exception = ProcessorNotFoundException::forNameInContext('missing', 'validator');

        $this->assertSame(ProcessorNotFoundException::NOT_REGISTERED, $exception->getCode());
        $this->assertSame(['processor' => 'missing', 'context' => 'validator'], $exception->context);
    }

    public function testSpecificationEntryKeepsKeyAndType(): void
    {
        $exception = InvalidProcessorConfigurationException::forSpecificationEntry(3, 1.5);

        $this->assertSame(InvalidProcessorConfigurationException::UNSUPPORTED_ENTRY, $exception->getCode());
        $this->assertSame(['specKey' => 3, 'valueType' => 'float'], $exception->context);
    }

    public function testAtStageChainsTheCauseAndNamesTheStage(): void
    {
        $cause = new \LogicException('inner detail');
        $exception = PipelineExecutionException::atStage('App\\Proc', 3, $cause);

        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame('[PP_8520] Pipeline failed at stage 3 (processor: App\\Proc).', $exception->getMessage());
        $this->assertSame(
            ['processor' => 'App\\Proc', 'stage' => 3, 'causeClass' => \LogicException::class],
            $exception->context,
        );
    }

    public function testConstructionIsReservedToTheNamedFactories(): void
    {
        $constructor = new \ReflectionMethod(ProcessorPipelineException::class, '__construct');

        $this->assertTrue($constructor->isProtected());
        $this->assertTrue($constructor->isFinal());
    }
}

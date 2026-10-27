<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Exception;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\ContainerFailure;
use KaririCode\Contract\Exception\ExceptionInterface;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
use KaririCode\ProcessorPipeline\Exception\ProcessorPipelineException;
use KaririCode\ProcessorPipeline\Exception\RateLimitExceededException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exceptions follow kariricode/contract 2.11.0 (one family each, numeric code
 * in the registered block 8500–8599) and never echo caller-supplied values.
 */
final class ExceptionFamiliesTest extends TestCase
{
    private const array FAMILIES = [
        Validation::class, NotFound::class, Configuration::class,
        Protocol::class, ContainerFailure::class, Runtime::class,
    ];

    public function testBaseExceptionIsAbstractAndRootedInTheContract(): void
    {
        $base = new \ReflectionClass(ProcessorPipelineException::class);

        $this->assertTrue($base->isAbstract());
        $this->assertTrue($base->implementsInterface(ExceptionInterface::class));
    }

    public function testEveryExceptionImplementsExactlyOneFamily(): void
    {
        $expected = [
            ProcessorNotFoundException::class => NotFound::class,
            InvalidProcessorConfigurationException::class => Configuration::class,
            PipelineExecutionException::class => Runtime::class,
            RateLimitExceededException::class => Runtime::class,
        ];

        foreach ($expected as $exception => $family) {
            $implemented = array_values(array_intersect(self::FAMILIES, array_keys(class_implements($exception))));
            $this->assertSame([$family], $implemented, $exception);
        }
    }

    public function testCodesAreUniqueAndInsideTheRegisteredBlock(): void
    {
        $codes = array_map(static fn(\Throwable $e): int => $e->getCode(), self::samples());

        foreach ($codes as $code) {
            $this->assertGreaterThanOrEqual(8500, $code);
            $this->assertLessThanOrEqual(8599, $code);
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testMessagesCarryTheirCodePrefix(): void
    {
        foreach (self::samples() as $exception) {
            $this->assertStringStartsWith(sprintf('[PP_%d] ', $exception->getCode()), $exception->getMessage());
        }
    }

    #[Group('fail-closed')]
    public function testMessagesNeverEchoCallerSuppliedValues(): void
    {
        foreach (self::samples() as $exception) {
            foreach (['caller-secret', 'jane@example', 'yes-flag'] as $supplied) {
                $this->assertStringNotContainsString($supplied, $exception->getMessage());
            }
        }
    }

    public function testContextKeepsTheDetailsForDiagnostics(): void
    {
        [$notFound, $entry, $optionKey, $notConfigurable, $stage, $limited] = self::samples();

        $this->assertSame(['processor' => 'caller-secret', 'context' => 'validator'], $notFound->context);
        $this->assertSame(['specKey' => 'caller-secret', 'valueType' => 'string'], $entry->context);
        $this->assertSame(['processor' => 'caller-secret', 'optionKey' => 0], $optionKey->context);
        $this->assertSame(['processor' => 'caller-secret'], $notConfigurable->context);
        $this->assertSame(\RuntimeException::class, $stage->context['causeClass']);
        $this->assertSame('jane@example is not valid', $stage->getPrevious()?->getMessage());
        $this->assertInstanceOf(RateLimitExceededException::class, $limited);
        $this->assertSame(7, $limited->retryAfterSeconds);
    }

    /**
     * @return list<ProcessorPipelineException>
     */
    private static function samples(): array
    {
        return [
            ProcessorNotFoundException::forNameInContext('caller-secret', 'validator'),
            InvalidProcessorConfigurationException::forSpecificationEntry('caller-secret', 'yes-flag'),
            InvalidProcessorConfigurationException::nonStringOptionKey('caller-secret', 0),
            InvalidProcessorConfigurationException::notConfigurable('caller-secret'),
            PipelineExecutionException::atStage('App\\Rule\\Email', 2, new \RuntimeException('jane@example is not valid')),
            RateLimitExceededException::forStage('App\\Geo', 7),
        ];
    }

    public function testRateLimitContextNamesTheStageAndTheWait(): void
    {
        $this->assertSame(
            ['processor' => 'App\\Geo', 'retryAfterSeconds' => 7],
            \KaririCode\ProcessorPipeline\Exception\RateLimitExceededException::forStage('App\\Geo', 7)->context,
        );
    }
}

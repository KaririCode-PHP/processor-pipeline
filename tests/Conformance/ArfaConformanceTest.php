<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Conformance;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\ContainerFailure;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Automated rules of Spec V4.3.3 §17 (documentation), §18 (antipatterns)
 * and the contract 2.11.0 exception model, checked on every src/ file.
 */
final class ArfaConformanceTest extends TestCase
{
    private const array FAMILIES = [Validation::class, NotFound::class, Configuration::class, Protocol::class, ContainerFailure::class, Runtime::class];

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function types(): iterable
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            /** @var class-string $class */
            $class = 'KaririCode\\ProcessorPipeline\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
            yield $relative => [$file->getPathname(), $class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('types')]
    public function testFileFollowsTheSpecification(string $path, string $class): void
    {
        $source = (string) file_get_contents($path);
        $type = new \ReflectionClass($class);
        $summary = (string) $type->getDocComment();

        $this->assertStringContainsString('declare(strict_types=1);', $source, 'strict types');
        $this->assertDoesNotMatchRegularExpression('/(Interface|Manager|Helper|Util)$/', $type->getShortName(), '§18 naming');
        $this->assertDoesNotMatchRegularExpression('/@(package|author|copyright|license|version)\b/', $source, 'metadata tags');
        $this->assertNotSame('', $summary, 'C1: type-level summary');
        $this->assertDoesNotMatchRegularExpression('/\/\*\*\s*\*\s*(Represents a|Executes the|Gets the)\b/', $summary, 'C1: domain role');

        if (!str_contains($summary, '@internal')) {
            $this->assertMatchesRegularExpression('/```php/', $summary, 'C4: usage example');
        }
        if (!$type->isInterface() && !$type->isAbstract() && !$type->isEnum()) {
            $this->assertTrue($type->isFinal(), '§18: concrete classes are final');
        }
        foreach ($type->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $this->assertNotContains($method->getName(), ['toArray', 'fromArray', 'jsonSerialize'], '§18 / E-001');
            if (str_starts_with($method->getName(), '__')) {
                $this->assertContains($method->getName(), ['__construct'], '§18: no magic methods');
            }
        }

        $depth = 0;
        for ($parent = $type->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (str_starts_with($parent->getName(), 'KaririCode\\ProcessorPipeline\\')) {
                ++$depth;
            }
        }
        $this->assertLessThanOrEqual(2, $depth, '§18: inheritance depth');

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (str_starts_with(trim($line), '// ──')) {
                $this->assertSame(72, mb_strlen(trim($line)), "section marker: {$line}");
            }
        }

        if ($type->isSubclassOf(\Throwable::class) && !$type->isAbstract()) {
            $families = array_values(array_intersect(self::FAMILIES, $type->getInterfaceNames()));
            $this->assertCount(1, $families, 'contract 2.11.0: exactly one exception family');
            foreach ($type->getReflectionConstants() as $constant) {
                $value = $constant->getValue();
                $this->assertIsInt($value);
                $this->assertGreaterThanOrEqual(8500, $value, 'registered block 8500–8599');
                $this->assertLessThanOrEqual(8599, $value, 'registered block 8500–8599');
            }
        }
    }
}

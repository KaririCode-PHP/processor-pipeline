<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes the ```php examples embedded in src/ docblocks.
 *
 * Every block is linted. A block whose first line is `// illustrative` is
 * only linted; every other block runs in a fresh PHP process after
 * prelude.php with `zend.assertions=1`, so each `assert()` in the
 * documentation is a real check.
 */
final class DocblockExamplesTest extends TestCase
{
    private const string ILLUSTRATIVE_MARKER = '// illustrative';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function examples(): iterable
    {
        $sourceRoot = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($sourceRoot) + 1);
            $block = null;
            $startLine = 0;

            foreach ($lines as $number => $line) {
                $content = preg_replace('/^\s*\* ?/', '', $line) ?? $line;

                if ($block === null && trim($content) === '```php') {
                    $block = [];
                    $startLine = $number + 1;
                    continue;
                }

                if ($block !== null && trim($content) === '```') {
                    $code = implode("\n", $block);
                    $runnable = !str_starts_with(ltrim($code), self::ILLUSTRATIVE_MARKER);
                    yield "{$relative}:{$startLine}" => [$code, $runnable];
                    $block = null;
                    continue;
                }

                if ($block !== null) {
                    $block[] = $content;
                }
            }
        }
    }

    public function testExamplesAreDiscovered(): void
    {
        $this->assertGreaterThanOrEqual(40, iterator_count(self::examples()));
    }

    #[DataProvider('examples')]
    public function testExampleLintsAndRuns(string $code, bool $runnable): void
    {
        $root = \dirname(__DIR__, 2);
        $script = tempnam(sys_get_temp_dir(), 'docex');
        $this->assertIsString($script);

        file_put_contents($script, "<?php\n\ndeclare(strict_types=1);\n\n"
            . "require '{$root}/vendor/autoload.php';\n"
            . "require '{$root}/tests/Documentation/prelude.php';\n\n"
            . $code . "\n");

        try {
            [$lintStatus, $lintOutput] = self::php(['-l', $script]);
            $this->assertSame(0, $lintStatus, "Lint failed:\n{$lintOutput}\n---\n{$code}");

            if ($runnable) {
                [$status, $output] = self::php(['-d', 'zend.assertions=1', '-d', 'assert.exception=1', $script]);
                $this->assertSame(0, $status, "Example failed:\n{$output}\n---\n{$code}");
            }
        } finally {
            unlink($script);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private static function php(array $arguments): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return [1, 'proc_open failed'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (\is_string($stdout) ? $stdout : '') . (\is_string($stderr) ? $stderr : '')];
    }
}

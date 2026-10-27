# KaririCode ProcessorPipeline

[![PHP 8.4+](https://img.shields.io/badge/php-8.4%2B-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![ARFA 1.3 level 1](https://img.shields.io/badge/ARFA-1.3%20level%201-orange.svg)](docs/spec/SPEC-001-processor-pipeline.md)
[![Spec 4.3.3](https://img.shields.io/badge/KaririCode%20Spec-4.3.3-blue.svg)](docs/quality/QD-V433-COMPLIANCE.md)

Ordered, immutable processing pipelines built from a declarative
specification. Validator, sanitizer and transformer components register their
processors; this package assembles and runs them.

## Why

- **One specification format.** `['trim', 'email', 'length' => ['min' => 3]]`
  can come from code, attributes or configuration. The builder rejects every
  malformed entry instead of skipping it.
- **Immutable pipelines.** `withProcessor()` returns a new pipeline, so one
  instance is safe to share across requests on long-running runtimes.
- **Fails closed.** A failing stage stops the pipeline with a coded exception
  that never repeats the rejected value; collecting every error is an
  explicit opt-in.
- **Resilience through the contract.** Circuit breaker, rate limit and retry
  are decorators over `kariricode/contract` ports, so any implementation fits.

## Installation

```bash
composer require kariricode/processor-pipeline
```

Requires PHP 8.4 and `kariricode/contract` ^2.11. As of 2026-10-26,
`kariricode/contract` 2.11.0 is not tagged, so the package installs once that
tag exists (QD-V433 §5).

## Quick Start

```php
use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\ProcessorBuilder;
use KaririCode\ProcessorPipeline\ProcessorRegistry;

final class TrimProcessor implements Processor
{
    public function process(mixed $input): mixed
    {
        return is_string($input) ? trim($input) : $input;
    }
}

final class EmailValidator implements Processor
{
    public function process(mixed $input): mixed
    {
        if (!is_string($input) || filter_var($input, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email.');
        }

        return $input;
    }
}

$registry = new ProcessorRegistry();
$registry->register('sanitizer', 'trim', new TrimProcessor());
$registry->register('validator', 'email', new EmailValidator());

$builder = new ProcessorBuilder($registry);
$pipeline = $builder->buildPipeline('sanitizer', ['trim'])
    ->withPipeline($builder->buildPipeline('validator', ['email']));

assert($pipeline->process('  walmir@kariricode.org  ') === 'walmir@kariricode.org');
```

## Specification Format

| Entry | Meaning |
|---|---|
| `'trim'` | run the registered `trim` |
| `'trim' => true` or `'trim' => []` | the same |
| `'trim' => false` | skip it |
| `'length' => ['min' => 3]` | run a configured clone; the processor must implement `ConfigurableProcessor` |

Anything else (an integer option key, a string flag, options for a processor
that is not configurable) fails the build with
`InvalidProcessorConfigurationException`. The registered instance is never
modified.

## Handling Failures

A failing stage stops the pipeline. The exception names the stage and keeps
the cause in `getPrevious()`:

```php
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;

try {
    $pipeline->process('  not-an-email ');
} catch (PipelineExecutionException $e) {
    assert($e->getCode() === PipelineExecutionException::STAGE_FAILED);
    assert($e->context['stage'] === 1);
    assert($e->getPrevious()->getMessage() === 'Invalid email.');
    assert(!str_contains($e->getMessage(), 'not-an-email'));
}
```

To report every problem of a form at once, wrap the stages in
`ProcessorHandler` and opt into collect-all mode. A failing stage then passes
its input through, so discard or re-check the output when there are errors:

```php
use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;

$results = new ProcessingResultCollection();
$form = new Pipeline([
    new ProcessorHandler(new TrimProcessor(), $results, haltOnError: false),
    new ProcessorHandler(new EmailValidator(), $results, haltOnError: false),
]);

$form->process('not-an-email');

assert($results->hasErrors);
assert(array_keys($results->errors) === [EmailValidator::class]);
```

Without `haltOnError: false`, the handler records the failure and rethrows it.

## Error Codes

Every exception implements one family of `kariricode/contract` 2.11.0, and
its message starts with `[PP_<code>]`.

| Code | Exception | Family | Cause |
|---|---|---|---|
| 8501 | `ProcessorNotFoundException` | `NotFound` | no processor under that name in the context |
| 8510 | `InvalidProcessorConfigurationException` | `Configuration` | specification entry outside the grammar |
| 8511 | `InvalidProcessorConfigurationException` | `Configuration` | option keyed by an integer |
| 8512 | `InvalidProcessorConfigurationException` | `Configuration` | options for a processor that is not configurable |
| 8520 | `PipelineExecutionException` | `Runtime` | a stage threw; the cause is `getPrevious()` |
| 8530 | `RateLimitExceededException` | `Runtime` | the stage's rate limit was reached |

## Metrics

A `PipelineObserver` receives each stage's index, name and duration in
nanoseconds. Without an observer, the clock is never read.

```php
use KaririCode\ProcessorPipeline\Observer\PipelineObserver;

final class StageTimer implements PipelineObserver
{
    /** @var list<string> */
    public array $lines = [];

    public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void
    {
        $this->lines[] = sprintf('%d %s %.3f ms', $stageIndex, $processorClass, $durationNs / 1e6);
    }

    public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, Throwable $cause): void
    {
        $this->lines[] = sprintf('%d %s failed (%s)', $stageIndex, $processorClass, $cause::class);
    }
}

$timer = new StageTimer();
$pipeline->withObserver($timer)->process(' walmir@kariricode.org ');

assert(count($timer->lines) === 2);
```

## Resilience

Three decorators wrap a stage; each depends only on a `kariricode/contract`
port, so `kariricode/circuit-breaker`, `kariricode/rate-limiter` or any other
implementation fits. Stages keep the name of the wrapped processor.

```php
// illustrative — $breaker, $limiter and $policy implement the contract ports
use KaririCode\ProcessorPipeline\Integration\CircuitBreakerProcessor;
use KaririCode\ProcessorPipeline\Integration\RateLimitedProcessor;
use KaririCode\ProcessorPipeline\Integration\RetryingProcessor;

$stage = new RetryingProcessor(
    new CircuitBreakerProcessor(
        new RateLimitedProcessor(new GeocodeProcessor($api), $limiter, key: "geocode:{$tenantId}"),
        $breaker,
    ),
    $policy,
);
$pipeline = $pipeline->withProcessor($stage);
```

| Decorator | Port | On rejection |
|---|---|---|
| `RetryingProcessor` | `RetryPolicy` | rethrows the last error when the policy declines |
| `CircuitBreakerProcessor` | `ManualCircuitBreaker` | the breaker's `CallNotPermittedException`, with `retryAfterSeconds` |
| `RateLimitedProcessor` | `RateLimiter` | `RateLimitExceededException` (8530), with `retryAfterSeconds` |

The default retry wait blocks the process; on PHP-FPM keep attempts few and
delays capped.

## Ecosystem Position

```
kariricode/contract  (Processor, ConfigurableProcessor, resilience ports, exception families)
        ▲
        │ implements / consumes
kariricode/processor-pipeline ◀── validator, sanitizer, transformer (register processors)
        │ decorators over ports
        ▼
kariricode/circuit-breaker, kariricode/rate-limiter (implementations, optional)
```

## Architecture

| Path | Role |
|---|---|
| `src/ProcessorRegistry.php` | prototypes by context and name |
| `src/ProcessorBuilder.php` | specification → pipeline, fail closed |
| `src/Pipeline/Pipeline.php` | immutable ordered stages |
| `src/Handler/ProcessorHandler.php` | records a stage failure; collect-all is opt-in |
| `src/Handler/ProcessorDecorator.php` | exposes the wrapped processor for naming |
| `src/Handler/StageName.php` | stage identity through decorators (internal) |
| `src/Observer/PipelineObserver.php` | per-stage metrics |
| `src/Result/ProcessingResultCollection.php` | errors, warnings and trace of one execution |
| `src/Integration/*` | circuit breaker, rate limit and retry decorators |
| `src/Attribute/Process.php` | specification carried by an attribute |
| `src/Exception/*` | coded exceptions, one contract family each |

## Quality

```bash
composer check            # cs-check, phpstan, psalm, complexity, test, test-conformance, security
composer infection        # mutation testing (MSI ≥ 80)
composer sbom             # CycloneDX 1.5
```

Measured values and open items: [QD-V433-COMPLIANCE](docs/quality/QD-V433-COMPLIANCE.md).

## Documentation

- [SPEC-001](docs/spec/SPEC-001-processor-pipeline.md): types, algorithms, error model, migration from 1.x
- [ADR-001 … ADR-008](docs/adr/): design decisions
- [SECURITY.md](SECURITY.md) and [CHANGELOG.md](CHANGELOG.md)

Class docblocks hold the detailed reference; their examples run in the test
suite.

## Contributing

```bash
git clone https://github.com/KaririCode-Framework/kariricode-processor-pipeline.git
cd kariricode-processor-pipeline
composer install
composer check   # must pass before a pull request
```

Pull requests target `develop`. Report vulnerabilities privately, as
[SECURITY.md](SECURITY.md) describes.

## License

MIT. See [LICENSE](LICENSE).

---

Part of the [KaririCode Framework](https://kariricode.org) ·
[Packagist](https://packagist.org/packages/kariricode/processor-pipeline) ·
[Issues](https://github.com/KaririCode-Framework/kariricode-processor-pipeline/issues)

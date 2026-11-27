# KaririCode ProcessorPipeline

<div align="center">

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e.svg)](LICENSE)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-4F46E5)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/Tests-128%20passing-22c55e)](https://kariricode.org)
[![ARFA](https://img.shields.io/badge/ARFA-1.3-orange)](https://kariricode.org)
[![KaririCode Framework](https://img.shields.io/badge/KaririCode-Framework-orange)](https://kariricode.org)

**Immutable, composable processor pipelines for the KaririCode Framework —  
context-based registry, flexible spec format, structured error collection, PHP 8.4+.**

[Installation](#installation) · [Quick Start](#quick-start) · [Features](#features) · [Pipeline](#the-pipeline) · [Architecture](#architecture)

</div>

---

## The Problem

Building reusable data-processing chains in PHP typically means either rigid class hierarchies or ad-hoc chains of function calls that are hard to test, configure, and compose:

```php
// The old way: ad-hoc chain, hard to test or reuse
function processInput(string $input): string
{
    $input = trim($input);
    $input = strtolower($input);
    if (strlen($input) < 3) {
        throw new \InvalidArgumentException('Too short');
    }
    return $input;
}
```

No registry, no configuration per processor, no error collection, no immutability — just imperative code you copy-paste everywhere.

## The Solution

```php
use KaririCode\ProcessorPipeline\ProcessorRegistry;
use KaririCode\ProcessorPipeline\ProcessorBuilder;

// 1. Register processors once, per context
$registry = new ProcessorRegistry();
$registry
    ->register('sanitizer', 'trim',      new TrimProcessor())
    ->register('sanitizer', 'lowercase', new LowercaseProcessor())
    ->register('validator', 'length',    new LengthValidator());

// 2. Build immutable pipelines from specs
$builder   = new ProcessorBuilder($registry);
$sanitized = $builder->buildPipeline('sanitizer', ['trim', 'lowercase']);
$validated = $builder->buildPipeline('validator', [
    'length' => ['minLength' => 3, 'maxLength' => 50],
]);

// 3. Execute — pipelines are immutable and reusable
$output = $sanitized->process('  HELLO WORLD  '); // 'hello world'
$validated->process($output);
```

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.4 or higher |
| kariricode/contract | ^2.8 |
| kariricode/exception | ^1.2 |

---

## Installation

```bash
composer require kariricode/processor-pipeline
```

---

## Quick Start

Define processors, register them, build a pipeline, execute:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use KaririCode\Contract\Processor\Processor;
use KaririCode\Contract\Processor\ConfigurableProcessor;
use KaririCode\ProcessorPipeline\ProcessorRegistry;
use KaririCode\ProcessorPipeline\ProcessorBuilder;

// 1. Define processors
final class TrimProcessor implements Processor
{
    public function process(mixed $input): mixed
    {
        return is_string($input) ? trim($input) : $input;
    }
}

final class LengthValidator implements ConfigurableProcessor
{
    private int $min = 0;
    private int $max = PHP_INT_MAX;

    public function configure(array $options): void
    {
        $this->min = $options['minLength'] ?? $this->min;
        $this->max = $options['maxLength'] ?? $this->max;
    }

    public function process(mixed $input): mixed
    {
        $len = mb_strlen((string) $input);
        if ($len < $this->min || $len > $this->max) {
            throw new \LengthException("Length must be between {$this->min} and {$this->max}.");
        }
        return $input;
    }
}

// 2. Register and build
$registry = new ProcessorRegistry();
$registry
    ->register('sanitizer', 'trim',   new TrimProcessor())
    ->register('validator', 'length', new LengthValidator());

$builder  = new ProcessorBuilder($registry);
$pipeline = $builder->buildPipeline('sanitizer', ['trim']);
$validate = $builder->buildPipeline('validator', [
    'length' => ['minLength' => 3, 'maxLength' => 50],
]);

// 3. Execute
$sanitized = $pipeline->process('  Hello, World!  '); // 'Hello, World!'
$validate->process($sanitized);                        // passes — 13 chars

var_dump($sanitized); // string(13) "Hello, World!"
```

---

## Features

### Immutable Pipelines (ARFA P1)

`Pipeline` is a `readonly class`. Adding processors returns a **new** instance — the original is never modified:

```php
$base     = $builder->buildPipeline('sanitizer', ['trim']);
$extended = $base->withProcessor(new LowercaseProcessor());

// $base still has 1 processor
// $extended has 2 processors
assert($base->count() === 1);
assert($extended->count() === 2);
```

### Context-Based Registry

Processors are namespaced by context — no name collisions across domains:

```php
$registry->register('validator', 'email', new EmailValidator());
$registry->register('sanitizer', 'email', new EmailSanitizer()); // same name, different context

$validationPipeline  = $builder->buildPipeline('validator', ['email']);
$sanitizationPipeline = $builder->buildPipeline('sanitizer', ['email']);
```

### Flexible Specification Format

Build pipelines with simple lists, enable/disable flags, or per-processor configuration:

```php
$pipeline = $builder->buildPipeline('validator', [
    'required',                                  // Simple: always enabled
    'trim'   => true,                            // Explicit: enabled
    'strict' => false,                           // Explicit: disabled (skipped)
    'length' => ['minLength' => 3, 'maxLength' => 50], // Configured
]);
```

### Configurable Processors

`ConfigurableProcessor` allows per-build configuration without constructing new instances:

```php
final class SlugProcessor implements ConfigurableProcessor
{
    private string $separator = '-';

    public function configure(array $options): void
    {
        $this->separator = $options['separator'] ?? '-';
    }

    public function process(mixed $input): mixed
    {
        return strtolower(str_replace(' ', $this->separator, trim((string) $input)));
    }
}

$pipeline = $builder->buildPipeline('formatter', [
    'slug' => ['separator' => '_'],
]);
$pipeline->process('Hello World'); // 'hello_world'
```

### Error Collection via ProcessorHandler

Wrap processors for non-halting error collection — useful in validation scenarios:

```php
use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;

$results = new ProcessingResultCollection();

$handler = new ProcessorHandler(
    processor:        new EmailValidator(),
    resultCollection: $results,
    haltOnError:      false,   // continue on failure
);

$output = $handler->process('not-an-email'); // returns input unchanged

if ($results->hasErrors()) {
    foreach ($results->getErrors() as $processor => $errors) {
        foreach ($errors as $error) {
            echo "{$processor}: {$error['message']}\n";
        }
    }
}
```

### PHP 8.4 Attributes

Use `#[Process]` to declare pipelines declaratively on entity properties:

```php
use KaririCode\ProcessorPipeline\Attribute\Process;

final class UserProfile
{
    #[Process(
        processors: ['trim', 'lowercase'],
        messages:   [],
    )]
    public private(set) string $email = '';

    #[Process(
        processors: ['required', 'length' => ['minLength' => 3]],
        messages:   ['required' => 'Username is required.'],
    )]
    public private(set) string $username = '';
}
```

### Structured Exceptions

All exceptions carry a `context` array for structured logging and tracing:

```php
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;

try {
    $pipeline->process($input);
} catch (PipelineExecutionException $e) {
    // $e->context['stage']         — stage index where failure occurred
    // $e->context['processorName'] — FQCN of the failing processor
    // $e->getPrevious()            — original exception
}
```

---

## The Pipeline

```
ProcessorBuilder::buildPipeline($context, $specs)
        │
        ▼
foreach spec entry:
    resolveSpec($key, $value)
      ├── string key   → name only (enabled, default config)
      ├── name => true → enabled, no config
      ├── name => false → SKIP
      └── name => [..] → enabled + configure()
        │
        ▼
    ProcessorRegistry::get($context, $name)
    ConfigurableProcessor::configure($options)  ← if applicable
    $processors[] = $processor
        │
        ▼
new Pipeline($processors)   ← immutable, readonly

Pipeline::process($input)
        │
        ▼
foreach processor as $index => $p:
    try:
        $state = $p->process($state)
    catch \Throwable:
        throw PipelineExecutionException::atStage($p::class, $index, $cause)
        │
        ▼
return $state
```

---

## Architecture

### Source layout

```
src/
├── Attribute/
│   └── Process.php                       PHP 8.4 attribute for declarative pipelines
├── Exception/
│   ├── PipelineExecutionException.php    Stage-aware failure with context array
│   ├── ProcessorNotFoundException.php    Registry miss
│   ├── InvalidProcessorConfigurationException.php
│   └── ProcessorPipelineException.php    Base exception
├── Handler/
│   └── ProcessorHandler.php             Error-collecting processor wrapper
├── Pipeline/
│   └── Pipeline.php                     Immutable readonly sequential executor
├── Result/
│   └── ProcessingResultCollection.php   Error + execution trace accumulator
├── ProcessorBuilder.php                 Factory: spec → Pipeline
└── ProcessorRegistry.php               Context-based processor store
```

### Key design decisions

| Decision | Rationale | ADR |
|---|---|---|
| Immutable `readonly` Pipeline | Eliminates shared-state bugs; safe to reuse across requests | [ADR-001](docs/adrs/ADR-001-immutable-pipeline.md) |
| Context-based registry | Prevents name collisions between validator/sanitizer/transformer domains | [ADR-002](docs/adrs/ADR-002-context-based-registry.md) |
| Flexible spec format | Same interface for simple lists and richly-configured pipelines | [ADR-003](docs/adrs/ADR-003-processor-specification-format.md) |
| `ProcessorHandler` wrapper | Decouples error collection from processor logic; supports halt-or-continue | — |
| `PipelineExecutionException` with stage context | Structured observability — which stage, which processor, which cause | — |

### Specifications

| Spec | Covers |
|---|---|
| [SPEC-001](docs/specs/SPEC-001-processor-pipeline.md) | Full pipeline: registry → builder → execution → error collection |

---

## Integration with the KaririCode Ecosystem

ProcessorPipeline is the **execution engine** used internally by other KaririCode components:

| Component | Role |
|---|---|
| `kariricode/validator` | Builds validation pipelines from `#[Validate]` attributes |
| `kariricode/sanitizer` | Builds sanitization pipelines from `#[Sanitize]` attributes |
| `kariricode/transformer` | Builds transformation pipelines from `#[Transform]` attributes |
| `kariricode/property-inspector` | Discovers `#[Process]` attributes and dispatches to pipeline handlers |

Any component that needs **configurable, composable processing chains** can be built on top of this engine.

---

## Project Stats

| Metric | Value |
|---|---|
| PHP source files | 8 |
| External runtime dependencies | 2 (contract · exception) |
| Test suite | 128 tests · 234 assertions |
| PHPStan level | 9 |
| Code coverage | 100% classes / methods / lines |
| PHP version | 8.4+ |
| ARFA compliance | 1.3 |
| Test suites | Unit + Integration |

---

## Development

This package keeps no QA tools in `require-dev`. [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs PHPUnit, PHPStan, Psalm, PHP-CS-Fixer and Rector into `.kcode/` (git-ignored) the first time a command needs them.

Install `kcode` once (PHP 8.4+):

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
```

From the repository root:

```bash
composer install          # runtime dependencies only
kcode init                # generate .kcode/ and install the toolchain (first run)
kcode test                # PHPUnit (--coverage, --suite=Unit, any PHPUnit option)
kcode analyse             # PHPStan, then Psalm
kcode cs:fix              # apply the code style (--check reports only)
kcode quality             # the CI gate: cs-fixer, PHPStan, Psalm, PHPUnit
kcode quality --docker    # the same gate inside the kcode PHP container
```

Project overrides go in `devkit.php` (`kcode init --config` writes a template); run `kcode init` after editing it. `kcode clean --all` removes `.kcode/`.

---

## Contributing

```bash
git clone https://github.com/KaririCode-Framework/kariricode-processor-pipeline.git
cd kariricode-processor-pipeline
composer install
kcode init
kcode quality  # Must pass before opening a PR
```

---

## License

[MIT License](LICENSE) © [Walmir Silva](mailto:community@kariricode.org)

---

<div align="center">

Part of the **[KaririCode Framework](https://kariricode.org)** ecosystem.

[kariricode.org](https://kariricode.org) · [GitHub](https://github.com/KaririCode-Framework/kariricode-processor-pipeline) · [Packagist](https://packagist.org/packages/kariricode/processor-pipeline) · [Issues](https://github.com/KaririCode-Framework/kariricode-processor-pipeline/issues)

</div>

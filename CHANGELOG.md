# Changelog

All notable changes to this project are documented here. The format follows
Keep a Changelog; versions follow Semantic Versioning (Spec V4.3.3 §32).

## [3.0.0] - Unreleased

Brings the component to KaririCode Spec V4.3.3 and kariricode/contract 2.11.
Migration notes: SPEC-001 §8.1.

### Breaking

- `ProcessorHandler` fails closed by default (`haltOnError: true`): a failure
  is recorded and rethrown. Pass `haltOnError: false` to collect every error.
- `ProcessorBuilder` rejects every specification entry outside its grammar
  (code 8510) and integer option keys (8511) instead of skipping or passing
  them on, and configures a clone instead of the registered instance.
- Exceptions implement one kariricode/contract 2.11.0 family each and carry
  codes 8501–8530. Messages start with `[PP_<code>]` and no longer repeat
  caller-supplied values; `PipelineExecutionException` no longer copies the
  cause's message (read `getPrevious()`). `ProcessorPipelineException` is
  abstract and built only through named factories.
  `InvalidProcessorConfigurationException::forProcessor()` is replaced by
  `nonStringOptionKey()` and `notConfigurable()`;
  `ProcessorNotFoundException::forName()` is removed.
- `ProcessingResultCollection` keys errors and warnings by the fully qualified
  class of the innermost processor instead of its short name, so processors
  with the same short name in different namespaces no longer merge, and a
  decorated stage is recorded under the processor it wraps.
- `ProcessorRegistry` parameters `$processorName` are renamed `$name`, as in
  1.x and kariricode/contract; named arguments `processorName:` must change.
- Requires kariricode/contract ^2.11 (was ^2.8). The unused dependency on
  kariricode/exception is dropped.

### Added

- `PipelineObserver`, `Pipeline::withObserver()` and the constructor's
  `observer` argument: per-stage durations in nanoseconds. `Pipeline` is
  `Countable`.
- `ProcessorDecorator`: decorators expose the wrapped processor, so every
  channel names a stage after the processor that does the work.
  `ProcessorHandler` implements it and exposes `ERROR_KEY`.
- `ProcessorRegistry::find()`, the nullable lookup (Spec §8.1); the registry
  is `Countable`.
- `CircuitBreakerProcessor`, `RateLimitedProcessor` and `RetryingProcessor`
  over the contract's resilience ports, and `RateLimitExceededException`.
- The quality gates of Spec V4.3.3 §29.1 as Composer scripts: PHPStan max,
  Psalm, PER-CS 3.0, complexity budgets, a conformance suite, executable
  documentation examples, Infection, a secret scan, `composer audit` and a
  CycloneDX SBOM.
- ADR-004 to ADR-008, QD-V433-COMPLIANCE and SECURITY.md.

### Changed

- `ci.yml` runs the Composer gates instead of the KaririCode DevKit, and both
  `ci.yml` and `release.yml` pin actions by commit SHA. `release.yml` takes the
  release notes from this file and attaches the SBOM. Spec V4.3.3 tooling
  takes precedence over DevKit conventions.

### Fixed

- Builds no longer mutate registered processors.
- A failure inside a nested pipeline keeps both the outer and the inner stage
  in the exception chain; 2.0 rethrew the inner failure and lost the outer
  stage.
- Numeric processor and context names come back as strings from
  introspection.

### Removed

- The DevKit `code-quality.yml` workflow; its checks run in `ci.yml`.

## [2.0.0] - 2026-03-03

First ARFA 1.3 release; its documents call it V4.0.

### Breaking (from 1.x)

- Requires PHP ^8.4 and kariricode/contract ^2.8; kariricode/data-structure
  and kariricode/property-inspector are no longer required.
- `Pipeline` is immutable: `withProcessor()` and `withPipeline()` return new
  instances; `pipe()` is gone.
- `ProcessorBuilder` accepts the list form and rejects options for processors
  that are not configurable; `build()` is removed.
- `ProcessorRegistry::getContextProcessors(): Map` is replaced by
  `getByContext(): array`.
- `ProcessingResultCollection` has no processed data and no `toArray()`;
  `clear()` became `reset()`.

[3.0.0]: https://github.com/KaririCode-Framework/kariricode-processor-pipeline/compare/v2.0.0...develop
[2.0.0]: https://github.com/KaririCode-Framework/kariricode-processor-pipeline/compare/v1.3.2...v2.0.0

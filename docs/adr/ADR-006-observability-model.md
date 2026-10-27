# ADR-006: Observability Model — Metrics, Attribution, Chaining

**Status:** Accepted  
**Date:** 2026-09-29  
**Author:** Walmir Silva  
**Supersedes:** the "no double-wrap" rule in the original `Pipeline::process()`

## Context

ARFA 1.3 Principle 5 is stated as `∀operation ∈ System: ∃trace(operation) ∧ ∃metrics(operation)`. Measured against it, the component had three gaps:

1. **No metrics.** Nothing measured stage duration or counted failures. Only the trace half existed, and only when a caller wrapped processors in `ProcessorHandler`.
2. **Wrong attribution.** A halting `ProcessorHandler` failure was reported as `processor: ProcessorHandler` — the wrapper, not the failing processor.
3. **Lost outer stage.** A `PipelineExecutionException` raised inside a stage (e.g. by a processor that runs its own pipeline) was rethrown unchanged, so the outer stage index disappeared from the error.

Gaps 2 and 3 are reproduced by `PipelineTest::testStageFailureNamesTheDecoratedProcessorNotTheWrapper` and `PipelineTest::testNestedFailureIsChainedWithOuterStage`.

## Decision

1. **`PipelineObserver`** (optional, vendor-neutral): `stageSucceeded()` / `stageFailed()` with stage index, processor class and `hrtime()` duration in nanoseconds. Attached immutably via `withObserver()`; propagated by `withProcessor()` and `withPipeline()`. Without an observer, no clock is read.
2. **`ProcessorDecorator`**: wrappers expose `innerProcessor()`; the pipeline names a stage after the innermost processor. The walk is bounded (16 levels), so a cyclic decorator cannot hang execution.
3. **Always chain.** Every stage failure, including a nested `PipelineExecutionException`, is wrapped with the outer stage; the inner exception stays reachable through `getPrevious()`.

## Consequences

### Positive

- Every executed stage produces a metric; every failure carries outer and inner stages.
- Backends (KaririCode\Observability, OpenTelemetry, StatsD) plug in without this component depending on them (ARFA 1.3 P4).

### Negative

- Nested failures now produce longer messages (`… stage 1 …: … stage 0 …: cause`).
- Observers must not throw; an exception raised by an observer escapes `process()` and aborts the run. The pipeline does not guard against it, to avoid swallowing observer bugs.
- Durations are wall-clock, including time the stage spent blocked on I/O.

### Neutral

- `ProcessingResultCollection` keeps its role: it records domain errors for end users, while the observer serves operational metrics. The two concerns stay separate.

## Amendment — One Identity for All Four Channels (2026-09-29)

**Finding.** The channels disagreed. `Pipeline` named a stage after the innermost processor, but `ProcessorHandler` recorded `$this->processor::class` (the decorator, when one sat in between), and `ProcessingResultCollection` shortened keys to the class short name, merging `App\Email` and `Vendor\Email` into one group. The short-name convention rested on a claim — "matches the structure consumed by Validator" — that the V1.3.2 source disproves: V1 keyed errors by property.

**Decision.** Every channel identifies a stage by the fully-qualified class name of its innermost processor, resolved by the internal `StageName::of()`, which both `Pipeline` and `ProcessorHandler` call. The collection stores identifiers verbatim.

**Consequences.** Error keys are longer; consumers shorten them for display only. Regression tests: `testSameShortNameInDifferentNamespacesStaysSeparate`, `testErrorKeyAndTraceUseTheSameIdentity`, `testIdentityLooksThroughNestedDecorators`.

## Alternatives Considered

1. **Hard dependency on a metrics library** — violates P4 and burdens every consumer.
2. **`instanceof ProcessorHandler` inside `Pipeline`** — fixes attribution for one wrapper only and couples the engine to it.
3. **Keep rethrowing nested failures unchanged** — shorter messages, but the outer operation vanishes from the trace, which P5 forbids.

## References

- ARFA 1.3 Specification, Principle 5 (Continuous Observability)
- OpenTelemetry Specification — Metrics API (histogram instruments)

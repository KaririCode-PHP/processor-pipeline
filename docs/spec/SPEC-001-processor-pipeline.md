# SPEC-001: KaririCode\ProcessorPipeline

| Item | Value |
|---|---|
| Package | `kariricode/processor-pipeline` 3.0.0 (unreleased); 2.0.0 is the current release |
| Namespace | `KaririCode\ProcessorPipeline` |
| Requires | PHP ^8.4; `kariricode/contract` ^2.11 (interfaces only) |
| Conformance | ARFA 1.3 level 1; OWASP ASVS 5.0 level 2; KaririCode Spec 4.3.3 (`extra.kariricode`) |
| Error codes | 8500–8599 |
| Quality record | [QD-V433-COMPLIANCE](../quality/QD-V433-COMPLIANCE.md) — PENDING |

## 1. Scope

The package runs data through an ordered list of processors. It holds no
processors of its own: components register theirs in a `ProcessorRegistry`,
a `ProcessorBuilder` turns a declarative specification into an immutable
`Pipeline`, and optional decorators add error collection and resilience.

### 1.1 ARFA 1.3 (level 1)

| Principle | Status | Evidence |
|---|---|---|
| P1 Immutable state | claimed | `Pipeline` and the decorators are `readonly`; builds configure clones (ADR-001, ADR-005) |
| P4 Protocol agnosticism | claimed | `mixed` in and out; no transport types |
| P5 Continuous observability | claimed | one stage identity across exceptions, observer, trace and error keys (ADR-006); coded exceptions (ADR-007) |
| P2 Reactive flow, P3 Adaptive context | not claimed | level 2 only (E-009); execution is synchronous |

## 2. Architecture

```
 ProcessorRegistry ──get()──▶ ProcessorBuilder ──buildPipeline()──▶ Pipeline ──process()──▶ output
  context → name → prototype     fail-closed grammar, clones        readonly stages     │
                                                                      │ stageSucceeded / stageFailed
                                                                      ▼
                                                               PipelineObserver
 stage decorators (ProcessorDecorator, named after the innermost processor by StageName):
   ProcessorHandler ── ProcessingResultCollection (collect-all, opt-in)
   RetryingProcessor ── contract RetryPolicy
   CircuitBreakerProcessor ── contract ManualCircuitBreaker
   RateLimitedProcessor ── contract RateLimiter
```

## 3. Public Types

| Type | Kind | Role |
|---|---|---|
| `Pipeline\Pipeline` | final readonly class | runs the stages in order; fails fast with `PipelineExecutionException` |
| `ProcessorRegistry` | final class | prototypes by context and name; `get()` throws, `find()` returns null (§8.1) |
| `ProcessorBuilder` | final readonly class | specification → pipeline; rejects malformed entries |
| `Result\ProcessingResultCollection` | final class | mutable accumulator of errors, warnings and trace, one per execution |
| `Handler\ProcessorHandler` | final readonly class | records a stage failure; rethrows unless `haltOnError: false` |
| `Handler\ProcessorDecorator` | interface | exposes the wrapped processor for stage naming |
| `Observer\PipelineObserver` | interface | per-stage durations in nanoseconds |
| `Integration\CircuitBreakerProcessor`, `RateLimitedProcessor`, `RetryingProcessor` | final readonly classes | resilience over contract ports (ADR-008) |
| `Attribute\Process` | attribute | carries a specification for consumers that read attributes |
| `Exception\*` | exceptions | §5 |

## 4. Algorithms

### 4.1 Execution

```
FOR i = 0 … n−1:
    t₀ ← now()                         only with an observer
    TRY state ← stage[i].process(state)
    CATCH e: observer.stageFailed(i, name(stage[i]), now()−t₀, e)
             THROW PipelineExecutionException(stage i, previous e)
    observer.stageSucceeded(i, name(stage[i]), now()−t₀)
RETURN state
```

`name()` follows `ProcessorDecorator::innerProcessor()` for at most 16 hops.

### 4.2 Specification resolution

| Key | Value | Result |
|---|---|---|
| int | string | stage: the registered processor |
| string | `true` or `[]` | stage: the registered processor |
| string | `false` | no stage |
| string | non-empty `array<string, mixed>` | stage: a clone, configured once |
| string | array with an int option key | `InvalidProcessorConfigurationException` 8511 |
| string | options for a non-configurable processor | 8512 |
| any | anything else | 8510 |

### 4.3 Retry

`$attempt` counts the calls already made, all failed (contract SPEC-001 §2.2).
The policy decides whether to retry and the delay; the decorator waits.

## 5. Error Model (ADR-007)

| Exception | Contract family | Code | Edge mapping (contract ADR-005) |
|---|---|---|---|
| `ProcessorNotFoundException` | `NotFound` | 8501 | 404 |
| `InvalidProcessorConfigurationException` | `Configuration` | 8510, 8511, 8512 | 500 |
| `PipelineExecutionException` | `Runtime` | 8520 | 500, or 503 when `getPrevious()` is a `CallNotPermittedException` |
| `RateLimitExceededException` | `Runtime` (`CallNotPermittedException`) | 8530 | 503 with `Retry-After` |

- Messages start with `[PP_<code>]` and hold only code identifiers; caller
  values are in `$context` (Spec §20.3).
- `ProcessorHandler` fails closed by default (Spec §8.3, §18).
- A stage failure is always chained: `getPrevious()` holds the cause, and a
  nested pipeline's failure keeps both stages (ADR-006).

## 6. Resilience (ADR-008)

- Recommended order: `RetryingProcessor(CircuitBreakerProcessor(RateLimitedProcessor($processor)))`.
- ARFA §4.4.2 `isCritical` ↔ `ProcessorHandler` `haltOnError` (default `true`).
- `stage.timeout` is not implemented: level 1 cannot interrupt a running stage.

## 7. Quality

Gates, measured values, escaped mutants and open items:
[QD-V433-COMPLIANCE](../quality/QD-V433-COMPLIANCE.md).

Documentation follows Spec V4.3.3 §17 (criteria C1–C5, which replaced the
line-count target under E-005). Every ```php block in `src/` docblocks runs in
the `documentation` suite with assertions enabled; blocks marked
`// illustrative` are linted only. `tests/Conformance` enforces the automated
rules of §17 and §18, the exception families and the code block.

## 8. Migration

### 8.1 From 2.0

| 2.0 | 3.0 |
|---|---|
| `ProcessorHandler` collected by default | fails closed; `haltOnError: false` to collect |
| Malformed specification entries skipped silently | rejected (8510); integer option keys rejected (8511) |
| `configure()` on the registered instance | on a clone (ADR-005) |
| `InvalidProcessorConfigurationException::forProcessor($name, $reason)` | `nonStringOptionKey()` (8511), `notConfigurable()` (8512) |
| `ProcessorNotFoundException::forName()` | removed; `forNameInContext()` (8501) |
| `new ProcessorPipelineException(...)` | abstract; built by the named factories only |
| `Pipeline failed at stage {i} (processor: {name}): {cause message}` | `[PP_8520] Pipeline failed at stage {i} (processor: {name}).`; the cause is `getPrevious()` |
| Nested pipeline failure rethrown as is, outer stage lost | both stages kept in the chain (ADR-006) |
| Errors keyed by short class name; a decorator recorded under its own class | keyed by the fully qualified class of the innermost processor |
| `$registry->get(context: …, processorName: …)` | `name:` (as in 1.x and the contract) |
| `kariricode/contract` ^2.8 and `kariricode/exception` ^1.2 | `kariricode/contract` ^2.11 only |

### 8.2 From 1.x (verified against v1.3.2)

| 1.x | 3.0 |
|---|---|
| `$pipeline->pipe($p)` mutates | `$pipeline = $pipeline->withProcessor($p)` |
| Builder read only the map form; list entries were skipped (an all-list spec built an empty pipeline) | list form supported |
| Malformed entries skipped silently | rejected (8510–8512) |
| `configure()` on the registered instance | on a clone |
| Options for a non-configurable processor ignored | 8512 |
| `ProcessorBuilder::build()` | removed; use a one-entry specification |
| `ProcessorRegistry::getContextProcessors(): Map` | `getByContext(): array` |
| `ProcessingResultCollection` keyed by property, `toArray()`, processed data, `clear()` | keyed by processor identity; no `toArray()` or processed data; `reset()` |
| `ProcessorHandler` collected by default | fails closed; `haltOnError: false` to collect |

## 9. Ecosystem Integration (Spec V4.3.3 §31)

| Package | Relation |
|---|---|
| `kariricode/contract` | `Processor`, `ConfigurableProcessor` (2.8.6); resilience ports (2.10.0); exception families (2.11.0) |
| `kariricode/circuit-breaker`, `kariricode/rate-limiter` | implement the ports the decorators use; no direct dependency |
| `kariricode/observability` | its `TracingProcessor` and `MetricsProcessor` should implement `ProcessorDecorator`; that interface should move to the contract (QD §5) |
| `kariricode/validator` | its `ProcessorBridge` does not implement `Processor` and changes the data shape, so it cannot be a stage (to be fixed there) |

## 10. References

- KaririCode Specification V4.3.3: §8 (errors), §17 (documentation), §18 (antipatterns), §20 (security), §29 (gates), §31 (integration), §32 (release governance); errata E-005, E-009, E-044.
- ARFA 1.3 §4.4 (ProcessorPipeline).
- kariricode/contract SPEC-001; ADR-004 (resilience ports); ADR-005 (cross-cutting families).
- ADR-001 … ADR-008 in [`docs/adr/`](../adr/).

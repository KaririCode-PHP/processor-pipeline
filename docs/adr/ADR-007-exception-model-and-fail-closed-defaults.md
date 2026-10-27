# ADR-007: Contract exception families, registered codes and fail-closed defaults

- **Status:** Accepted
- **Date:** 2026-10-02
- **Component:** kariricode/processor-pipeline 3.0.0 (unreleased); kariricode/contract 2.11.0
- **Authors:** Walmir Silva <walmir.silva@kariricode.org>

## Context

A review against KaririCode Spec V4.3.3 found four defects:

1. `ProcessorHandler` defaulted to `haltOnError: false`. A validator that failed
   let later stages run on the rejected value: a `catch` that fails open
   (Spec §18; OWASP Top 10:2025 A10).
2. `PipelineExecutionException` copied the cause's message into its own, and the
   not-found and configuration exceptions repeated the requested name, the
   specification key or the option key. Those values can come from external
   specifications (ProcessorRegistry §16) or carry user input (Spec §20.3; A09).
3. No exception implemented a family of kariricode/contract 2.11.0, none had a
   numeric code, and the ecosystem's allocation registry had no block for this
   package.
4. The base exception was concrete and not final.

## Decision

- `ProcessorHandler` fails closed: a failure is recorded and rethrown.
  `haltOnError: false` is an explicit opt-in to collect-all mode.
- Exceptions implement exactly one contract family and use the block
  8500–8599, the first free block after Cache (8400–8499) in the registry:

| Exception | Family | Code | Factory |
|---|---|---|---|
| `ProcessorNotFoundException` | `NotFound` | 8501 | `forNameInContext()` |
| `InvalidProcessorConfigurationException` | `Configuration` | 8510 | `forSpecificationEntry()` |
| | | 8511 | `nonStringOptionKey()` |
| | | 8512 | `notConfigurable()` |
| `PipelineExecutionException` | `Runtime` | 8520 | `atStage()` |
| `RateLimitExceededException` | `Runtime`, via `Resilience\CallNotPermittedException` | 8530 | `forStage()` |

- Messages start with `[PP_<code>]` and contain only code identifiers (stage
  index, class name). Caller-supplied values go to `$context`.
- `ProcessorPipelineException` is abstract with a `final protected`
  constructor, so exceptions are built by named factories only.
  `forProcessor($name, $reason)`, which took free text, is replaced by
  `nonStringOptionKey()` and `notConfigurable()`; the unused `forName()` is
  removed.

## Alternatives Considered

- **Keep the collect-all default and document it.** Rejected: §8.3 requires the
  safe state by default, and documentation does not change behaviour.
- **Mask values inside messages.** Rejected: no message needs the value, and
  `$context` already serves diagnostics.
- **One code per exception class.** Rejected: the three configuration failures
  need different fixes, so clients must be able to tell them apart.

## Consequences

- Breaking for code that relied on the collect-all default or on message texts
  (CHANGELOG 3.0.0).
- An edge maps the exceptions by family (contract ADR-005: `NotFound` → 404,
  `Configuration` → 500, `Runtime` → 503 with `Retry-After` when known).
- `tests/Conformance` enforces one family per exception and the 8500–8599
  block; `ExceptionFamiliesTest` (group `fail-closed`) checks that messages
  never contain sampled caller values.
- The block still has to be recorded in the ecosystem registry (QD §5).

## ARFA 1.3

- **P5:** numeric codes and structured context make every failure classifiable
  from logs without parsing text.

## References

- KaririCode Spec V4.3.3 §8.1–§8.3, §18, §20.3.
- kariricode/contract 2.11.0, ADR-005 (exception families).
- OWASP Top 10:2025, A09 and A10.

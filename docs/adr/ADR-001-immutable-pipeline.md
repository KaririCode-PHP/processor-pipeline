# ADR-001: Immutable Pipeline Design

**Status:** Accepted  
**Date:** 2025-11-20  
**Author:** Walmir Silva  
**Supersedes:** N/A

## Context

The original ProcessorPipeline (v1.x–v1.3.2) used a mutable `Pipeline` class where `pipe()` modified the internal array in-place. This created several problems:

1. **Race conditions**: Shared pipeline instances modified concurrently in async/fiber contexts produced non-deterministic processor ordering.
2. **Test isolation**: Test suites that reused pipeline fixtures experienced ordering leaks between test methods.
3. **Composition safety**: Building a "base pipeline" and extending it for specific use-cases mutated the base, breaking other consumers.

## Decision

Starting with v4.0.0, `Pipeline` is declared `final readonly class`. The `pipe()` method is replaced by `withProcessor()` which returns a **new** `Pipeline` instance. The original is never modified.

```php
// v1.x (mutable)
$pipeline->pipe(new TrimProcessor()); // mutates $pipeline

// v4.0 (immutable — ARFA 1.3 P1)
$extended = $pipeline->withProcessor(new TrimProcessor()); // new instance
```

## ARFA 1.3 Justification

**Principle 1 (Immutable State Transformation):**

> ∀s ∈ States, ∀t ∈ Transformations: t(s) → s' where s ≠ s' ∧ s.immutable = true

The Pipeline is a state object. Adding a processor is a transformation that must produce a new pipeline, leaving the original unchanged.

## Consequences

### Positive

- Zero race conditions on shared pipeline instances
- Full test isolation without `setUp()` reconstruction
- Safe composition: base pipelines can be extended freely
- Aligns with PHP 8.4+ `readonly class` language support

### Negative

- Marginal memory overhead from cloning processor arrays (negligible for typical pipeline sizes of 3–15 processors)
- Existing v1.x code using `pipe()` requires migration to `withProcessor()`

### Neutral

- `ProcessorBuilder::buildPipeline()` already produced a new pipeline per call, so builder-based code is unaffected

## Alternatives Considered

1. **Copy-on-write with `clone`**: Rejected — PHP's clone semantics for arrays are value-copy, which is what `readonly` already provides. No benefit over the simpler approach.
2. **Freezable pattern** (`$pipeline->freeze()`): Rejected — adds runtime state tracking, violates Principle 1, and creates a temporal coupling anti-pattern.

## References

- ARFA 1.3 Specification §4.4 (ProcessorPipeline Type Definition)
- Okasaki, C., "Purely Functional Data Structures", Cambridge University Press, 1999
- KaririCode Standard Specification V4.0 §3.1 (Immutability Requirements)

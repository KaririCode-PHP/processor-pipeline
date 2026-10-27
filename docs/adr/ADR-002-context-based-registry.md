# ADR-002: Context-Based Processor Registry

**Status:** Accepted — amended 2026-09-29 (justification corrected)  
**Date:** 2025-11-20  
**Author:** Walmir Silva  
**Supersedes:** N/A

## Context

A flat processor registry (name → processor) forces globally unique names. When a project uses both `KaririCode\Validator` and `KaririCode\Sanitizer`, both may register a processor named `trim` — collision.

## Decision

ProcessorRegistry uses a **two-level map**: `context → processorName → Processor`.

```php
$registry->register('validator', 'trim', new TrimValidator());
$registry->register('sanitizer', 'trim', new TrimSanitizer());

// No collision — different contexts
$validator = $registry->get('validator', 'trim');
$sanitizer = $registry->get('sanitizer', 'trim');
```

## Justification

The two-level map is justified by **namespace isolation**: independent domains can use the same processor names without coordination.

> **Amendment (2026-09-29).** The original revision justified this decision with ARFA 1.3 Principle 3 (`Context(t) = {metrics(t), conditions(t), history(t)} → Adaptation(t+1)`). That was a category error: P3 describes *run-time adaptation* driven by metrics and history, while the registry's `context` is a static string namespace fixed at registration. The decision stands on isolation alone; P3 is **not implemented** by this component (see SPEC-001 §2). The metrics an adaptive context would consume are exposed by `PipelineObserver` (ADR-006).

**Principle 4 (Protocol Agnosticism):**

The same processor class can be registered under multiple contexts for different protocol handlers (e.g. `http.validator` vs `grpc.validator`).

## Consequences

### Positive

- Zero naming collisions across domains
- Clean integration between Validator, Sanitizer, Transformer, and Normalizer components
- Context names serve as documentation of intent

### Negative

- Two strings required per `get()` call instead of one
- Slightly more verbose API

### Neutral

- `ProcessorBuilder` abstracts the context parameter — end users typically interact with it rather than the registry directly

## Alternatives Considered

1. **Prefixed names** (`validator.trim`, `sanitizer.trim`): Rejected — convention-based, no compile-time or runtime enforcement, fragile to typos.
2. **Separate registry instances per context**: Rejected — complicates DI configuration and cross-context introspection.

## References

- KaririCode Standard Specification V4.0

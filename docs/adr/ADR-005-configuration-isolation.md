# ADR-005: Configuration Isolation via Prototype Cloning

**Status:** Accepted  
**Date:** 2026-09-29  
**Author:** Walmir Silva  
**Supersedes:** "Configuration is per-build" note in the original `ProcessorBuilder` docblock

## Context

`ProcessorBuilder` used to call `configure()` directly on the instance stored in the registry. Every pipeline built from that registry held the **same** object, so configuring it for one pipeline reconfigured all the others. Reproduced by `ProcessorBuilderTest::testConfiguredBuildsDoNotShareProcessorState`:

```php
$lenient = $builder->buildPipeline('validator', ['length' => ['minLength' => 1]]);
$strict  = $builder->buildPipeline('validator', ['length' => ['minLength' => 10]]);

$lenient->process('hello'); // threw: $strict had reconfigured the shared instance
```

The failure depends on build order and surfaces far from its cause — a direct violation of ARFA 1.3 P1 (`t(s) → s'` with `s` unchanged). `Pipeline` being `readonly` did not help: `readonly` freezes the reference, not the referenced object.

## Decision

Registered instances are **prototypes**. For a configured entry, the builder configures and uses a `clone`; the registry instance is never touched. Unconfigured entries (`'name'`, `'name' => true`, `'name' => []`) keep sharing the registered instance by reference.

## Consequences

### Positive

- Pipelines built with different options are independent, whatever the build order.
- The registry's contents no longer change as a side effect of building.

### Negative

- `clone` is shallow. A `ConfigurableProcessor` whose `configure()` mutates a nested object must implement `__clone()` to deep-copy it, otherwise that nested object is still shared.
- One extra allocation per configured entry per build (build time only, not per `process()` call).
- A processor that forbids cloning fails at build time with PHP's own `Error`.

### Neutral

- Unconfigured processors are still shared. Stateful processors (e.g. the contract's `ValidatableProcessor`, which keeps `isValid()` state between calls) must not be used by two pipelines concurrently; this is unchanged from 1.x.

## Alternatives Considered

1. **Register factories instead of instances** — full isolation, but breaks the registry API (`register(..., Processor)`) and every existing registration.
2. **Document "register separate instances"** (the previous approach) — leaves the defect in place and depends on every caller reading the docblock.
3. **Clone every processor on every build** — isolates stateful processors too, but costs an allocation per stage per build and changes identity semantics for processors that are shared on purpose. Rejected as out of scope.

## References

- ARFA 1.3 Specification, Principle 1 (Immutable State Transformation)
- Gamma, E. et al., "Design Patterns", Addison-Wesley, 1994 — Prototype pattern

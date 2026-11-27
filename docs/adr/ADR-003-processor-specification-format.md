# ADR-003: Flexible Processor Specification Format

**Status:** Accepted — amended 2026-09-29 (fail-closed parsing)  
**Date:** 2025-11-20  
**Author:** Walmir Silva  
**Supersedes:** N/A

## Context

Pipeline construction requires specifying which processors to include and optionally how to configure them. Different use-cases require different levels of control:

1. **Simple**: Just list processor names → `['trim', 'email']`
2. **Selective**: Enable/disable specific processors → `['trim' => true, 'optional' => false]`
3. **Configured**: Provide processor-specific options → `['length' => ['min' => 3]]`

## Decision

`ProcessorBuilder::buildPipeline()` accepts a `$processorSpecs` parameter that supports all three formats in a single array:

```php
$pipeline = $builder->buildPipeline('validator', [
    'required',                               // Simple: enabled by name
    'trim' => true,                           // Selective: enabled explicitly
    'optional' => false,                      // Selective: disabled (skipped)
    'length' => ['minLength' => 3, 'max' => 50],  // Configured
]);
```

### Resolution Rules

| Key Type | Value Type | Interpretation |
|----------|-----------|----------------|
| `int` | `string` | Name from sequential list — enabled, no config |
| `string` | `false` | Disabled — skip this processor |
| `string` | `true` | Enabled — no configuration |
| `string` | `[]` (empty) | Enabled — no configuration |
| `string` | non-empty `array<string, mixed>` | Enabled — configured on a private clone (ADR-005) |
| *anything else* | *anything else* | **Rejected** — `InvalidProcessorConfigurationException` |

Option keys must be strings (option names); `'length' => [3, 50]` is rejected.

### Amendment (2026-09-29): fail closed

The original revision **silently skipped** entries matching no rule. For a pipeline that carries validators, that is fail-open: a typo such as `'required' => 'yes'`, `'required' => 1` or `'required' => null` removed the validator with no signal, and the pipeline kept accepting input it should reject. Unrecognized entries now fail the build with the offending key and value type in the exception context (ARFA 1.3 P5). `false` remains the only way to disable an entry, and it is explicit.

## ARFA 1.3 Justification

**Principle 2 (Reactive Flow Composition):**

The specification defines the composition: which processors, in what order, with what configuration. This is the declarative expression of `Flow = f₁ ∘ f₂ ∘ ... ∘ fₙ`.

## Consequences

### Positive

- Single API serves simple and complex use-cases
- Mirrors the `#[Validate]` / `#[Sanitize]` / `#[Transform]` attribute DSL
- No separate "simple" and "advanced" builder methods

### Negative

- Mixed key types (`int` and `string`) require careful resolution logic
- Type-safety is limited to runtime checks (PHPStan cannot fully validate mixed arrays); the checks run once, at build time
- Specs that relied on the silent skip now fail at build time — intended, but a behaviour change

## References

- KaririCode\Validator `#[Validate(processors: [...], messages: [...])]` attribute format
- ARFA Specification §4.4.2 (Execution Algorithm). ARFA specifies execution, not spec parsing; the resolution rules above are this component's own.

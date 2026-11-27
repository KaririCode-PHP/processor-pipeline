# ADR-004: Compatibility with kariricode/contract

**Status:** Accepted  
**Date:** 2026-09-29  
**Author:** Walmir Silva  
**Supersedes:** N/A

## Context

Earlier V4 drafts declared `"kariricode/contract": "^4.0"`. Packagist was checked on 2026-09-29: the newest published release is **v2.8.6** and no 4.x exists, so `composer install` could not resolve the component at all.

The published contract (v2.8.6) defines, in `KaririCode\Contract\Processor`:

| Interface | Shape | Fits ARFA P1? |
|-----------|-------|---------------|
| `Processor` | `process(mixed): mixed` | ✅ stateless contract |
| `ConfigurableProcessor` | `configure(array): void` | ✅ used on clones only (ADR-005) |
| `Pipeline` | `addProcessor(Processor): self` | ❌ mutation semantics |
| `ProcessorBuilder` | `buildPipeline(...): Pipeline` (the mutable one) | ❌ returns the mutable type |
| `ProcessorRegistry` | `getContextProcessors(string): Map` | ⚠️ needs a `Map` implementation |

## Decision

1. Require `"kariricode/contract": "^2.8"` — the only resolvable constraint.
2. Depend on **`Processor` and `ConfigurableProcessor` only**.
3. Do **not** implement the contract's `Pipeline` or `ProcessorBuilder`.

Implementing `Pipeline::addProcessor(): self` with copy-on-write would type-check but break Liskov substitution: every caller written against the contract ignores the return value (`$pipeline->addProcessor($p);`), so the processor would be silently lost. A type-correct, behaviour-incorrect implementation is worse than none.

`ProcessorRegistry` is not implemented either: `getContextProcessors(): Map` would pull in `kariricode/data-structure` solely to return a `Map`, for no current consumer.

## Consequences

### Positive

- The component installs against published packages.
- No LSP-violating adapters.

### Negative

- Consumers cannot type-hint this component through the contract's `Pipeline`, `ProcessorBuilder` or `ProcessorRegistry` interfaces; they depend on the concrete classes. This falls short of the KaririCode axiom of full dependency inversion until the contract changes.
- Short names collide with the contract interfaces (`ProcessorRegistry`, `ProcessorBuilder`, `Pipeline`); code importing both needs aliases.

### Neutral

- `kariricode/validator` 1.0.0 pins `kariricode/processor-pipeline ^1.1`, so V4 breaks no published dependant.

## Required contract change (next contract major)

Publish immutable abstractions and then implement them here:

```php
interface ImmutablePipeline extends Processor, \Countable
{
    public function withProcessor(Processor $processor): static;
}
```

## References

- Packagist metadata, `repo.packagist.org/p2/kariricode/contract.json` (checked 2026-09-29)
- Liskov, B., Wing, J., "A Behavioral Notion of Subtyping", ACM TOPLAS, 1994

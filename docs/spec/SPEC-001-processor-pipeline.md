# SPEC-001: KaririCode ProcessorPipeline V4.0 — Technical Specification

**Version:** 4.0.0  
**Status:** Final Release  
**Date:** November 2025  
**Author:** Walmir Silva  
**Architecture:** ARFA 1.3 (Adaptive Reactive Flow Architecture)  
**License:** MIT

---

## 1. Overview

KaririCode\ProcessorPipeline is the composition engine of the KaririCode Framework. It provides a context-based processor registry, a flexible builder, and an immutable execution pipeline. Every component in the DPO triad (Validator, Sanitizer, Transformer) depends on ProcessorPipeline for processor orchestration.

### 1.1 Component Identity

| Property | Value |
|----------|-------|
| Packagist | `kariricode/processor-pipeline` |
| Namespace | `KaririCode\ProcessorPipeline` |
| PHP Version | ≥ 8.4 |
| Dependencies | `kariricode/contract ^4.0` |
| External Dependencies | **Zero** |
| Lines of Code (src) | ~1,143 |
| Lines of Code (tests) | ~1,822 |
| Test Count | 128 (91 unit + 12 integration + 7 attribute) |
| Test Coverage Target | ≥ 95% |

### 1.2 ARFA 1.3 Principle Mapping

| Class | P1 | P2 | P3 | P4 | P5 |
|-------|----|----|----|----|-----|
| Pipeline | ✅ | ✅ | — | — | ✅ |
| ProcessorRegistry | — | — | ✅ | ✅ | ✅ |
| ProcessorBuilder | — | ✅ | ✅ | — | — |
| ProcessorHandler | — | — | — | — | ✅ |
| ProcessingResultCollection | ✅ | — | — | — | ✅ |

---

## 2. Architecture

### 2.1 Component Diagram

```
┌──────────────────────────────────────────────────────┐
│                 Consumer Code                        │
│  $pipeline = $builder->buildPipeline('ctx', specs);  │
│  $result = $pipeline->process($data);                │
└──────────────┬───────────────────────────────────────┘
               │
               ▼
┌──────────────────────────┐
│    ProcessorBuilder      │──────────┐
│  buildPipeline(ctx, spec)│          │
└──────────┬───────────────┘          │
           │ resolves                 │ creates
           ▼                          ▼
┌──────────────────────┐   ┌──────────────────┐
│  ProcessorRegistry   │   │    Pipeline       │
│  context → name → P  │   │  [P₁, P₂, ..Pₙ] │
└──────────────────────┘   │  process(input)   │
                           └──────────────────┘
```

### 2.2 Data Flow

```
Input → P₁.process() → P₂.process() → ... → Pₙ.process() → Output
         ↓                ↓                      ↓
    [error?] ──────→ PipelineExecutionException (with stage index)
```

---

## 3. Type Definitions

### 3.1 ProcessorRegistry

```
ProcessorRegistry = {
    processors: Map<String, Map<String, Processor>>
}

Invariants:
    ∀ (ctx, name) ∈ registry:
        processors[ctx][name] implements Processor
```

### 3.2 Pipeline

```
Pipeline<T> = {
    processors: List<Processor>
    immutable: true
}

Invariants:
    processors.length ≥ 0
    ∀i ∈ [0, processors.length):
        processors[i] implements Processor
```

### 3.3 ProcessorBuilder

```
ProcessorBuilder = {
    registry: ProcessorRegistry (injected)
}

buildPipeline: (context: String, specs: ProcessorSpec[]) → Pipeline
```

---

## 4. Algorithms

### 4.1 Pipeline Execution

```
Algorithm: Sequential Pipeline Execution
Input:  pipeline π with processors [P₀..Pₙ₋₁], data d
Output: transformed data d' | PipelineExecutionException

1. state ← d
2. FOR i ← 0 TO n-1 DO
3.   TRY
4.     state ← π.processors[i].process(state)
5.   CATCH throwable
6.     RAISE PipelineExecutionException(stage=i, processor=Pᵢ.class, cause=throwable)
7.   END TRY
8. END FOR
9. RETURN state
```

**Complexity:** O(n·p) where n = |processors|, p = avg processor cost  
**Space:** O(1) additional (state variable reused)

**Theorem 4.1.1 (Termination):**
Pipeline execution terminates in bounded time if every Pᵢ terminates.

*Proof:* The loop iterates exactly n times (finite). Each iteration executes exactly one `process()` call. If Pᵢ terminates, the iteration completes. By induction, all n iterations complete. ∎

### 4.2 Specification Resolution

```
Algorithm: Resolve Processor Specification
Input:  specs = array<int|string, mixed>, registry R, context C
Output: List<Processor>

1. result ← []
2. FOR EACH (key, value) IN specs DO
3.   CASE (typeof key, typeof value):
4.     (int, string)   → name ← value; config ← null
5.     (string, false)  → CONTINUE  // disabled
6.     (string, true)   → name ← key; config ← null
7.     (string, array)  → name ← key; config ← value
8.     OTHERWISE        → CONTINUE  // unknown format
9.   END CASE
10.  processor ← R.get(C, name)  // may throw ProcessorNotFoundException
11.  IF config ≠ null THEN
12.    ASSERT processor instanceof ConfigurableProcessor
13.    processor.configure(config)
14.  END IF
15.  result.append(processor)
16. END FOR
17. RETURN result
```

---

## 5. Exception Hierarchy

```
ProcessorPipelineException (RuntimeException)
├── ProcessorNotFoundException
│     forNameInContext(name, context)
│     forName(name)
├── PipelineExecutionException
│     atStage(processorName, stageIndex, cause)
└── InvalidProcessorConfigurationException
      forProcessor(processorName, reason)
```

All exceptions carry a `context: array<string, mixed>` for structured observability.

---

## 6. PHP 8.4+ Features Used

| Feature | Usage |
|---------|-------|
| `readonly class` | `Pipeline`, `ProcessorBuilder`, `ProcessorHandler`, `Process` attribute |
| Property Hooks (`get {}`) | `ProcessingResultCollection` (6 hooks), `ProcessorRegistry` (3 hooks) |
| Asymmetric Visibility | `Process::$processors` (public read) |
| Constructor Property Promotion | All value-carrying classes |
| Named Arguments | Exception factories |
| `Attribute::IS_REPEATABLE` | `#[Process]` attribute |
| `array_values()` normalization | Pipeline constructor |

---

## 7. Integration Points

### 7.1 KaririCode Ecosystem

| Component | Integration |
|-----------|-------------|
| `kariricode/contract` | `Processor`, `ConfigurableProcessor` interfaces |
| `kariricode/validator` | `#[Validate]` → `ProcessorBuilder::buildPipeline('validator', ...)` |
| `kariricode/sanitizer` | `#[Sanitize]` → `ProcessorBuilder::buildPipeline('sanitizer', ...)` |
| `kariricode/transformer` | `#[Transform]` → `ProcessorBuilder::buildPipeline('transformer', ...)` |
| `kariricode/normalizer` | `#[Normalize]` → `ProcessorBuilder::buildPipeline('normalizer', ...)` |
| `kariricode/property-inspector` | `AttributeAnalyzer` discovers `#[Process]` attributes |
| `kariricode/serializer` | Pre/post-serialization processing chains |

### 7.2 External Integration

| Pattern | Approach |
|---------|----------|
| PSR-11 Container | Register processors from DI container into ProcessorRegistry |
| PSR-14 Events | Dispatch events before/after pipeline execution via ProcessorHandler |
| PSR-3 Logging | Wrap processors with logging handlers |

---

## 8. Quality Metrics

| Metric | Target | Tool |
|--------|--------|------|
| PHPStan Level | 9 (max) | phpstan.neon |
| Psalm Level | 1 (strictest) | psalm.xml |
| PHP CS Fixer | KaririCode ruleset | .php-cs-fixer.php |
| PHPUnit Coverage | ≥ 95% line | phpunit.xml |
| Antipatterns | 0 | Manual review |
| Documentation | ≥ 800 lines per major file | Spec V4.0 |

---

## 9. Migration from V1.x

| V1.x API | V4.0 API | Notes |
|-----------|----------|-------|
| `$pipeline->pipe($p)` | `$pipeline->withProcessor($p)` | Returns new instance |
| `new Pipeline()` then `pipe()` | `new Pipeline([$p1, $p2])` or builder | Immutable construction |
| `ProcessorRegistry::register(ctx, name, p)` | Same | API unchanged |
| `ProcessorBuilder::buildPipeline(ctx, specs)` | Same | API unchanged |
| PHP 8.1+ | PHP 8.4+ | Minimum version raised |

---

## 10. References

- ARFA 1.3 Specification (Patent Pending) — 87 pages, 15 algorithms, 13 theorems
- KaririCode Standard Specification V4.0
- Framework Design Guidelines (Cwalina & Abrams, 3rd ed.)
- Clean Code (Martin, R.C.)
- A Philosophy of Software Design (Ousterhout, J.)

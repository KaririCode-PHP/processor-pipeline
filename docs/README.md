# ProcessorPipeline — Documentation Index

## Specification

- [SPEC-001: Technical Specification](spec/SPEC-001-processor-pipeline.md) — types, algorithms, ARFA compliance matrix, measured quality gates, migration

## Architecture Decision Records

| ADR | Decision | Status |
|-----|----------|--------|
| [ADR-001](adr/ADR-001-immutable-pipeline.md) | Pipelines are immutable (`withProcessor()`) | Accepted |
| [ADR-002](adr/ADR-002-context-based-registry.md) | Two-level registry (context → name) | Accepted — amended 2026-09-29 |
| [ADR-003](adr/ADR-003-processor-specification-format.md) | Specification format; fail-closed parsing | Accepted — amended 2026-09-29 |
| [ADR-004](adr/ADR-004-contract-compatibility.md) | `kariricode/contract ^2.8`; which interfaces are (not) implemented | Accepted |
| [ADR-005](adr/ADR-005-configuration-isolation.md) | Configured entries use a clone of the registered prototype | Accepted |
| [ADR-006](adr/ADR-006-observability-model.md) | Observer metrics, decorator attribution, failure chaining | Accepted |
| [ADR-007](adr/ADR-007-exception-model-and-fail-closed-defaults.md) | Contract exception families, codes 8500–8599, messages without caller values, fail-closed handler |
| [ADR-008](adr/ADR-008-resilience-decorators-over-contract-ports.md) | Circuit breaker, rate limit and retry as decorators over kariricode/contract ports |

The package overview and usage guide is the root [README](../README.md).

## Quality

- [QD-V433-COMPLIANCE](quality/QD-V433-COMPLIANCE.md) — gates, measured values, open items (PENDING)
- [SECURITY.md](../SECURITY.md) — threat model and reporting
- [CHANGELOG.md](../CHANGELOG.md)

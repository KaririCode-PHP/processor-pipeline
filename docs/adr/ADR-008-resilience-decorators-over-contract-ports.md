# ADR-008: Resilience as decorators over the contract's ports

- **Status:** Accepted
- **Date:** 2026-10-02
- **Component:** kariricode/processor-pipeline 3.0.0 (unreleased); kariricode/contract 2.10.0 ports
- **Authors:** Walmir Silva <walmir.silva@kariricode.org>

## Context

ARFA 1.3 §4.4 sketches circuit breakers, rate limiters and retry policies
around pipeline stages. The ecosystem already has kariricode/circuit-breaker
and kariricode/rate-limiter, and kariricode/contract 2.10.0 defines their ports
(`ManualCircuitBreaker`, `RateLimiter`, `RetryPolicy`); its SPEC-001 §8.4 lists
this package as a consumer. Spec V4.3.3 §31 routes coupling between components
through kariricode/contract. Conformance level 1 rules out promises and
preemptive timeouts.

## Decision

Three `ProcessorDecorator` implementations in `src/Integration`, each typed
only against a contract port:

| Decorator | Port | Behaviour |
|---|---|---|
| `CircuitBreakerProcessor` | `ManualCircuitBreaker` | `acquirePermit()`; a rejection propagates the breaker's `CallNotPermittedException` with its `retryAfterSeconds`; the permit is settled once with `recordSuccess()` or `recordFailure()` |
| `RateLimitedProcessor` | `RateLimiter` | `attempt($key, $cost)`; a denial becomes `RateLimitExceededException` (8530); the key defaults to the stage name and never reaches messages or context |
| `RetryingProcessor` | `RetryPolicy` | the retry loop documented by the contract; the wait is injectable, `time_nanosleep` by default |

- Recommended composition, the order Resilience4j applies its aspects:
  `Retry(CircuitBreaker(RateLimiter(processor)))`, wrapped in
  `ProcessorHandler` when collect-all mode is wanted.
- ARFA §4.4.2's `isCritical` maps to `ProcessorHandler`'s `haltOnError`
  (`true`, the default, is critical).
- `stage.timeout` is not implemented: level 1 has no way to interrupt a
  running stage.

## Alternatives Considered

- **Built-in breaker and limiter (Observability's dual mode).** Rejected: state
  in process memory resets on every PHP-FPM request; the libraries own the
  shared state stores.
- **Duck typing on `object` (Observability, DataFlow).** Rejected by Spec §31
  and contract ADR-004.
- **`tryAcquirePermit()` plus an exception of this package.** Rejected: the
  breaker's own exception already carries `retryAfterSeconds`.

## Consequences

- The package requires kariricode/contract `^2.11` (exception families), which
  includes the 2.10 ports; 2.11.0 is not tagged yet (QD §5).
- No dependency on the circuit-breaker or rate-limiter packages.
- The decorators implement `ProcessorDecorator`, so stages keep the name of the
  wrapped processor in every observability channel (ADR-006).

## ARFA 1.3

- **P1:** the decorators are `readonly`. **P4:** no transport types.
  **P5:** stage names unchanged; denials are classified exceptions.
- P2 and P3 are not claimed (level 1).

## References

- Nygard, M. *Release It!*, 2nd ed., ch. 5. Fowler, M. "CircuitBreaker" (2014).
- Brooker, M. "Exponential Backoff And Jitter", AWS Architecture Blog (2015).
- Resilience4j documentation: CircuitBreaker, RateLimiter, Retry, aspect order.
- kariricode/contract SPEC-001 §2.2 and §8.4; ADR-004.

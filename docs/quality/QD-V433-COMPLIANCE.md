# QD-V433-COMPLIANCE: kariricode/processor-pipeline 3.0.0

- **Status: PENDING**
- **Date:** 2026-10-26
- **Component:** kariricode/processor-pipeline 3.0.0 (unreleased); kariricode/contract 2.11.0 (fixture)
- **Standards:** KaririCode Spec V4.3.3 §29.1 (gates) and §32 (supply chain); ARFA 1.3 level 1; OWASP ASVS 5.0 level 2
- **Authors:** Walmir Silva <walmir.silva@kariricode.org>

Every gate of §29.1 passes on a local run. The status stays PENDING until the
open items of §5 are closed; it turns PASS when §5 is empty, and FAIL if a gate
fails.

## 1. Gates (Spec V4.3.3 §29.1)

All numbers come from one local run on 2026-10-26 (§2), not from CI.

| Gate | Command | Budget | Measured | Result |
|---|---|---|---|---|
| Tests | `composer test` | all pass | 160 unit, 12 integration, 99 documentation (docblock examples run with assertions enabled) | pass |
| Conformance | `composer test-conformance` | all pass | 17 source files, 261 assertions | pass |
| PHPStan | `composer phpstan` | level max on `src` and `tests`, no baseline | 0 errors | pass |
| Psalm | `composer psalm` | level 1 on `src`, no baseline | 0 errors; 5 inline suppressions, each with its reason | pass |
| Coding standard | `composer cs-check` | `@PER-CS3x0` | 0 of 46 files to fix | pass |
| Complexity | `composer complexity` | cyclomatic ≤ 10, cognitive ≤ 15, nesting ≤ 3, parameters ≤ 4 | 60 methods; highest cyclomatic 6, nesting 3, parameters 4; no cognitive finding; empty baseline | pass |
| Mutation | `composer infection` | MSI ≥ 80, covered MSI ≥ 80 | 168 mutants: 158 killed, 10 escaped (§4); MSI 94 %; mutation code coverage 100 % | pass |
| Security | `composer security` | no secret, no advisory, fail-closed suite green | secret scan of 71 files, 0 findings (self-test 5 of 5); `composer audit --locked`: no advisories; group `fail-closed`: 7 tests, 35 assertions | pass |

Coverage with PCOV, suites `unit` and `integration`: classes 100 % (15/15), methods 100 % (56/56), lines 100 % (170/170).

`composer sbom` wrote a CycloneDX 1.5 document with 1 runtime component (`kariricode/contract`) and 107 development components marked `excluded`.

## 2. Environment and Metadata

| Item | Value |
|---|---|
| Runtime | PHP 8.4.27 CLI, PCOV 1.0.12 |
| Tools | PHPUnit 11.5.57, PHPStan 2.3.2, Psalm 6.20.1, PHP-CS-Fixer 3.95.27, Infection 0.29.14, nikic/php-parser 5.9.0, tomasvotruba/cognitive-complexity 1.3.0. The run installed them from their Git sources, and PHPStan from its distribution repository at tag 2.3.2, because GitHub archive downloads were unavailable in the environment |
| `kariricode/contract` | 2.11.0 has no tag (checked 2026-10-26: the latest on Packagist is 2.8.6). The run used a fixture: 2.8.6 from Packagist plus the exact 2.9.0–2.11.0 `Resilience` and `Exception` signatures from the contract's SPEC-001, installed from a path repository |
| `extra.kariricode` (gate G4, errata E-044) | `arfa` 1.3 level 1, `asvs` 5.0 level 2, `spec` 4.3.3 |

ARFA 1.3 level 1 covers P1, P4 and P5. P2 and P3 belong to level 2 and are not
claimed.

## 3. Complexity Baseline

Empty: no method exceeds a budget.

## 4. Escaped Mutants

| Location | Mutation | Why it stays |
|---|---|---|
| `RetryingProcessor.php:98` | `>` becomes `>=` | `time_nanosleep(0, 0)` returns at once, the same as skipping the call |
| `RetryingProcessor.php:99` (6) | `1000` or `1_000_000` changed by one | alters only how delays of 999 ms or more split into seconds and nanoseconds; observing it needs tests that sleep for seconds |
| `Pipeline.php:780` (2) | `0` becomes `-1` or `1` when no observer is attached | the value is never read without an observer |
| `Pipeline.php:896` | `(int)` cast removed | `hrtime(true)` already returns an `int` on 64-bit PHP; the cast covers 32-bit builds |

## 5. Open Items

These block PASS:

| # | Item | Closes when |
|---|---|---|
| 1 | `composer.lock` is not committed: the only lock resolves `kariricode/contract` from a local path fixture | contract 2.11.0 is tagged |
| 2 | CI has not run green on GitHub, for the same reason | after 1 |
| 3 | No signed release tag | after 2 |
| 4 | Spec §18 "getters without hooks": method accessors duplicate property hooks (`ProcessingResultCollection`, `ProcessorRegistry::getContextNames()`) and `Pipeline::getProcessors()` | next change set |
| 5 | Spec §18 "arrays as domain return types": error entries are array shapes | next change set |
| 6 | Spec §17 (Ousterhout corrective): parts of the large class docblocks restate code; side-effect notes (C3) are incomplete in Pipeline, ProcessorRegistry, ProcessorBuilder and ProcessingResultCollection | next change set |
| 7 | Block 8500–8599 must be recorded in the ecosystem allocation registry | registry update |
| 8 | `ProcessorDecorator` belongs in kariricode/contract: Observability's processor decorators cannot implement it without depending on this package | contract ADR |

## 6. Accepted Deviations

| Rule | Where | Why |
|---|---|---|
| Named arguments at three or more parameters | `Pipeline::process()` calls `PipelineObserver::stageSucceeded()` (3) and `stageFailed()` (4) positionally | PHP binds named arguments to the parameter names of the implementing method; an observer that renames a parameter would fail at run time |

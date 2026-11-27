# Security Policy

## Reporting a vulnerability

Report vulnerabilities privately to walmir.silva@kariricode.org. Do not open a
public issue. You will receive an acknowledgement, and fixes are released as
patch versions with a CHANGELOG entry.

## Threat model

kariricode/processor-pipeline runs processors chosen by a specification. It
does no I/O of its own; its exposure comes from the specifications it accepts,
the failures it reports and the waits it performs.

| Entry point | Threat | Control |
|---|---|---|
| Specifications built from external input (JSON, admin UI, tenant settings) | invoking code the operator did not intend | the registry is an allowlist; the builder rejects every malformed entry (ADR-003, ProcessorRegistry §16) |
| A processor that rejects input | later stages running on the rejected value | `ProcessorHandler` fails closed by default; collect-all mode is explicit (ADR-007) |
| Exception messages | user input or secrets reaching logs and responses | messages hold codes, stage indexes and class names only; caller values stay in `$context` (ADR-007) |
| Messages in a result collection | personal data reaching logs | documented practice: log identities, keys and counts, not messages (ProcessingResultCollection §21) |
| Retries with blocking waits | exhausting PHP-FPM workers | the policy bounds attempts; delays should stay capped (RetryingProcessor) |
| Rate-limit keys (IP addresses, tenant ids) | disclosure through errors | never copied into messages or context (RateLimitExceededException) |
| Serialized result collections | object injection on `unserialize()` | only between trusted processes, with `allowed_classes` (ProcessingResultCollection §21) |
| Dependencies and CI | supply-chain compromise | one runtime dependency (kariricode/contract, interfaces only); CI actions pinned by commit SHA; SBOM and `composer audit` in `composer check` (Spec V4.3.3 §32) |

## Verification level

The package declares OWASP ASVS 5.0 level 2 in `composer.json`
(`extra.kariricode.asvs`). The fail-closed behaviours above are tested in the
`fail-closed` group, run by `composer security`.

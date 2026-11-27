# ADR-009: Quality toolchain through KaririCode Devkit, with the root configs

- **Status:** Accepted
- **Date:** 2026-11-27
- **Component:** kariricode/processor-pipeline 3.0.0

## Context

The 3.0.0 work put the gates of QD-V433-COMPLIANCE §1 in `require-dev`: PHPStan max, Psalm level 1, PER-CS, the §13 budgets (`tools/complexity.php` and the cognitive-complexity rule), Infection at MSI ≥ 80, `composer audit`, a secret scan and the fail-closed group. Meanwhile the ecosystem settled on KaririCode Devkit for the toolchain, and Devkit 1.2.1 runs a component's committed configs (`'root_configs' => true`), so the gates do not have to weaken to move: kariricode/contract (ADR-008), kariricode/exception (ADR-003) and kariricode/property-inspector (ADR-013) moved first.

## Decision

- `require-dev` is removed. `devkit.php` pins the tools exactly: PHPUnit 11.5.56, PHPStan 2.3.7 with tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer 3.95.27 and Infection 0.35.5. kcode installs them into `.kcode/vendor/`.
- The root configs stay and are what kcode runs: `phpunit.xml.dist`, `phpstan.neon`, `phpstan-complexity.neon`, `psalm.xml`, `.php-cs-fixer.dist.php`, `infection.json.dist`. Paths that pointed into `vendor/` point into `.kcode/vendor/`.
- The gates and commands are unchanged: `composer check`, `composer infection`, `composer sbom`. The scripts call kcode, and `composer security` also audits the toolchain.
- `composer.lock` is committed and holds the runtime only: kariricode/contract 2.12.0. `tools/sbom.php` reads the development components from `.kcode/composer.lock`.
- CI and the release workflow download kcode 1.2.1 by version and check its SHA-256 before `kcode init`. Actions stay pinned by commit SHA.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep the tools in `require-dev` | the rest of the ecosystem installs them through kcode |
| kcode with its generated configs | PHPStan level 9, Psalm level 3, no mutation or conformance gate: weaker than §29.1 |
| Unpinned tools | a gate result could not be reproduced from the commit that produced it (§32) |

## Consequences

- One toolchain model across the ecosystem; this package's gates are unchanged.
- Contributors install kcode once (README, Development).
- Transitive versions of the toolchain resolve at install time until Devkit commits a `devkit.lock`.

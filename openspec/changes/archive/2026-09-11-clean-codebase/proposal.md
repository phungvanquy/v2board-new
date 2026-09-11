## Why

The codebase has grown to 235 PHP files with no automated style, lint, or quality gates. There is no `.php-cs-fixer`, `phpcs`, `phpstan`, or JS linter config, `composer.json` carries no quality scripts, the test suite is two placeholder `ExampleTest`s, and several structural smells have accumulated (a 587-line `Helper` god-class, duplicated URI/query building, repeated `abort(500)` error patterns, an orphan `TODO: default should be in Dict`). Without intervention, every new feature increases review cost and regression risk.

## What Changes

- **Automated style & linting** — add PHP-CS-Fixer (or Pint) + PHPStan (level 5 baseline) + ESLint/Prettier for the Vue admin, with `composer lint` / `composer analyse` scripts and a CI workflow that fails on violations.
- **Dead-code & duplication removal** — delete unreachable code, deduplicate shadowed protocol URI builders (`Clash*`, `Singbox`, `QuantumultX`, `Stash` share substantial logic), extract shared query-encoding helpers, consolidate the 30+ `abort(500)` sites into a small exception helper.
- **Cohesion & decomposition** — split `App\Utils\Helper` (587 lines, 20+ unrelated static methods: UUID, crypto, traffic formatting, subscription URLs, every proxy-protocol URI) into focused collaborators (`SubscriptionHelper`, `TrafficHelper`, `ProtocolUriBuilder` family, `CryptoHelper`); slim oversized controllers/services (`ServerService` 480 lines, `UserController` 469/392 lines) toward single-responsibility boundaries without changing routes or response shapes.
- **Consistency & typing** — add strict types where safe, replace loosely-typed `mixed` pass-throughs with typed DTOs/value objects at service boundaries, normalize error handling (typed exceptions vs ad-hoc `abort`), standardize naming and docblocks.
- **Architecture hygiene** — clarify layering (controllers → services → models/utils), introduce thin interfaces for the protocol formatter family so new protocols plug in without copy-paste, keep all changes internal (no API or DB contract changes).
- **Docs & developer experience** — refresh `README`/`CONTRIBUTING` with the new lint/test commands, add `make lint` / `make fix` shortcuts; ensure `docker compose` dev override still works with the new tooling.

## Capabilities

### New Capabilities

- `code-quality/tooling`: lint/format/static-analysis toolchain, composer/npm scripts, and CI quality gates.

### Modified Capabilities

- None. All refactoring is behavior-preserving, so no existing requirement changes. (The `admin-dashboard-i18n` and `docker-deploy` specs stay untouched; any regression their scenarios describe would be a bug in this change, not a spec update.)

## Impact

- **Code**: `app/Utils/Helper.php`, `app/Protocols/*`, `app/Services/*`, `app/Http/Controllers/**/*`, `resources/js/admin/**/*`, `composer.json`, `package.json`, `Dockerfile`/`docker-compose.yml` (only to mount/run linters if needed), new config files (`.php-cs-fixer.php`, `phpstan.neon`, `.eslintrc.*`).
- **CI**: new GitHub Actions workflow (lint + analyse); no deployment change.
- **Risk**: intentionally zero behavioral change — every refactor step is covered by before/after characterization or existing feature tests; breaking changes are out of scope.
- **Out of scope**: new features, API/route changes, DB migrations, UI redesign, dependency upgrades beyond what the linters require.

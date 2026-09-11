## Context

The repo has ~235 PHP files, a 587-line `App\Utils\Helper` god-class, ~500-line protocol formatters with duplicated network-settings plumbing, and a 400+ line `OrderService`/`ServerService` — all with no formatter, linter, or static-analysis config. `composer.json` and `package.json` expose no quality scripts and CI has no quality gates. Docker images bake the same unlinted sources. See `proposal.md` for the "why"; `specs/code-quality/tooling/spec.md` for the behavioural contract.

## Goals / Non-Goals

**Goals:**
- One-command formatting/linting locally plus a CI gate that fails on violations.
- Decompose `Helper` and the protocol formatter duplication into small, tested collaborators without changing any HTTP or queue contract.
- Establish a PHPStan baseline so the tree passes now and future type errors are caught.

**Non-Goals:**
- New product features, API or schema changes, dependency major upgrades, or UI redesign.
- Exhaustive 100% type coverage — baseline + level 5 first, tighten later.
- Rewriting the Vue admin build pipeline.

## Decisions

**Tooling — PHP formatting: Laravel Pint (wrapping PHP-CS-Fixer)**
*Why:* zero-config Laravel preset matches the project's Laravel 8 conventions; single binary, no extra config churn. Alternative (raw PHP-CS-Fixer with custom ruleset) kept as fallback if Pint's preset needs tuning — the `composer lint` script abstracts the binary so the choice is swappable.
*Config:* `pint.json` at root, preset `laravel`, paths `app/`, `config/`, `database/`, `tests/`.

**Tooling — Static analysis: PHPStan level 5 + baseline**
*Why:* level 5 catches real type errors without drowning a legacy codebase; baseline file (`phpstan-baseline.neon`) lets the tree pass on day one. Psalm considered but PHPStan has broader Laravel extension support (`larastan`). Level will be raised in follow-ups.
*Config:* `phpstan.neon` includes baseline, excludes `bootstrap/cache`, analyses `app/`.

**Tooling — Frontend: ESLint + Prettier only if JS sources warrant it**
*Why:* the admin asset is largely a prebuilt bundle with thin Vue wrappers; adding ESLint to a mostly-vendor bundle creates noise. Decision: add a minimal `.eslintrc` + `prettier` config and an `npm run lint` script, but gate CI on it only if the repo has editable `resources/js` sources at implementation time; otherwise document and defer.

**Refactor — Helper decomposition by responsibility, not by file size**
Split by cohesive duties observed in the 587-line file:
- `Support/SubscriptionHelper` — `getSubscribeUrl` + OTP helpers.
- `Support/TrafficHelper` — `trafficConvert` + related formatting.
- `Support/CryptoHelper` — `uuid/guid`, `randomChar/Port`, ECH keypair.
- `Protocol/UriBuilder` family — one builder per protocol reusing a shared `NetworkSettings` normalizer and `UriString` helper (eliminates the duplicated `configure*Settings` dispatch).
A thin `App\Utils\Helper` facade re-exports the moved methods as `@deprecated` forwarders so external callers (themes, custom scripts) keep working for one release.

**Refactor — Protocol duplication**
Extract `App\Protocols\Support\NetworkSettings` and `App\Protocols\Support\UriString` to collapse the 6 copy-pasted `configure*Settings` methods and the repeated `tls_settings`/`network_settings` key normalization. Introduce `ProtocolFormatter` interface so adding a protocol is a new class, not a new `if` branch.

**Refactor — Error handling**
Replace the 30+ `abort(500, ...)` sites with a small `App\Exceptions` helper / typed exceptions mapped in the handler — same HTTP status, but uniform message source and testable.

**CI**
Single `quality.yml` workflow: `composer lint:check` → `composer analyse` → (conditional) `npm run lint`. Uses `actions/cache` for Composer. Branch protection turns it into a required check after the first green run.

## Risks / Trade-offs

- **Large diff touches many files** → Mitigation: land tooling + baseline first (no semantic changes), then refactor in reviewable slices; each slice must pass the Docker smoke verification (`GET /`, `/healthz`, Horizon probe).
- **Baseline hides real bugs** → Mitigation: baseline is checked in and reviewed; level is raised iteratively; new code is not baseline-exempt.
- **Facade prolongs debt** → Mitigation: mark forwarders `@deprecated` and schedule removal next minor; grep CI warns on new usages of `Helper::`.
- **PHPStan level 5 still noisy on dynamic Eloquent** → Mitigation: include `larastan` extension and a narrow `ignoreErrors` list; keep baseline small.
- **Frontend lint may be low-value** → Mitigation: make it conditional on editable sources; don't block CI on vendor bundles.

## Migration Plan

1. Merge the branch; no data migration. Developers run `composer install && composer lint:check` locally.
2. CI enforces the gate on subsequent PRs. Existing PRs may need a one-time `composer cs:fix`.
3. Rollback is reverting the branch — no schema or queue changes to unwind.

## Open Questions

- Exact Pint rule overrides (e.g., `array_syntax`, `ordered_imports`) — pick the Laravel preset defaults at implementation time; overrides are additive and don't affect the spec.
- Whether to add `rector` for automated type narrowing — deferred; evaluate after the baseline stabilizes.

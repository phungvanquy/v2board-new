## Context

The codebase has 3 test files (`ExampleTest` x2 + `ProtocolSnapshotTest` with 8 cases). Core helpers (`App\Support\*`), services (`OrderService`, `ServerService`, `UserService`, `PaymentService`), and HTTP flows (auth, user, order, subscription, tickets, admin) are untested. Migrations and models exist; factories do not. CI runs `composer lint:check`, `composer analyse`, and `eslint` but not tests. See `proposal.md` for motivation.

## Goals / Non-Goals

**Goals:**
- Establish a reproducible test harness (DB isolation, fakes for cache/queue/mail) and a fast Unit suite plus a Feature/HTTP suite.
- Add coverage for the highest-risk areas first: pure helpers, protocol formatters, `ApiException`, then service logic, then critical HTTP flows.
- Provide factories/builders for `User`, `Plan`, `Order`, `Server` so tests are deterministic without production dumps.
- Make coverage measurable locally and visible in CI (initially informational, tighten later).

**Non-Goals:**
- 100% line coverage in this change; aim for meaningful coverage of critical paths first.
- E2E browser tests or load tests.
- Rewriting application code to be testable beyond minimal seams (fakes, small extract-only refactors where unavoidable); large refactors are separate changes.
- Testing third-party payment gateways end-to-end (stub the gateway boundary).

## Decisions

- **PHPUnit 9 + Laravel test harness** — already in repo (`phpunit.xml` with `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`). Keep it; no new runner.
  - Alternative considered: Pest — nicer syntax but adds migration cost and is unnecessary for the goal.
- **Factories vs builders** — Prefer Laravel `HasFactory` + `database/factories/*` where models support it; for models that don't cleanly support factories (legacy casts/guards), add lightweight test builders/helpers in `tests/Support/` that mirror real defaults. This avoids forcing a mass model refactor to land tests.
  - Alternative: pure builders everywhere — more consistent but duplicates what factories already do well for standard models.
- **DB isolation** — `RefreshDatabase` (or `DatabaseTransactions` where faster) with SQLite in-memory for Feature tests when possible; fall back to MySQL test DB via Docker when MySQL-specific SQL is required. Document both paths; default to SQLite for speed.
- **Mocking boundaries** — Fake at the framework seam (`Cache::fake`-style where available, `Mail::fake`, `Queue::fake`, `Http::fake`) rather than mocking application classes directly. Mock/stub only the payment gateway interface and time (`Carbon::setTestNow`) when needed.
- **Coverage driver** — `phpdbg` or `pcov` for local coverage (`composer test:coverage`); `xdebug` as fallback. No coverage gate that blocks merge in this change — surface as CI artifact/comment first, gate later once baseline is stable.
- **Suite split** — `tests/Unit` (no DB) and `tests/Feature` (HTTP/service with DB). Keep `tests/Feature/ProtocolSnapshotTest.php` as the seed for protocol parity; extend with additional snapshot cases rather than rewriting.
- **Composer scripts** — Add `composer test` (phpunit), `composer test:unit`, `composer test:feature`, `composer test:coverage` (text + html) for ergonomics.

## Risks / Trade-offs

- **Flaky tests from shared state** → Mitigation: transactional isolation, `RefreshDatabase`, no reliance on seeded production data, deterministic factories with explicit overrides.
- **MySQL-specific queries break on SQLite** → Mitigation: keep SQLite for most Feature tests; for the few that need MySQL, run them against the Docker MySQL service and tag/skip accordingly (`--exclude-group mysql` vs `mysql` group).
- **Tests become coupled to implementation** → Mitigation: test observable behavior (status codes, response keys, state transitions, formatter output) not private methods; snapshot only stable outputs.
- **Coverage numbers mislead** → Mitigation: track coverage of `app/` only, report per-directory, and review delta coverage on PRs rather than chasing a global percentage.
- **Slow suite as coverage grows** → Mitigation: keep Unit fast (no DB), parallelize later if needed, and avoid heavy fixtures.

## Migration Plan

1. Land harness: `composer.json` scripts, `phpunit.xml` tweaks if needed, `tests/Support/` helpers, factories/builders for core models.
2. Add Unit suite (helpers, `ApiException`, `CacheKey`, protocol snapshots) — no DB required, establishes baseline green.
3. Add Service tests with fakes — still no real I/O.
4. Add Feature/HTTP tests — introduce `RefreshDatabase`, seed via factories, fake mail/queue/cache.
5. Wire CI: add test job to `.github/workflows/quality.yml`, upload coverage artifact, update `readme.md` with `composer test` / `composer test:coverage`.
6. Rollback: revert is safe — tests are additive; no app code contract changes. If a DB compatibility issue appears, skip the offending group and keep the rest green.

## Open Questions

- Which models warrant real factories vs lightweight builders? Answer during implementation by inspecting each model's `$fillable`/`$casts`/`$guarded` — no spec change needed.
- Exact coverage driver per environment (host vs Docker). Decide at implementation time based on what's available in CI image; document the chosen driver.

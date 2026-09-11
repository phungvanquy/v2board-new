## Why

The project ships with only 3 test files (2 trivial `ExampleTest` + 1 `ProtocolSnapshotTest` with 8 cases). Core services (`OrderService`, `ServerService`, `UserService`, `PaymentService`), helpers (`TrafficHelper`, `CryptoHelper`, `SubscriptionHelper`), protocols (19 formatters), and exception handling (`ApiException`) have no or minimal automated coverage. Changes are validated manually and via smoke checks, leaving regressions undetected until production.

## What Changes

- Establish a test strategy and conventions (unit vs feature vs integration, fixtures/factories, DB handling, mocking boundaries).
- Add **unit tests** for pure/stateless code: `TrafficHelper`, `CryptoHelper`, `SubscriptionHelper`, `CacheKey`, `UriString`/`NetworkSettings`, `ApiException`, and protocol formatters (snapshot/parity).
- Add **feature/HTTP tests** for critical API flows: auth (register/login), user info, order lifecycle, plan listing, server subscription generation, ticket flow, admin user/config flows — using `RefreshDatabase` or in-memory SQLite where feasible, otherwise mocked repositories.
- Add **service tests** for `OrderService`, `UserService`, `ServerService` (reset-day logic, availability, traffic fetch) with fakes for cache/queue/mail.
- Wire tests into quality gates: `composer test` / `composer test:coverage` scripts, CI job, and documented workflow (`readme.md` / `docs/*`).
- Provide seed factories/helpers for `User`, `Plan`, `Order`, `Server` to keep tests deterministic and fast.

## Capabilities

### New Capabilities
- `testing/coverage`: Test strategy, organization, factories/fixtures, coverage measurement, and CI integration for automated tests.

### Modified Capabilities
- `code-quality/tooling`: Extend quality gates to include test execution (and optionally coverage threshold) alongside lint and static analysis.

## Impact

- Affected code: `tests/` (new suites), `phpunit.xml`, `composer.json` scripts, `.github/workflows/quality.yml`, `database/factories` (if added), `readme.md`.
- No breaking API changes. Tests run with `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`.
- Risk is low if DB isolation is correct; otherwise flaky tests. Mitigated by transactional tests and fakes.

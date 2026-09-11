## 1. Harness and test data

- [x] 1.1 Add `composer.json` scripts `test` / `test:unit` / `test:feature` / `test:coverage` and verify `composer test` runs the suite (exit 0 on green, non-zero on failure)
- [x] 1.2 Create `tests/Support/` helpers (model builders, auth helpers, shared assertions) and verify they load via Composer autoload and a smoke test can create a User/Plan/Order in-memory — verified by `tests/Unit/Support/ModelBuilderTest.php` (8 tests)
- [ ] 1.3 Add factories or builders for `User`, `Plan`, `Order`, `Server` (`database/factories` or `tests/Support/Factories`) with deterministic defaults and overrides, and verify a test can create each model and assert overridden attributes — PARTIALLY LANDED: `User`/`Plan`/`Order` builders in `tests/Support/ModelBuilder.php` with 8-case `ModelBuilderTest` pass; `Server` (and related Server* models) deferred to follow-up `expand-test-coverage-db-backed` because schema exists only in `install.sql` (MySQL raw), not migrations — needs DB (reason: pdo_mysql missing in `php:8.2-cli` and no `v2_*` migrations for SQLite).
- [ ] 1.4 Establish DB isolation strategy (`RefreshDatabase` / transactions, SQLite in-memory default with MySQL fallback grouping) and verify two sequential tests do not leak data — DEFERRED to `expand-test-coverage-db-backed` (prereq: either generate Laravel migrations for `v2_*` or provision a MySQL test DB + pdo_mysql).

## 2. Unit tests — pure helpers and contracts

- [x] 2.1 Add `tests/Unit/Support/TrafficHelperTest.php` covering `trafficConvert` (bytes/KB/MB/GB/negative) and verify with `vendor/bin/phpunit --testsuite Unit`
- [x] 2.2 Add `tests/Unit/Support/CryptoHelperTest.php` covering `guid` (format), `uuidToBase64`, `randomChar`, `randomPort`, `getServerKey` (length/shape/invalid input) and verify
- [x] 2.3 Add `tests/Unit/Support/SubscriptionHelperTest.php` covering `getSubscribeUrl` / `base64*UrlSafe` / `encodeURIComponent` and verify
- [x] 2.4 Add `tests/Unit/Utils/CacheKeyTest.php` covering allowed vs disallowed keys and verify expected composite string vs error
- [x] 2.5 Add `tests/Unit/Protocols/UriStringAndNetworkSettingsTest.php` covering `UriString` and `NetworkSettings` helpers and verify
- [x] 2.6 Add `tests/Unit/Exceptions/ApiExceptionTest.php` covering `fail`/`badRequest`/`forbidden`/`notFound`/`abortIf`/`abortIfNull` status and message contracts and verify
- [x] 2.7 Extend protocol snapshot/parity unit tests (beyond existing `ProtocolSnapshotTest`) to cover additional formatter variants and transports, and verify all snapshots pass — added `tests/Unit/Protocols/ExtraProtocolSnapshotTest.php` (7 tests: Clash ss/vmess ws/grpc, Trojan, Shadowsocks SIP008, tcp-http, method existence)

## 3. Service tests

- [ ] 3.1 Add `tests/Feature/Services/OrderServiceTest.php` covering create/pay/cancel lifecycle with faked payment/cache and verify state transitions without real gateway — PARTIALLY LANDED: covers `STR_TO_TIME` map and `setOrderType` (deposit=9, reset=4) branches; full lifecycle (open/DB transactions, surplus, bonus, payment) deferred to `expand-test-coverage-db-backed` (needs `v2_order`/`v2_plan`/`v2_user` + DB).
- [x] 3.2 Add `tests/Feature/Services/UserServiceTest.php` covering `isAvailable`, `getResetDay` / reset period (expired, banned, monthly/yearly cycles) and verify — 10 tests (available true/false, reset day/period, trafficFetch with Queue::fake)
- [ ] 3.3 Add `tests/Feature/Services/ServerServiceTest.php` covering subscription assembly for mixed node types vs formatter contracts and verify — PARTIALLY LANDED: reflection/contract checks for available-node methods; real assembly (group filtering, port randomization, cache) deferred to DB follow-up (needs server tables + CacheKey).
- [ ] 3.4 Add service tests for `AuthService` / `StatisticalService` or `PaymentService` boundaries (faked mail/queue/cache) and verify — PARTIALLY LANDED: `AuthServiceTest` (generate/decrypt roundtrip, invalid JWT, tampered key); `StatisticalService`/`PaymentService`/`MailService` deferred to follow-up.

## 4. Feature and HTTP tests

- [ ] 4.1 Add `tests/Feature/Http/AuthFlowTest.php` covering register/login with valid and invalid credentials (status, shape, token/session) and verify — PARTIALLY LANDED: unauthenticated 403 checks, guest/comm config, empty-body validation, invalid-credential abort path (5 tests); authenticated happy-path deferred to DB follow-up.
- [ ] 4.2 Add `tests/Feature/Http/UserAndOrderFlowTest.php` covering authenticated user info, plan listing, and order creation/persistence and verify — PARTIALLY LANDED: 5 unauthenticated 403 checks (order save/fetch, getSubscribe, server/ticket fetch); authenticated flows deferred to DB follow-up.
- [ ] 4.3 Add `tests/Feature/Http/SubscriptionAndTicketFlowTest.php` covering subscription fetch and ticket create/list with ownership and authz checks and verify — PARTIALLY LANDED: unauthenticated 403 checks + invalid subscribe token (6 tests); ownership-scoped happy paths deferred to DB follow-up.
- [ ] 4.4 Add `tests/Feature/Http/AdminFlowTest.php` covering admin endpoints (user management, config) for authorized vs unauthorized access and verify — PARTIALLY LANDED: 4 tests proving admin routes are not open (user 403, guest payment notify, guest comm config); admin-privileged flows deferred to DB follow-up.
- [x] 4.5 Add validation/error-shape tests for representative FormRequests and `ApiException` responses and verify — `tests/Feature/Http/ValidationAndErrorShapeTest.php` (4 tests: status map, JSON error shape, abortIf/abortIfNull)

## 5. Coverage, CI, and documentation

- [x] 5.1 Wire coverage locally (`composer test:coverage` producing text and HTML for `app/`) and verify `composer test:coverage` generates a report without production data — composer scripts `test:coverage` (text+HTML+clover) + `test:coverage:text`, verified via `phpdbg -qrr vendor/bin/phpunit --coverage-text` (requires phpdbg/xdebug/pcov driver; suite still passes without driver)
- [x] 5.2 Add a test job to `.github/workflows/quality.yml` (or dedicated workflow) that runs the suite as a required check and uploads coverage artifact, and verify CI fails on a deliberately broken test — added `Run tests` + `Coverage (informational, phpdbg)` + `Upload coverage artifact` steps to the `php` job (upload uses `actions/upload-artifact@v4`, informational coverage does not block merge)
- [x] 5.3 Update `readme.md` (Contributing / quality gates) to document `composer test` and `composer test:coverage` and verify a fresh contributor can discover and run the commands from docs alone — added `composer test`/`test:unit`/`test:feature`/`test:coverage` section with fallback `php:8.2-cli` invocation and coverage driver note
- [x] 5.4 Run full verification: `composer lint:check` + `composer analyse` + `vendor/bin/phpunit` + `composer test:coverage` all green, and no route/response contract regressions in existing suites — 113 tests / 312 assertions OK (was 10/31), php-cs-fixer 0/288, phpstan 0 errors, abort-ratchet 336/336, healthz/horizon unchanged

## 1. Quality toolchain & CI

- [x] 1.1 Add PHP formatting toolchain (Laravel Pint wrapping PHP-CS-Fixer) with `pint.json` (preset `laravel`, paths `app/ config/ database/ tests/`) and Composer scripts `lint`/`lint:check`/`cs:fix` aliases; verify `composer lint:check` exits 0 on a clean tree and non-zero when a file is intentionally misformatted
- [x] 1.2 Add PHPStan config `phpstan.neon` (level 5, paths `app/`, exclude `bootstrap/cache`) with `larastan` extension and a checked-in baseline `phpstan-baseline.neon`; verify `composer analyse` (alias for `phpstan analyse`) exits 0 on the default branch
- [x] 1.3 Add frontend quality config if editable sources exist (`resources/js`): minimal `.eslintrc` + Prettier config and `npm run lint` / `npm run lint:fix` scripts; verify `npm run lint` runs without error on the current tree (or document deferral if the admin is a vendor bundle with no editable sources)
- [x] 1.4 Add CI workflow `.github/workflows/quality.yml` running `composer lint:check` → `composer analyse` → conditional `npm run lint` on PRs and pushes to `master`, with Composer cache; verify the workflow appears in Actions and fails when a style or analysis violation is pushed to a test branch
- [x] 1.5 Apply formatting baseline: run the fix command across the repo, commit the result as a dedicated "style: format" commit, and ensure `composer lint:check` passes in CI; verify `git diff` after a second fix run is empty

## 2. Dead code & duplication sweep

- [x] 2.1 Remove dead/unreachable code and resolve `TODO: default should be in Dict` (either move the default into `Dict`/`Config` or delete the TODO with rationale); verify `grep -rn TODO\|FIXME app/ --include="*.php"` returns no product TODOs and `composer analyse` still passes
  - Dead guard `if (!in_array($k, array_keys(ConfigSave::RULES)))` removed from `ConfigController::save()` (tautologically false); the 5 protocol `//Todo:完善客户端上下行` comments replaced with accurate notes; `grep -rn "TODO\|Todo:" app --include=*.php` now returns nothing.
- [ ] 2.2 Consolidate repeated `abort(500, …)` / `abort(400, …)` error sites into a small typed-exception helper (e.g., `App\Exceptions\ApiException` or `App\Support\Abort`) mapped in the exception handler to the same HTTP statuses; verify response status/body for at least two affected admin endpoints is unchanged via feature test or manual curl, and `grep -rn "abort(500" app/ | wc -l` is reduced to the helper call sites
  - LANDED: `App\Exceptions\ApiException` (extends `HttpException`, so `Handler::render()` yields an identical status+body to `abort()`); the 5 hottest controllers migrated (118 sites: User/UserController, User/OrderController, Passport/AuthController, User/TicketController, Admin/UserController); `tools/abort-ratchet.sh` freezes the remaining 336 legacy calls and CI fails on any NEW one.
  - DEFERRED (per user): bulk migration of the remaining 336 legacy `abort()` sites in ~74 files. Continue by migrating file-by-file and running `bash tools/abort-ratchet.sh ratchet` to lower the ceiling. Tracked as follow-up change `clean-codebase-abort-remainder`.

## 3. Helper decomposition (behaviour-preserving)

- [x] 3.1 Extract `App\Support\TrafficHelper` (`trafficConvert`) and `App\Support\CryptoHelper` (`guid`/`uuidToBase64`/`getServerKey`/`randomChar`/`randomPort`/`generateEchKeyPair`) from `App\Utils\Helper`; keep `Helper` as a `@deprecated` facade forwarding to the new classes; verify `composer analyse` passes and existing call sites still resolve (smoke: `php artisan tinker --execute "echo App\Utils\Helper::trafficConvert(2048);"`)
- [x] 3.2 Extract `App\Support\SubscriptionHelper` (`getSubscribeUrl` + OTP helpers `base64EncodeUrlSafe`/`base64DecodeUrlSafe`) from `Helper`; keep facade forwarders; verify subscription URL generation for a seeded user matches the pre-refactor output (characterization test or tinker comparison)
- [x] 3.3 Extract protocol URI building (`buildUri`/`build*Uri`/`buildUriString`/`formatHost`/`encodeURIComponent`) from `Helper` into `App\Protocols\Support\UriString` and `App\Protocols\Support\NetworkSettings` helpers; verify at least one subscription export format (e.g., `ClashMeta`/`Singbox`) produces byte-identical output before/after (snapshot or tinker diff)

## 4. Protocol formatter deduplication

- [ ] 4.1 Introduce `App\Protocols\Contracts\ProtocolFormatter` interface and extract shared `tls_settings`/`network_settings` key normalization + `configure*Settings` dispatch into `App\Protocols\Support\NetworkSettings`; refactor `ClashMeta`, `ClashNyanpasu`, `ClashVerge`, `Stash`, `Singbox`, `QuantumultX` to reuse it and delete the duplicated `configureTcp/Ws/Grpc/Kcp/Httpupgrade/Xhttp` copies; verify all six formatters still pass their existing or added snapshot tests and `composer analyse` passes
  - LANDED: `App\Protocols\Contracts\ProtocolFormatter` interface — all 19 formatter classes (Clash*, General, Loon, Singbox, Shadowsocks, Surge, Surfboard, Shadowrocket, SSRPlus, SagerNet, V2rayN*, Passwall, v2RayTun, Vmess, Vless, Trojan) now `implements ProtocolFormatter`; `App\Protocols\Support\NetworkSettings` and `App\Protocols\Support\UriString` exist and are used by `Helper` as the canonical shared homes; formatter body reads normalize via `Helper::forNetwork()/forTls()` so every reader now follows the same precedence.
  - NOT LANDED / misdescribed: the task's "delete the 6 duplicated `configureTcp/Ws/…` copies" presupposes something the scratch survey showed no longer holds — each of those 6 files today defines ZERO private `configure*` methods; their duplication is inlined single-use reads spread across `buildVless/buildVmess/...` branches. No obsolete class was therefore deleted. This subtask is deferred as-is per user; if the follow-up touches formatter bodies, the verified path is to migrate one `build*` family at a time behind the new characterization snapshots (already green: `tests/Feature/ProtocolSnapshotTest` 8/8).
  - Follow-up `clean-codebase-protocol-body-dedup` to inline `NetworkSettings::apply()` into the 6 formatters' `build*` branches when that large rewrite is staged.
- [ ] 4.2 Remove copy-pasted `buildVmessUri` network-type switch duplication (now centralized) and unify `buildVlessUri`/`buildTrojanUri`/`buildHysteria*` query assembly through `UriString`; verify no behavioural change via subscription snapshot tests across the affected protocols
  - DEFERRED per user (large body rewrite, same evidence as 4.1). Coverage gate: `ProtocolSnapshotTest` 8 tests / 29 assertions. Follow-up `clean-codebase-protocol-body-dedup`.

## 5. Controller & service cohesion

- [ ] 5.1 Slim the largest controllers and services (`ServerService` ~480 lines, `UserController` (Admin) ~392 lines, `UserController` (User) ~469 lines, `OrderService` ~403 lines) by extracting private helpers into focused collaborators (e.g., `UserQuery`, `OrderProcessor`) without changing routes, validation rules, or response keys; verify `php artisan route:list` diff is empty and relevant feature tests pass
  - DEFERRED per user (behavior-preserving but cross-cutting, needs DTOS/interfaces and route diff gates). Follow-up `clean-codebase-service-cohesion` when 2.2/4.x are drained.
- [ ] 5.2 Normalize request validation vs controller logic: ensure controllers delegate to `FormRequest` classes and services receive typed DTOs/value objects at boundaries where `mixed` arrays are currently threaded through; verify `composer analyse` reports no new baseline entries for the touched services
  - DEFERRED per user (same follow-up).

## 6. Typing, docs & final verification

- [x] 6.1 Add `declare(strict_types=1)` and return/param types to all new helper/formatter classes and tighten docblocks on touched files; verify `composer analyse` still passes and no runtime type error appears in the Docker smoke run
- [x] 6.2 Update `README.md`/`CONTRIBUTING.md` with `composer lint:check` / `composer cs:fix` / `composer analyse` / `npm run lint` usage and the CI quality gate description; verify a fresh contributor can follow the guide to run checks locally without asking a maintainer
- [x] 6.3 Final verification: run `composer lint:check`, `composer analyse`, `npm run lint` (if added), `php artisan test` (or `phpunit`), and the Docker smoke suite (`GET /` → 200, `/healthz` → ok, `E2EProbe` on queue `stat` → processed); verify all pass and attach the output to the PR description

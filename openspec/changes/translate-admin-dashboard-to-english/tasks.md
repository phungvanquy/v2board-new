# Tasks: Translate Admin Dashboard to Full English

## 1. Prerequisites & Source Location

- [ ] 1.1 Locate the admin Umi frontend source repo/pipeline that builds `public/assets/admin/` (check private repos, CI config, sibling directories, and git history for source publishing) and record its location + build command — verify `README` or task doc links to the source and `npm run build` / `umi build` reproduces current artifacts
- [ ] 1.2 If no source is found, decide fallback (vendor/recreate Umi project from bundle vs. external repo handoff) and update `design.md` Open Questions; do not proceed to string edits until this is resolved — verify decision is documented and approved before continuing

## 2. String Inventory & Coverage Checklist

- [ ] 2.1 Extract the full set of admin display strings from source (static scan for CJK literals, `formatMessage`/`intl` IDs, and Blade strings in `resources/views/admin.blade.php`) and produce a tracked inventory (sheet/JSON) keyed by route/component — verify inventory file exists and is referenced by later translation tasks
- [ ] 2.2 Build a route/state checklist covering every admin section (dashboard, users, plans, orders, tickets, nodes, settings, coupons, etc.) plus edge states (empty tables, pagination, loading, error boundaries, validation errors, confirm/delete dialogs, toasts) — verify checklist enumerates every admin route found in source router

## 3. Admin i18n Plumbing

- [ ] 3.1 Introduce admin locale resources modeled on `public/theme/default/assets/i18n/` — add source-side `i18n/en-US` (or equivalent) as the source of truth, convert hard-coded Chinese literals to keyed lookups (`formatMessage`/`intl` IDs), and set default locale to English (`en-US`/`en`) in Umi/intl config — verify `grep -R '[一-鿿]' <admin source>` returns no display strings outside locale files (brand names excepted and documented)
- [ ] 3.2 Ensure locale fallback behavior: missing keys fall back to English rather than rendering raw keys or blanks, and locale files are the single source of truth for display strings — verify by temporarily deleting a key in a non-English locale file and observing English fallback in the running admin UI
- [ ] 3.3 Keep change presentation-only: confirm no admin API contracts, route paths (`secure_path`), or permission logic were altered — verify via diff review that only string/i18n/config files changed outside `public/assets/admin/` build output

## 4. Server-Rendered & API-Surfaced Messages

- [ ] 4.1 Update `resources/views/admin.blade.php` and any admin-scoped server messages so titles, `window.settings` labels, and error/validation messages shown in the admin are English — verify loading `/admin` renders an English title and settings with no Chinese text in view source
- [ ] 4.2 Ensure admin-surfaced API messages have English entries in `resources/lang/en-US.json` (add keys or duplicate where admin vs. user wording must differ) and scope admin request locale to English without changing the global `config/app.php` default if the user theme must stay Chinese — verify admin API error responses (e.g., invalid input, duplicate email) return English messages while global locale remains untouched

## 5. Build & Deploy Artifacts

- [ ] 5.1 Rebuild admin assets and commit/update `public/assets/admin/` (`umi.js`, `vendors.async.js`, `components.async.js`, CSS, locale chunks) so the deployed bundle reflects English locale resources — verify rebuilt `public/assets/admin/umi.js` contains English strings and no hard-coded Chinese display text (exclude `env.example.js` comments)
- [ ] 5.2 Confirm cache-busting works after translation changes (existing `?v={{$version}}` in `admin.blade.php` + version bump/build step on deploy) — verify a fresh browser load after deploy receives the new assets without stale Chinese strings persisting from cache

## 6. QA, Guardrails & Docs

- [ ] 6.1 Full QA sweep: exercise every route/state from the 2.2 checklist (including error/empty/confirm/toast flows) and confirm no Chinese characters appear in rendered DOM, tooltips, or notifications — verify QA sign-off with checklist marked pass for every entry
- [ ] 6.2 Add a CI/local guard that fails if Chinese display strings remain in admin source (e.g., `grep -R '[一-鿿]' <admin source locale-excluded>` in CI or a pre-commit hook) — verify the guard fails on a seeded Chinese literal and passes on the cleaned codebase
- [ ] 6.3 Update admin operator docs/screenshots and release notes that referenced Chinese labels — verify docs no longer show outdated Chinese admin screenshots/text

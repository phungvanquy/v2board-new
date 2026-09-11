# Design: Translate Admin Dashboard to Full English

## Context

The admin dashboard is served at `/admin` via `resources/views/admin.blade.php`, which boots a compiled [Umi](https://umijs.org/) frontend from `public/assets/admin/` (`umi.js`, `vendors.async.js`, `components.async.js`, CSS). The frontend **source is not in this repo** — only built artifacts are checked in. A grep of `public/assets/admin/umi.js` shows intl/locale plumbing (`formatMessage`/`intl` present, `a.a.locale("zh-cn")`), so an i18n mechanism exists but default locale is `zh-CN`. The user-facing theme under `public/theme/default/assets/i18n/` already follows a locale-file pattern (`en-US.js`, `zh-CN.js`, etc.), but no equivalent `public/assets/admin/i18n/` exists. Server locale defaults are `zh-CN` in `config/app.php`; `resources/lang/` holds API/error messages used by both user and admin surfaces.

See `proposal.md` for why full English is needed. See `specs/admin-dashboard-i18n/spec.md` for behavioral requirements.

## Goals / Non-Goals

**Goals:**
- Render every admin UI string in English with no hard-coded Chinese remaining.
- Keep the English text in a reviewable, diffable locale table that is the sole source of the shipped strings, so re-translating or correcting wording never means hand-editing a bundle.
- Produce `public/assets/admin/` artifacts generated from that table by a reproducible command; keep cache-busting working.
- Keep behavior/contracts/routes/permissions unchanged (presentation-only).

**Non-Goals:**
- Translating the end-user theme, emails (`resources/views/mail/`), or marketing/docs content — admin only (unless a shared message catalog forces it).
- Adding a locale switcher UI or a per-user locale preference. English is the shipped default and only shipped admin locale.
- Restoring the upstream Umi source, or reconstructing a buildable admin project from the bundle.
- Changing API error-code semantics — only the human message text where surfaced in admin.

## Resolved: there is no admin source repo

Task 1.1 was answered definitively. Upstream `v2board/v2board-admin` ships **compiled artifacts only** — no Umi source, no build config, nothing that reproduces the checked-in bundle. The source-location premise behind D2 and the original migration plan is therefore false, and the fallback it anticipated is the only path available.

The consequence for the design is that "source of truth" cannot mean component source with `formatMessage` IDs. It now means: **an editable English locale table checked into this repo (`admin-i18n/strings/`), plus a deterministic tool (`admin-i18n/translate.mjs`) that regenerates the bundle from it.** The bundle is a build artifact of that table, which is the property the spec actually requires.

## Decisions

**D1: Locale resources live in `admin-i18n/strings/`**
`admin-i18n/strings/parts/*.json` is the human-editable English source of truth, keyed by the exact Chinese literal (a stable identifier that survives minification and renumbering, unlike a positional index). Two tiers, first non-blank match wins: `default.json` for the shared table, and `<file>.<module>.json` per-module overrides for when one Chinese word genuinely needs different English in two places (`关闭` is "Close" on a chart toolbar but "Closed" in a ticket status map; moment's `日` is the format token `D`, while the UI word is "Day"). `extract.mjs` merges them into the generated `admin-ui.en-US.json` inventory shaped `{ "<file>@<module>": { zh: en } }`, and renders `report/admin-ui.zh-en.md` for review.
Rationale: a keyed table is diffable and reviewable in a way that edits to a 4.7 MB minified bundle are not. Alternative considered — inventing a runtime `formatMessage` layer inside the bundle — rejected: it would require rewriting module internals to add an i18n lookup the minified code does not have, which is far more fragile than replacing string values.

**D2: Rewrite the compiled bundle, but only at AST token spans**
`translate.mjs` parses each bundle with acorn and replaces only the byte spans of JavaScript **string literals** whose decoded value contains CJK. This is the load-bearing safety property:
- Regex sources, identifiers, and template syntax are structurally out of reach, because they are not string-literal tokens. A naive text substitution over the bundle could corrupt a regex or a property name.
- Literals are attributed to their exact webpack module via the module map (the object literal with the most function-valued properties), so per-module overrides resolve correctly and a wrong-but-plausible translation cannot leak across modules.
- Non-ASCII is re-emitted as `\uXXXX`, so the output stays ASCII-only like the original build.
- Rewrites are applied in reverse offset order after an explicit overlap assertion.
- The pipeline is idempotent: it scans whatever is currently in `public/assets/admin/`, so a second run is a no-op and cannot restore Chinese or stack edits. `admin-i18n/orig/` keeps a one-time pristine copy of the untranslated bundles for recovery.

**D3: Fail closed before publishing**
The tool refuses to write if any literal is untranslated (`__TBD__`), still contains CJK, or has no inventory entry. Staged output under `admin-i18n/build/` must (a) re-parse as JavaScript and (b) contain no translatable residue; then `verify.mjs` asserts the staged bundles against a headless DOM. Only after all of that does anything reach `public/assets/admin/`.
Rationale: a half-translated bundle that still loads is worse than a failed build, because it looks deployed. Alternatives considered — `--no-verify` as a default convenience, or warn-and-ship on residue — rejected for that reason.

**D4: Third-party locale labels need no separate shim**
The earlier plan assumed ECharts toolbox and date-picker labels were baked into library code in a form the rewriter could not reach, and proposed a runtime `admin-i18n/locale.js` patch. That assumption is **false and was checked**: the AST scan finds those labels as ordinary CJK string literals in the bundles — `umi.js@L9pr` holds `今天`, `确定`, `清除`, `返回今天` (the antd/moment date-picker strings), `vendors.async.js` holds 142 more including the ECharts toolbox language table. Every one of them is reachable by D2's literal-span rewrite, so no runtime shim is loaded, no extra request is added to admin boot, and there is no second code path that could drift out of sync with the table. `translate.mjs` no longer copies a shim.
Rationale: adding a shim for text the primary mechanism already covers would create two sources of truth for the same label.

**D5: Server messages use the existing Laravel `__()` / `resources/lang/` pipeline**
For strings rendered by Blade or returned as API messages that surface in admin, ensure English entries exist and the admin request path resolves to `en`/`en-US`. Keep `config/app.php` locale default as `zh-CN` for now if the user theme must stay Chinese, and scope the admin locale override to the admin middleware/controller (e.g., `App\Http\Middleware` or `AdminController` base) rather than global default. Alternative (flip global locale to `en`) rejected — would affect user theme unexpectedly.

**D6: String inventory via AST scan, with a manual sweep for runtime-built text**
`extract.mjs` enumerates every CJK literal in the bundles per module — this is the authoritative inventory and it is complete by construction (nothing displayed can be outside a literal, except text assembled at runtime). The manual route/state checklist covers what scanning cannot see: strings composed from fragments, server-returned messages rendered verbatim, and states that need a click to reach.
Rationale: the original plan assumed static extraction would miss toasts; the AST scan does not, so the manual pass is scoped down to composition and runtime sources rather than re-enumerating everything.

**D7: `guard.mjs` is the enforcement gate, not a grep**
A plain `grep '[一-鿿]'` over the bundles fails on the allowlisted non-display literals (regjsparser regex fragments, core-js's U+3000 whitespace table, the `he` entity table, zrender's CJK glyph-width measurement probes — see `strings/admin-ui.allowlist.json`). `guard.mjs` therefore re-scans via the same AST path, applies the allowlist, and additionally flags any surviving literal with no inventory entry — which catches a bundle replaced or upgraded without re-running `extract.mjs`.

## Risks / Trade-offs

- **Translations applied to a bundle that was since rebuilt** → Mitigation: `guard.mjs` and `extract.mjs --check` both fail on inventory drift (a live literal with no entry, or an authored key matching nothing), so a stale table cannot pass silently.
- **One Chinese word needing two English meanings** → Mitigation: per-module override tier (D1) plus `extract.mjs --conflicts`, which lists every string occurring in more than one module for review.
- **Shape corruption** (a translation that drops a `%s`, a placeholder, a URL, or padding whitespace) → Mitigation: `check-shape.mjs` asserts whitespace borders, newline/tab counts, digit runs, printf/`{}` tokens, and technical identifiers survive per key.
- **Minified identifier accidentally rewritten** → Mitigation: impossible by construction under D2 — identifiers are not string-literal tokens.
- **Incomplete coverage (missed toasts/edge states)** → Mitigation: AST scan is exhaustive for literals; checklist-driven QA pass over every route + error/empty/confirm states covers the rest.
- **Locale-switching is not provided** → Accepted trade-off: this delivers English-only. The spec's per-user-preference scenario is marked not-applicable in the tasks file because the system has no admin locale preference to honor; adding one is an explicit non-goal (see design Non-Goals).
- **Bundle size** → English literals are ASCII (2 bytes/char) versus `\uXXXX`-escaped CJK in the source; net size is roughly neutral, no perf concern.
- **Stale caches after deploy** → Mitigation: existing `?v={{$version}}` in `admin.blade.php`; the version bump is part of publishing.

## Migration Plan

1. `node admin-i18n/extract.mjs --seed` to create/extend the key skeleton; author English in `strings/parts/` (shared `default.json`, per-module overrides where the same Chinese means different things).
2. `node admin-i18n/check-shape.mjs` to catch structural corruption, then `node admin-i18n/extract.mjs` to regenerate the inventory + report.
3. `node admin-i18n/translate.mjs --dry-run`, then `--stage`, then publish once the staged bundles are clean and `verify.mjs` passes.
4. Server side: English Blade strings, `resources/lang/` entries, admin-scoped locale override without touching the global default.
5. `node admin-i18n/guard.mjs` + `extract.mjs --check` as the CI gate; bump `version`; full-route QA sweep.
6. Rollback = `git checkout public/assets/admin/` (or restore `admin-i18n/orig/`) + revert the Blade/lang changes. No data migration, no deploy coupling.

## Open Questions

- Should Chinese remain available as a selectable locale after this change? English is now the shipped default and `admin-i18n/orig/` retains the Chinese bundles as a manual rollback. A selectable locale switcher remains a non-blocking follow-up, and would require the `locale.js` shim pattern to be extended rather than a component change.

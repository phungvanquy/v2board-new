# Design: Translate Admin Dashboard to Full English

## Context

The admin dashboard is served at `/admin` via `resources/views/admin.blade.php`, which boots a compiled [Umi](https://umijs.org/) frontend from `public/assets/admin/` (`umi.js`, `vendors.async.js`, `components.async.js`, CSS). The frontend **source is not in this repo** — only built artifacts are checked in. A grep of `public/assets/admin/umi.js` shows intl/locale plumbing (`formatMessage`/`intl` present, `a.a.locale("zh-cn")`), so an i18n mechanism exists but default locale is `zh-CN`. The user-facing theme under `public/theme/default/assets/i18n/` already follows a locale-file pattern (`en-US.js`, `zh-CN.js`, etc.), but no equivalent `public/assets/admin/i18n/` exists. Server locale defaults are `zh-CN` in `config/app.php`; `resources/lang/` holds API/error messages used by both user and admin surfaces.

See `proposal.md` for why full English is needed. See `specs/admin-dashboard-i18n/spec.md` for behavioral requirements.

## Goals / Non-Goals

**Goals:**
- Render every admin UI string in English with no hard-coded Chinese remaining.
- Externalize strings to locale files so future locales require only a new file, not code edits.
- Produce rebuilt `public/assets/admin/` artifacts that reflect the change; keep cache-busting working.
- Keep behavior/contracts/routes/permissions unchanged (presentation-only).

**Non-Goals:**
- Translating the end-user theme, emails (`resources/views/mail/`), or marketing/docs content — admin only (unless a shared message catalog forces it).
- Adding a locale switcher UI or per-user preference system unless it already exists; English as default is sufficient for this change.
- Changing API error-code semantics — only the human message text where surfaced in admin.

## Decisions

**D1: Locale-file pattern modeled on `public/theme/default/assets/i18n/`**
Use `en-US` (or `en`) as the admin source-of-truth locale file and make `zh-CN` optional later. Admin bundle already uses `formatMessage`/`intl`, so introduce `public/assets/admin/i18n/en-US.js` (and source-equivalent) mirroring the theme's per-locale file shape. Rationale: reuses established pattern, avoids inventing a new i18n stack. Alternatives considered: `react-intl` message descriptors per component — heavier migration; editing compiled JS literals — fragile and non-maintainable.

**D2: Resolve the missing source repo before editing strings**
Do not edit `public/assets/admin/umi.js` by hand. First locate the admin frontend source repo/pipeline that produces `public/assets/admin/`. If truly unavailable, the fallback is to vendor that source into this repo (extract or recreate an Umi project from the bundle) — but that is a separate prerequisite decision. Rationale: hand-editing compiled bundles is unreviewable and will be overwritten on next build. Alternative (patch compiled JS + add build guard) rejected for maintenance cost.

**D3: Server messages: use existing `resources/lang/en-US.json` / Laravel `__()` pipeline**
For strings rendered by Blade or returned as API messages that surface in admin, ensure English entries exist and the admin request path resolves to `en`/`en-US`. Keep `config/app.php` locale default as `zh-CN` for now if the user theme must stay Chinese, and scope the admin locale override to the admin middleware/controller (e.g., `App\Http\Middleware` or `AdminController` base) rather than global default. Alternative (flip global locale to `en`) rejected — would affect user theme unexpectedly.

**D4: String inventory via static extraction + manual screen sweep**
Generate the translation inventory by (a) static extraction of Chinese literals from admin source (regex on CJK / `formatMessage` IDs), and (b) a QA pass over every admin route/dialog/error state. Track coverage in a sheet/checklist consumed by `tasks.md` T3. Rationale: static extraction alone misses toast/validation strings built dynamically.

## Risks / Trade-offs

- **Admin source not in this repo** → Mitigation: tasks open with a source-location step; if not found, add a task to vendor/recreate source rather than proceeding to edit compiled assets.
- **Incomplete coverage (missed toasts/edge states)** → Mitigation: checklist-driven QA pass over every route + error/empty/confirm states; automated CJK grep in CI as a guard (`grep -R '[一-鿿]' <admin source>` fails if matches remain).
- **Bundle size / locale loading** → Trade-off: locale files are tiny; loading `en-US` by default with optional lazy `zh-CN` is negligible. No perf concern.
- **Other consumers of shared `resources/lang` keys** → Mitigation: audit key usage; duplicate keys if a single key needs different wording in admin vs. user context.
- **Stale caches after deploy** → Mitigation: existing `?v={{$version}}` in `admin.blade.php` already handles cache-busting; just ensure a version bump/build step runs on deploy.

## Migration Plan

1. Locate admin frontend source; confirm build reproduces current `public/assets/admin/` output.
2. Introduce `i18n/en-US` as source of truth, convert hard-coded Chinese to keyed lookups, set default locale to English in source config.
3. Add/verify English entries for admin-surfaced server messages in `resources/lang/`.
4. Rebuild admin assets, commit `public/assets/admin/` (or switch to CI-built artifacts if preferred), bump `version`.
5. Deploy; verify no CJK remains (automated grep + manual sweep). Rollback is reverting the asset commit / redeploying prior `public/assets/admin/` version — no data migration.

## Open Questions

- Where does the admin Umi source live? (private repo, submodule, or untracked local copy?) — answer determines whether work starts in this repo or an external one.
- Should Chinese remain available as a selectable locale after this change, or is English-only acceptable? Spec requires English default + fallback; retaining `zh-CN` as an optional locale is a non-blocking follow-up.

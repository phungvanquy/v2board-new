# Proposal: Translate Admin Dashboard to Full English

## Why

The admin dashboard (`/admin`) currently renders UI text primarily in Chinese, which blocks non-Chinese-speaking operators from using the system and creates inconsistency with any English-facing user theme. Converting the entire admin surface to English removes the language barrier for international deployments and makes future localization straightforward.

## What Changes

- Audit every admin UI string (navigation, tables, forms, validation messages, notifications, empty/loading/error states) and replace hard-coded Chinese with English equivalents.
- Externalize all display strings into a checked-in English locale table (`admin-i18n/strings/`) rather than inlining English directly, so strings are not re-hard-coded in a second language.
- Ship the admin bundle as a generated artifact of that table: a deterministic tool (`admin-i18n/translate.mjs`) rewrites the compiled bundles at AST string-literal spans, with verification required before publish. The admin frontend has no upstream source (see `design.md`), so the bundle is regenerated from the locale table rather than from a build of component source.
- Update any server-rendered admin shell text (`resources/views/admin.blade.php` title/settings, API error messages surfaced in the admin) that is currently Chinese.
- Keep API contracts, routes (`secure_path`), and data shapes unchanged; change is presentation-only.
- No breaking changes to user-facing theme or public i18n files beyond what is required for consistency.

## Capabilities

### New Capabilities
- `admin-dashboard-i18n`: Admin dashboard is fully rendered in English with all UI strings externalized to a checked-in locale table and no hard-coded Chinese remaining; English is the admin locale and display strings are maintained via that table rather than in bundle source.

### Modified Capabilities
- (none — no existing specs to modify; current spec inventory is empty)

## Impact

- **Code**: `admin-i18n/` (locale table + the tooling that generates the bundles from it), `resources/views/admin.blade.php`, admin-scoped server messages, and `resources/lang/`. Generated output lands in `public/assets/admin/`.
- **Build/deploy**: Admin assets are produced by `node admin-i18n/translate.mjs`, not by a frontend build; version cache-busting via `?v={{$version}}` already in place.
- **Docs**: Admin operator docs/screenshots that reference Chinese labels will need updating; `admin-i18n/README` documents the string-authoring workflow.
- **Risk**: The admin frontend source does not exist upstream — `v2board/v2board-admin` ships compiled artifacts only — so strings must be changed in the compiled bundle. That is mitigated structurally rather than by hand-editing: rewrites happen only at acorn string-literal token spans (never regex sources, identifiers or template syntax), the result is staged, re-parsed, and asserted against a headless DOM before it is published, and `guard.mjs` fails CI if any non-allowlisted Chinese literal remains. Residual risk: any upstream bundle upgrade invalidates the keyed translations and must be re-extracted.

# Proposal: Translate Admin Dashboard to Full English

## Why

The admin dashboard (`/admin`) currently renders UI text primarily in Chinese, which blocks non-Chinese-speaking operators from using the system and creates inconsistency with any English-facing user theme. Converting the entire admin surface to English removes the language barrier for international deployments and makes future localization straightforward.

## What Changes

- Audit every admin UI string (navigation, tables, forms, validation messages, notifications, empty/loading/error states) and replace hard-coded Chinese with English equivalents.
- Externalize all display strings behind an i18n mechanism (locale files + runtime lookup) rather than inlining English directly, so strings are not re-hard-coded in a second language.
- Set English as the default/fallback admin locale; if the admin source is a compiled Umi app, update source strings and rebuild `public/assets/admin/` artifacts.
- Update any server-rendered admin shell text (`resources/views/admin.blade.php` title/settings, API error messages surfaced in the admin) that is currently Chinese.
- Keep API contracts, routes (`secure_path`), and data shapes unchanged; change is presentation-only.
- No breaking changes to user-facing theme or public i18n files beyond what is required for consistency.

## Capabilities

### New Capabilities
- `admin-dashboard-i18n`: Admin dashboard is fully rendered in English with all UI strings externalized and no hard-coded Chinese remaining; English is the default admin locale and strings are maintained via locale files.

### Modified Capabilities
- (none — no existing specs to modify; current spec inventory is empty)

## Impact

- **Code**: Admin frontend source (wherever the Umi app lives — likely private build pipeline/repo generating `public/assets/admin/`), `resources/views/admin.blade.php`, and any admin-related server messages that surface in the UI. Locale/translation files; build output in `public/assets/admin/`.
- **Build/deploy**: Requires rebuilding admin assets after source string changes; version cache-busting via `?v={{$version}}` already in place.
- **Docs**: Admin operator docs/screenshots that reference Chinese labels will need updating.
- **Risk**: Source for `public/assets/admin/umi.js` is not in this repo (compiled bundle). If the admin frontend lives in a separate repo/pipeline, implementation must start there; otherwise strings must be edited in the compiled output (fragile) or the source must be located first.

# admin-dashboard-i18n Specification

## Purpose
Provide a fully English admin dashboard with no remaining hard-coded Chinese strings, with display text held in a checked-in locale table that deterministically generates the shipped admin assets, so the UI is usable by English-speaking operators and future localization does not require editing compiled bundles by hand.

## Requirements

### Requirement: Admin dashboard renders entirely in English

The system SHALL render every user-visible string in the admin dashboard (`/admin`) in English with no remaining hard-coded Chinese text in navigation, tables, forms, validation messages, notifications, empty/loading/error states, or any other UI surface.

#### Scenario: Operator navigates the admin dashboard in English
- **WHEN** an authenticated admin loads any admin route (dashboard, users, plans, orders, tickets, nodes, settings, etc.)
- **THEN** all labels, headings, buttons, table headers, form fields, placeholders, and status text are displayed in English

#### Scenario: No Chinese text remains in admin UI
- **WHEN** the full set of admin routes and modals/dialogs is exercised (including error and empty states)
- **THEN** no Chinese characters appear in rendered DOM text, tooltips, or toast/notification messages

#### Scenario: Server-rendered admin shell is English
- **WHEN** the admin shell (`resources/views/admin.blade.php` and any admin-related server messages surfaced in the admin UI) is rendered
- **THEN** titles, injected `window.settings` labels, and error/validation messages shown in the admin are in English

### Requirement: Admin UI strings are externalized to a locale table

The system SHALL hold all admin display strings in a checked-in, human-editable locale table that deterministically generates the shipped admin assets, rather than requiring display text to be edited directly in compiled or hand-maintained bundle code.

#### Scenario: Correcting a display string does not require editing bundle code
- **WHEN** an operator changes a string's English value in the locale table and regenerates the admin assets
- **THEN** the change appears in the dashboard with no edit to any bundle file outside the generated output

#### Scenario: Regeneration is deterministic and idempotent
- **WHEN** the admin assets are regenerated twice in a row from an unchanged locale table
- **THEN** the second run produces no change and cannot restore previously replaced text or stack edits

#### Scenario: Rewrites cannot reach non-display code
- **WHEN** a Chinese substring also occurs in a regex source, an identifier, or template syntax within a bundle
- **THEN** regeneration leaves that occurrence untouched, because rewrites are confined to string-literal spans

#### Scenario: Non-display CJK is explicitly accounted for
- **WHEN** a CJK literal survives regeneration because it is library plumbing rather than display text (a character-class fragment, an entity-table key, a glyph-measurement probe)
- **THEN** it is listed in an allowlist with a recorded reason, and any surviving literal not so listed is a build failure rather than a warning

### Requirement: English is the admin locale

The system SHALL render the admin dashboard in English for every operator, with the client and server agreeing on English so that a fresh install and any existing deployment both see English.

#### Scenario: Fresh install shows English admin
- **WHEN** a new installation is accessed at the admin path
- **THEN** the admin UI renders in English

#### Scenario: Library-supplied labels are English too
- **WHEN** the dashboard renders text that originates in a bundled third-party locale table rather than in admin-authored code (date-picker and chart toolbar labels)
- **THEN** that text is also generated from the locale table, so no Chinese leaks through library defaults and no second translation source exists for the same label

#### Scenario: Chinese remains reachable only as a rollback
- **WHEN** an operator needs the previous Chinese dashboard
- **THEN** it is available by restoring the retained pristine untranslated bundles, and no half-translated state is ever reachable at runtime

### Requirement: Admin assets are generated from the locale table and verified before publish

The system SHALL produce admin build artifacts (`public/assets/admin/`) from the locale table, and SHALL refuse to publish output that is incomplete, unparseable, or unverified, so that a deployed admin never ships half-translated.

#### Scenario: Generated assets reflect the locale table
- **WHEN** admin assets are regenerated after a change to the locale table
- **THEN** the emitted assets under `public/assets/admin/` contain the English strings from that table and no hard-coded Chinese display text

#### Scenario: An untranslated string blocks publication
- **WHEN** any display string in the bundles has no English value in the locale table
- **THEN** generation fails and reports which strings are unresolved, writing nothing to `public/assets/admin/`

#### Scenario: Output is verified before it is published
- **WHEN** generation produces staged assets
- **THEN** the staged bundles must re-parse as valid JavaScript and pass a runtime assertion against a headless DOM before being published, and any failure aborts without touching the live assets

#### Scenario: Cache-busting still works after translation changes
- **WHEN** the admin assets are regenerated after translation updates and deployed
- **THEN** clients receive the new assets (via the existing `?v={{$version}}` cache-busting or equivalent) without stale Chinese strings persisting from cache

### Requirement: Translations preserve the structural contract of the original string

The system SHALL validate that each English value preserves the non-linguistic structure its Chinese source carries, so a translation cannot silently break formatting, placeholders, or embedded technical identifiers.

#### Scenario: Placeholder and format tokens survive
- **WHEN** a source string contains printf-style tokens, brace placeholders, digit sequences, or is a date-format pattern
- **THEN** the English value must carry the equivalent tokens, and a mismatch is reported as an error rather than applied

#### Scenario: Padding and line structure survive
- **WHEN** a source string carries leading or trailing literal spaces, or newline/tab characters used for layout
- **THEN** the English value must preserve them, and a divergence is reported

#### Scenario: Embedded technical identifiers survive
- **WHEN** a source string embeds a URL, a host:port example, a config key, or a protocol name such as SNI, REALITY, BBR, or `geoip:cn`
- **THEN** that identifier must appear unchanged in the English value

### Requirement: Translation does not alter admin behavior or contracts

The system SHALL keep all admin functional behavior, API contracts, route structure (`secure_path`), permissions, and data shapes unchanged; the translation change is presentation-only.

#### Scenario: API contracts are unchanged
- **WHEN** admin API requests are made before and after the translation change
- **THEN** request/response shapes, status codes, and auth/permission behavior are identical

#### Scenario: Routes and access control are unchanged
- **WHEN** admin routes are accessed after the translation
- **THEN** path structure, `secure_path` handling, and authorization checks behave identically to before

### Requirement: Comprehensive admin surface is covered

The system SHALL ensure translation coverage includes every admin surface area, including lesser-tested states that are often missed: form validation messages, confirmation dialogs, pagination/empty states, error boundaries, and notification/toast messages triggered by async operations.

#### Scenario: Validation and error messages are English
- **WHEN** an admin submits invalid input or an API call fails (e.g., duplicate email, invalid plan, permission denied)
- **THEN** the inline validation text, dialog content, and toast/error messages are shown in English

#### Scenario: Confirmation and destructive-action dialogs are English
- **WHEN** an admin triggers a confirmation flow (delete, ban, reset, refund, etc.)
- **THEN** the dialog title, body, and confirm/cancel buttons are in English

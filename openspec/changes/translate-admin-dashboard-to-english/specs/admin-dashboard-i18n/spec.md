## Purpose

Provide a fully English admin dashboard with no remaining hard-coded Chinese strings, externalized through locale files so the UI is usable by English-speaking operators and future localization does not require code changes.

## ADDED Requirements

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

### Requirement: Admin UI strings are externalized via locale files

The system SHALL externalize all admin display strings behind an i18n lookup (locale/resource files) rather than inlining English literals throughout the codebase, so that strings are not re-hard-coded in a second language and future locales can be added without code changes.

#### Scenario: Adding a new locale does not require code edits to component files
- **WHEN** a new locale file is added for the admin dashboard and the active locale is switched
- **THEN** display strings resolve from locale resources without modifying component source files

#### Scenario: Missing translation falls back to English
- **WHEN** a translation key is missing in the active locale file
- **THEN** the system falls back to the English string for that key and does not render a raw key or blank text

#### Scenario: Locale files are the single source of truth for display strings
- **WHEN** the admin codebase is searched for user-visible literals outside locale files
- **THEN** no hard-coded display strings remain in component/template code (with narrow, documented exceptions such as brand names or technical identifiers)

### Requirement: English is the default admin locale

The system SHALL default the admin dashboard locale to English, with the server and client agreeing on the default so that a fresh install and any user without an explicit locale preference sees English.

#### Scenario: Fresh install shows English admin
- **WHEN** a new installation is accessed at the admin path without any locale preference set
- **THEN** the admin UI renders in English by default

#### Scenario: Locale preference is respected when explicitly set
- **WHEN** an admin user has an explicit locale preference (if the system supports per-user locale)
- **THEN** the admin UI renders in the preferred locale, falling back to English only for missing keys

### Requirement: Admin asset build includes translated strings

The system SHALL produce admin build artifacts (`public/assets/admin/`) that contain the translated/externalized strings, so that the deployed admin dashboard reflects the English locale without manual edits to compiled bundles.

#### Scenario: Production build reflects translations
- **WHEN** the admin frontend is built for production after string externalization
- **THEN** the emitted assets under `public/assets/admin/` contain English strings from locale resources and no hard-coded Chinese display text

#### Scenario: Cache-busting still works after translation changes
- **WHEN** the admin assets are rebuilt after translation updates and deployed
- **THEN** clients receive the new assets (via the existing `?v={{$version}}` cache-busting or equivalent) without stale Chinese strings persisting from cache

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

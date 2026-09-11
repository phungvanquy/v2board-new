## Purpose

Provide automated code-quality guardrails (formatting, linting, and static analysis) with one-command local fixing and CI enforcement so that style, duplication, and type-safety regressions are caught before merge without relying on manual review.

## ADDED Requirements

### Requirement: Automated PHP formatting

The system SHALL provide a deterministic PHP formatter and a one-command way to check and to fix formatting across `app/`, `config/`, `database/`, and `tests/`.

#### Scenario: Developer checks formatting locally

- **WHEN** a developer runs the documented check command (e.g., `composer lint` or `composer cs:check`) on a clean checkout
- **THEN** the command exits 0 without modifying files

#### Scenario: Formatter fixes violations

- **WHEN** a developer runs the documented fix command (e.g., `composer cs:fix`) after introducing a style violation
- **THEN** the violation is corrected on disk and a subsequent check exits 0

#### Scenario: CI fails on style violation

- **WHEN** a pull request contains a PHP style violation and CI runs the quality workflow
- **THEN** the workflow fails with a non-zero exit and surfaces the offending files/lines

### Requirement: Static analysis baseline

The system SHALL provide a PHP static-analysis configuration (PHPStan or equivalent) with a checked-in baseline so that the current codebase passes at the chosen level and new type errors are surfaced.

#### Scenario: Analysis passes on main

- **WHEN** the analysis command (e.g., `composer analyse`) is run on the default branch
- **THEN** it exits 0 (either clean or with only baseline-ignored legacy findings)

#### Scenario: New type error is flagged

- **WHEN** a change introduces a type error covered by the configured level (e.g., wrong return type, undefined method, impossible null dereference)
- **THEN** analysis fails and reports the error location

#### Scenario: Baseline is versioned

- **WHEN** a reviewer inspects the repository
- **THEN** the analysis config and baseline file are checked in at-repo-root and referenced by CI

### Requirement: Admin frontend linting

The system SHALL provide linting/formatting for the admin frontend assets so that JS/TS/Vue style is enforced consistently.

#### Scenario: Frontend lint check in CI

- **WHEN** CI runs the frontend quality step
- **THEN** it lints the admin frontend sources and fails on violations

#### Scenario: Frontend fix command

- **WHEN** a developer runs the documented frontend fix command
- **THEN** fixable violations are corrected on disk

### Requirement: Documented quality workflow

The system SHALL document the quality commands in `README.md` or `CONTRIBUTING.md` and wire them into a CI workflow that runs on pull requests and pushes to the default branch.

#### Scenario: Contributor finds how to run checks

- **WHEN** a new contributor reads the contributing guide
- **THEN** they can discover the exact commands to check and fix code quality locally without asking a maintainer

#### Scenario: PR quality gate is enforced

- **WHEN** a pull request is opened or updated
- **THEN** CI runs formatting + static analysis (+ frontend lint if applicable) and blocks merge on failure per repository branch protection

### Requirement: Behaviour-preserving cleanup

Structural clean-ups performed under this change (dead-code removal, duplication reduction, decomposition of oversized classes) SHALL preserve externally observable behaviour: HTTP routes, response shapes, database schema, queue jobs, and scheduled tasks remain compatible with the current release.

#### Scenario: Existing tests and smoke checks still pass

- **WHEN** the cleanup is applied and the existing test suite plus the Docker smoke verification (`GET /`, `/healthz`, Horizon probe) are run
- **THEN** all checks pass and no route or payload contract has changed

#### Scenario: No silent contract change

- **WHEN** a reviewer diffs the change for a known API endpoint or job queue name
- **THEN** the endpoint path, request validation rules, response keys, queue names, and Horizon supervisor config are unchanged unless explicitly called out and approved as a follow-up change

### Requirement: Test gate in quality workflow

The quality workflow SHALL run the automated test suite as a required check alongside formatting and static analysis, using the documented test command.

#### Scenario: Test command is documented

- **WHEN** a contributor reads the contributing guide
- **THEN** they can discover the exact command to run the full test suite locally (e.g., `composer test` or `vendor/bin/phpunit`)

#### Scenario: CI blocks on test failure

- **WHEN** a pull request contains a failing test and CI runs the quality workflow
- **THEN** the workflow fails and blocks merge per branch protection

#### Scenario: Coverage is measurable locally

- **WHEN** a developer runs the documented coverage command (e.g., `composer test:coverage`)
- **THEN** a coverage report is generated for `app/` without requiring production data

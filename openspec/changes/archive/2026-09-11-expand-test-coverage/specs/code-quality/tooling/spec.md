## ADDED Requirements

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

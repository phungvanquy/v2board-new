## Purpose

Increase confidence in changes and catch regressions automatically by establishing automated unit, service, and feature tests with reproducible fixtures and CI enforcement.

## ADDED Requirements

### Requirement: Test organization and execution

The system SHALL provide a documented test organization with clearly separated suites (Unit, Feature) and a single command to run the full suite locally and in CI.

#### Scenario: Developer runs full suite locally

- **WHEN** a developer runs the documented test command (e.g., `composer test` or `vendor/bin/phpunit`) on a clean checkout with dependencies installed
- **THEN** the suite executes and reports pass/fail per test with a non-zero exit on any failure

#### Scenario: CI runs tests on PRs

- **WHEN** a pull request is opened or updated
- **THEN** CI executes the test suite as a required check and blocks merge on failure

#### Scenario: Suites are separated by speed and scope

- **WHEN** a developer runs only the Unit suite
- **THEN** no database, network, or queue is required and the suite completes quickly (seconds), while the Feature suite may use a test database and fakes

### Requirement: Unit tests for pure helpers and protocol formatters

The system SHALL have unit tests for stateless helpers and protocol formatters that verify their observable output without I/O.

#### Scenario: TrafficHelper formatting

- **WHEN** `TrafficHelper::trafficConvert` is called with representative byte values (bytes, KB, MB, GB, negative)
- **THEN** it returns the expected human-readable string or integer per existing formatting rules

#### Scenario: CryptoHelper determinism and shape

- **WHEN** `CryptoHelper::guid`, `uuidToBase64`, `randomChar`, `randomPort`, and `getServerKey` are exercised with valid inputs and boundary values
- **THEN** outputs have the expected length/format and invalid inputs are handled as documented (e.g., exception or validated fallback)

#### Scenario: Protocol formatter snapshots

- **WHEN** a protocol formatter (Clash variants, Singbox, Shadowsocks, etc.) handles a canonical server payload
- **THEN** the output matches the snapshots/parity expectations already established (e.g., `ProtocolSnapshotTest` parity) and regressions are detected

#### Scenario: CacheKey validation

- **WHEN** `CacheKey::get` is called with an allowed and a disallowed key
- **THEN** the allowed key returns the expected composite string and the disallowed key triggers the documented error

### Requirement: Service logic tests

The system SHALL have tests for critical service logic that can run without external I/O by using fakes/mocks for cache, mail, and queue.

#### Scenario: OrderService lifecycle

- **WHEN** an order is created, paid, or cancelled through `OrderService` with stubbed payment and cache dependencies
- **THEN** the service transitions state correctly and invokes the expected side effects (or their fakes) without hitting a real gateway

#### Scenario: UserService availability and reset day

- **WHEN** `UserService::isAvailable`, `getResetDay`, and related reset-period logic are called with users covering expired, banned, and various billing cycles
- **THEN** they return the expected availability and reset-day values per business rules

#### Scenario: ServerService subscription generation

- **WHEN** `ServerService` assembles subscription data for a user with mixed node types
- **THEN** it returns the expected server list and protocol payloads matching the formatter contracts

### Requirement: Feature and HTTP tests for critical flows

The system SHALL have feature/HTTP tests for the most critical user-facing flows, exercising routes through the HTTP layer with a test database.

#### Scenario: Auth flow

- **WHEN** a client registers and logs in via the auth endpoints with valid and invalid credentials
- **THEN** the API returns the expected status codes, response shapes, and authentication artifacts (token/session), and invalid attempts return the documented error

#### Scenario: User and order flows

- **WHEN** an authenticated user fetches their info, lists plans, and creates an order
- **THEN** responses contain the expected fields and the order is persisted with correct pricing

#### Scenario: Subscription and ticket flows

- **WHEN** a user fetches their subscription and creates/lists tickets
- **THEN** the endpoints return the expected content (filtered by ownership) and unauthorized access is rejected

#### Scenario: Admin flows

- **WHEN** an admin user accesses admin endpoints (user management, config) with and without admin privileges
- **THEN** authorized requests succeed with expected payloads and unauthorized requests are rejected with the documented error

### Requirement: Test data factories and fixtures

The system SHALL provide factories or helper builders for core models (`User`, `Plan`, `Order`, `Server`, etc.) so tests can create deterministic, isolated data without relying on production dumps.

#### Scenario: Factory creates valid models

- **WHEN** a test creates a user, plan, or order via the factory/helper with defaults and with overrides
- **THEN** the persisted (or in-memory) model satisfies model validation and has the overridden attributes applied

#### Scenario: Tests remain isolated

- **WHEN** two tests each create data via factories and run in sequence
- **THEN** neither test sees the other's data (transactional isolation or fresh database per test)

### Requirement: Coverage visibility and threshold

The system SHALL provide a way to measure test coverage and surface it locally (and optionally in CI), with an initial non-blocking threshold that can be tightened over time.

#### Scenario: Developer measures coverage locally

- **WHEN** a developer runs the documented coverage command (e.g., `composer test:coverage`)
- **THEN** a coverage report is generated (text and/or HTML) showing line/branch coverage for `app/`

#### Scenario: Coverage does not regress silently

- **WHEN** CI runs on a PR that removes tests or drops coverage significantly
- **THEN** the coverage result is visible in the PR (as an artifact, comment, or check output), and a configured threshold — if enabled — fails the workflow

### Requirement: Exception and validation behavior

The system SHALL have tests that document the contract of `ApiException` and request validation, ensuring error responses are consistent.

#### Scenario: ApiException helpers

- **WHEN** `ApiException` factory methods (`fail`, `badRequest`, `forbidden`, `notFound`, `abortIf`, `abortIfNull`) are called with representative arguments
- **THEN** they throw with the expected HTTP status and message, and `abortIf`/`abortIfNull` only throw when the condition holds

#### Scenario: Request validation

- **WHEN** an endpoint is called with invalid input that violates its FormRequest rules
- **THEN** the response is a validation error with the documented status and error shape, not an unhandled exception

## ADDED Requirements

### Requirement: Eloquent loading is explicit
Queries SHALL eager-load relationships used during serialization or iteration,
use constrained aggregates where appropriate and enable strict lazy-loading
detection in development and tests.

#### Scenario: Collection serialization
- **WHEN** a service serializes a collection using related models
- **THEN** the query loads the required relationships or aggregates before iteration and executes without lazy-loading violations

### Requirement: Large datasets are processed with bounded memory
Commands, jobs and services that can traverse tenant-wide or platform-wide
datasets SHALL NOT materialize an unbounded result set. Complete read-only scans
SHALL use explicit continuation with `lazy`, `cursor` or equivalent pagination;
mutable scans SHALL use `chunkById` or stable claims. A bounded claim or batch
limit SHALL expose resumable continuation or backlog evidence when the workflow
is expected to process the complete eligible set.

#### Scenario: Mutable scheduled scan
- **WHEN** a scheduled command updates records selected by a changing predicate
- **THEN** it uses `chunkById` or an equivalent stable claim strategy and processes every eligible row at most once per run

### Requirement: Migrations and factories are forward-safe
Merged migrations SHALL remain immutable. Every new concrete persisted model
SHALL have a migration and a factory, and the canonicalization SHALL add missing
factories required to construct realistic domain graphs in tests. New schema
changes SHALL use reversible PostgreSQL-compatible migrations with tenant-aware
keys and indexes.

#### Scenario: Existing schema needs a new column
- **WHEN** an implemented fix requires changing a table already represented by a merged migration
- **THEN** a new reversible migration is added instead of editing the historical migration

#### Scenario: Concrete model is introduced
- **WHEN** a new concrete Eloquent model is added
- **THEN** its migration, factory states and focused persistence test are added in the same change

### Requirement: Cache state is scoped and invalidated
Cache keys SHALL include every correctness dimension, including tenant and
environment where applicable. Cached reads SHALL define TTL and explicit
invalidation, and exclusive work SHALL use Laravel cache locks.

#### Scenario: Tenant-scoped cached query
- **WHEN** two tenants request the same logical resource identifier
- **THEN** their cache entries cannot collide and a write invalidates only the affected tenant scope

### Requirement: Multi-write workflows are atomic
Related writes SHALL run in a transaction. Input-independent prechecks MAY occur
before it, but every invariant that depends on mutable state SHALL be rechecked
inside the transaction under the applicable row lock or enforced by a database
constraint.
Externally visible jobs and events SHALL be dispatched after commit and be
idempotent on retry.

#### Scenario: Transaction rolls back
- **WHEN** a multi-write workflow throws before commit
- **THEN** no partial state remains and no job, broadcast or external side-effect observes the rolled-back change

#### Scenario: Concurrent one-time claim
- **WHEN** two requests attempt to consume the same one-time capability
- **THEN** an atomic condition or row lock grants it to exactly one request and the loser receives the same safe failure envelope

#### Scenario: Fiscal mutation capability
- **WHEN** fiscal mutation execution omits or presents a different preflight capability or omits its idempotency key
- **THEN** execution is rejected before terminal replay or external transport and no replacement preflight is created implicitly

### Requirement: Tenant isolation remains fail-closed
Global-scope bypasses SHALL require an explicit typed privileged context or a
query constrained by trusted tenant identity. No HTTP input SHALL directly
select privileged tenant scope.

#### Scenario: Scope bypass review
- **WHEN** application code calls `withoutGlobalScopes` or removes the tenant scope
- **THEN** the call is constrained by trusted identifiers or executed inside an audited privileged context and has an isolation test

#### Scenario: Tenant credential reference
- **WHEN** a tenant-scoped record references a shared credential identifier
- **THEN** a composite database constraint requires both records to belong to the same tenant while preserving valid historical credential rows

#### Scenario: Client credential ownership
- **WHEN** a client credential is persisted for a tenant and client
- **THEN** a composite database constraint requires the client to belong to that tenant and deleting the client removes only its credential history

## ADDED Requirements

### Requirement: PostgreSQL-only fresh installation
The API MUST build its complete application schema by running versioned Laravel
PHP migrations against an empty PostgreSQL 17 database.

#### Scenario: Fresh canonical database
- **WHEN** `migrate:fresh --seed` runs against the isolated test connection
- **THEN** every canonical table, constraint, index, and reference row is created

#### Scenario: Unsupported driver
- **WHEN** application or test configuration is inspected
- **THEN** no SQLite runtime, PHPUnit override, migration branch, or schema dump is present

### Requirement: Reversible migration tree
Every migration MUST have a deterministic reverse operation and MUST avoid
application models, runtime-dependent values, and reference-data writes.

#### Scenario: Full rollback
- **WHEN** the complete migration batch is rolled back
- **THEN** all application tables and PostgreSQL-only objects created by the batch are removed

#### Scenario: Individual rollback
- **WHEN** migrations run with `--step` and the latest step is rolled back
- **THEN** only that migration's schema changes are reversed

### Requirement: Canonical tenant integrity
All tenant-owned data MUST use `tenant_id` and MUST be prevented from referencing
a parent owned by another tenant.

#### Scenario: Cross-tenant relationship
- **WHEN** a child row references a parent id from a different tenant
- **THEN** PostgreSQL rejects the write at the foreign-key boundary

#### Scenario: Missing tenant context
- **WHEN** a tenant-scoped Eloquent query runs without `CurrentTenant`
- **THEN** it fails closed and returns no cross-tenant data

### Requirement: Canonical reference data
Required catalogs and permissions MUST be installed by an idempotent
`ReferenceDataSeeder`, separate from structural migrations and demo data.

#### Scenario: Repeated canonical seed
- **WHEN** the reference seeder runs twice
- **THEN** the second execution changes no identities and creates no duplicates

#### Scenario: Production-safe seed
- **WHEN** only the reference seeder runs in a non-local environment
- **THEN** it creates no tenant, user, credential, token, or demo record

### Requirement: No product compatibility layer
The application MUST NOT read, write, normalize, redirect, or expose an earlier
KontiveHub schema or contract.

#### Scenario: Canonical source audit
- **WHEN** the compatibility inventory gate scans application-owned source
- **THEN** no legacy field, deprecated product API, backfill, cutover, dual storage, or redirect-only route remains

#### Scenario: Current resilience
- **WHEN** a current provider or network operation fails
- **THEN** a current operational fallback may run only if it does not interpret an old contract

### Requirement: Canonical public contract
The Laravel API and Nuxt SPA MUST share a versioned public OpenAPI contract using
only canonical tenant and domain names.

#### Scenario: Generated client types
- **WHEN** the API type generation gate runs
- **THEN** the checked-in Nuxt types match the public OpenAPI document

#### Scenario: Canonical route inventory
- **WHEN** route and page inventories are generated
- **THEN** no old API alias, Nuxt redirect page, or migration middleware is present

### Requirement: Canonical private Wazync contract
Laravel and Wazync MUST exchange only typed messages and strict canonical session
states.

#### Scenario: Typed message
- **WHEN** Laravel submits a message command
- **THEN** its payload contains `kind` and validates against exactly one typed schema

#### Scenario: Invalid historical status
- **WHEN** Wazync encounters a status outside `DISCONNECTED`, `CONNECTING`, or `CONNECTED`
- **THEN** it rejects the value instead of normalizing it

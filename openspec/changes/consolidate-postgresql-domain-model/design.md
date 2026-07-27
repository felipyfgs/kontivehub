## Context

The API currently has 166 migration files, 235 created tables, widespread
`office_id` ownership, PostgreSQL/SQLite branches, reference-data writes inside
migrations, and several in-progress cutovers. The public API and Nuxt SPA also
carry renamed fields and redirect-only routes. Wazync accepts both typed
messages and the original untyped envelope.

No data must survive. The only supported installation is a new PostgreSQL 17
database built from the canonical tree in this change.

## Goals / Non-Goals

**Goals:**

- Make the final domain model explicit and deterministic.
- Use conventional Laravel migrations and directories.
- Remove all product-owned compatibility behavior, including Wazync.
- Preserve active business capabilities while deleting transitional machinery.
- Make PostgreSQL the test database so production constraints are exercised.

**Non-Goals:**

- Upgrade, backfill, import, or preserve an existing database.
- Preserve an old route, payload, status, environment key, or redirect.
- Move fiscal business logic into Wazync.
- Replace current runtime fallbacks that handle present-day network/provider
  failures and do not interpret an old contract.

## Decisions

### Laravel migrations are the schema source

The migration tree contains anonymous classes ordered by timestamp. The normal
unit is `create_<plural_table>_table`, one table per file. A small number of
`add_*_constraints` migrations may resolve circular dependencies. There are no
schema dumps or generated SQL baselines.

Migrations contain only schema. `ReferenceDataSeeder` owns required catalogs and
is safe to run repeatedly. Demo seeders remain environment-gated.

### PostgreSQL is the sole application driver

Migrations use Laravel's PostgreSQL-capable schema methods (`id`, `foreignId`,
`jsonb`, `timestampsTz`, `decimal`, indexes, and explicit foreign actions).
SQLite connections, PHPUnit overrides, driver branches, and SQLite-specific
tests are removed. Tests use an isolated PostgreSQL 17 service.

Raw SQL is limited to named PostgreSQL checks or partial indexes that the schema
builder cannot express, with exact `down()` reversals.

### Tenant is the canonical aggregate

Code and persistence use `Tenant`, `tenants`, `tenant_id`,
`TenantMembership`, `tenant_memberships`, `CurrentTenant`, and
`BelongsToTenant`. UI copy remains "Escritório".

Every tenant-owned row has a non-null tenant key. Child/parent relationships
that can otherwise cross tenants use composite foreign keys backed by
`(tenant_id, id)` uniqueness.

Membership roles are only `tenant_admin` and `tenant_user`.
`tenant_user` requires a permission profile; `tenant_admin` forbids one.
Platform authorization remains a separate `platform_admin` membership.

### Domain naming is canonical on first install

- A `Client` represents one CNPJ root per tenant.
- An `Establishment` represents each registered unit and uses
  `is_headquarters`; client self-linkage is removed.
- Work models and tables use `Work*` / `work_*`.
- Singular or ambiguous tables are renamed to their semantic plural names.
- SERPRO uses `serpro_operations` and versioned coordinates; compatibility
  catalogs are removed.
- Tenant and client signing material is exposed as a `certificate`; certificate
  subtype names are not part of the domain or public API. PFX remains an
  implementation detail accepted only at upload and materialization boundaries.

### The contract cutover is atomic

`/api/v1` remains the URI version but its content is replaced in place.
Requests and responses use only canonical fields. API Resources/Collections and
Form Requests define the shape. A public OpenAPI contract generates the Nuxt
TypeScript contract; handwritten duplicate DTOs are removed.

Nuxt keeps only canonical file-based routes. Redirect-only pages and middleware
are deleted.

Wazync accepts only typed message payloads with `kind`, strict canonical session
states, and context-aware connect. The private OpenAPI and both consumers change
together.

### Compatibility removal is semantic

Delete code whose purpose is reading, writing, transforming, redirecting, or
describing a prior KontiveHub contract. This includes `legacy`, deprecated
product APIs, backfills, cutovers, dual storage, compatibility casts, and old
test fixtures.

Technical fallbacks remain only when they respond to a current failure without
interpreting historical data. Framework deprecation logging also remains.

## Risks / Trade-offs

- **The cutover cannot upgrade an existing database** → accepted; provisioning
  destroys and recreates the database.
- **A feature accidentally disappears with its adapter** → inventory every
  compatibility path and keep the canonical service/test for the capability.
- **Laravel and Wazync versions are mismatched** → deploy and test the private
  contract atomically; there is no mixed-version window.
- **PostgreSQL tests are slower than SQLite** → use a dedicated ephemeral
  service and transactional test isolation.

## Delivery

1. Replace the OpenSpec artifacts and establish deletion/naming inventories.
2. Create the canonical migration and reference seeder tree.
3. Rename the Laravel tenant aggregate and remove transition machinery.
4. Cut API and Nuxt to canonical routes and generated types.
5. Cut the private Wazync contract and Go implementation.
6. Validate fresh install, rollback, contracts, and all app gates.

## Open Questions

None. The user explicitly selected a destructive cut with Wazync included.

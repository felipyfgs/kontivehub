## Why

KontiveHub is still pre-release and its databases are explicitly disposable.
The current application nevertheless carries a large transitional history:
PostgreSQL/SQLite branches, compatibility columns, backfills, dual-read/write
authorization, deprecated API aliases, redirect-only Nuxt routes, and a legacy
Laravel-Wazync message envelope. Preserving those paths would make the first
supported schema and contract needlessly ambiguous.

The project needs one clean PostgreSQL domain model expressed by normal Laravel
migrations and one coordinated canonical contract across Laravel, Nuxt, and
Wazync.

## What Changes

- Replace the historical API migration chain with reversible Laravel PHP
  migrations, normally one table per migration, targeting PostgreSQL 17 only.
- Rename the tenant aggregate from `Office` to `Tenant` across schema, PHP,
  public API, generated TypeScript, and application code while keeping
  "Escritório" as pt-BR product copy.
- Remove all application-owned compatibility paths: legacy columns and enum
  cases, backfill/cutover services, aliases, redirects, deprecated DTO members,
  dual-read/write behavior, and old environment keys.
- Separate structural migrations from idempotent canonical reference seeders.
- Replace `/api/v1` in place and update the Nuxt SPA atomically; no compatibility
  version or deprecation window is provided.
- Remove the untyped Laravel-Wazync message envelope and require the typed
  private contract on both consumers.
- Run all database tests against an isolated PostgreSQL 17 test database.

This is an intentionally breaking re-baseline. No existing database, payload,
URL, or persisted transitional value is supported after the cutover.

## Capabilities

### New Capabilities

- `canonical-postgresql-domain-model`: deterministic fresh installation from
  Laravel migrations and canonical reference seeders.
- `canonical-contract-cutover`: one contract vocabulary across API, SPA, and
  Wazync without compatibility aliases.

### Modified Capabilities

- `database-schema-baseline`: migrations remain the source of truth and only
  PostgreSQL is supported.
- `multitenant-authorization`: `Tenant` and canonical membership roles replace
  Office-era dual storage.
- `wazync-private-contract`: typed message payloads and strict session states
  replace compatibility normalization.

## Impact

- API migrations, models, factories, seeders, configuration, routes, services,
  commands, policies, resources, tests, and public OpenAPI.
- Nuxt routes, middleware, composables, types, navigation, and tests.
- Wazync private OpenAPI, Go domain/worker/protocol code, and tests.
- Docker Compose, environment examples, Make targets, and quality gates.

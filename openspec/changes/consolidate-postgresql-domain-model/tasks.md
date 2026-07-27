## 1. Specify the canonical cut

- [x] 1.1 Replace the rejected dump/SQLite change with the PostgreSQL-only domain model change
- [x] 1.2 Record the canonical table/name map and compatibility deletion inventory
- [x] 1.3 Validate the replacement OpenSpec artifacts

## 2. Rebuild persistence

- [x] 2.1 Replace historical migrations with reversible Laravel migrations, normally one table per file
- [x] 2.2 Move required catalogs and permissions into an idempotent `ReferenceDataSeeder`
- [x] 2.3 Configure an isolated PostgreSQL 17 test database and remove SQLite support
- [x] 2.4 Update factories and schema tests for canonical tenant relationships

## 3. Cut Laravel to the canonical model

- [x] 3.1 Rename Office persistence and runtime context to Tenant
- [x] 3.2 Consolidate membership/platform authorization and remove dual storage
- [x] 3.3 Remove backfill, cutover, deprecated DTO, compatibility cast, and schema fallback code
- [x] 3.4 Rename canonical client, establishment, Work, fiscal, document, vault, and SERPRO structures
- [x] 3.5 Update policies, jobs, events, services, resources, routes, and tests

## 4. Cut public API and Nuxt

- [x] 4.1 Add and validate the public API v1 OpenAPI contract
- [x] 4.2 Generate Nuxt API types and remove handwritten compatibility aliases
- [x] 4.3 Remove redirect-only pages/middleware and update canonical navigation
- [x] 4.4 Run all Nuxt gates and update contract/page tests

## 5. Cut the Wazync contract

- [x] 5.1 Remove the untyped message payload and require typed message kinds
- [x] 5.2 Remove command, transport, connect, status, environment, and schema compatibility
- [x] 5.3 Update private OpenAPI, Laravel consumer, Go implementation, and tests atomically

## 6. Validate the clean baseline

- [x] 6.1 Pass fresh seed, pretend, step rollback, full rollback, and remigrate checks on PostgreSQL
- [x] 6.2 Pass Composer validation, Pint, and the complete Laravel suite
- [x] 6.3 Pass all Web and Wazync gates
- [x] 6.4 Audit application source for remaining product compatibility and review the final diff

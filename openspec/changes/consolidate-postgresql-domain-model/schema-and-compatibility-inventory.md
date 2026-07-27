# Canonical schema and compatibility inventory

## Naming map

| Previous | Canonical |
| --- | --- |
| `Office`, `offices`, `office_id` | `Tenant`, `tenants`, `tenant_id` |
| `OfficeMembership`, `office_user` | `TenantMembership`, `tenant_memberships` |
| `CurrentOffice`, `BelongsToOffice` | `CurrentTenant`, `BelongsToTenant` |
| `office_*` tables and symbols | corresponding `tenant_*` names |
| `operational_*` tables/models | corresponding `work_*` names |
| `mit_apuracoes` | `mit_assessments` |
| `exports` | `document_exports` |
| `fiscal_document_quarantine` | `fiscal_document_quarantines` |
| `vault_object_journal` | `vault_object_journal_entries` |
| `is_matrix` | `is_headquarters` |

## Canonical structures

- `Client` is unique by `(tenant_id, root_cnpj)`.
- `Establishment` owns CNPJ units; `Client::matrix_client_id` does not exist.
- `tenant_memberships.role` is `tenant_admin` or `tenant_user`.
- `tenant_user` requires a tenant-owned permission profile.
- `platform_memberships.role` is independent and currently supports
  `platform_admin`.
- `serpro_operations` and `serpro_operation_versions` are the operation
  catalog; the compatibility catalog does not exist.
- Work persistence uses the `work_*` prefix consistently.

## Delete as compatibility

- All backfill, collapse, cutover, reconciliation-for-migration, remap, and
  transition commands/services/tables.
- All `legacy_*`, `*_legacy`, `LEGACY`, deprecated product DTO fields, enum
  cases, permission keys, profiles, and error codes.
- All dual-read/write and compatibility casts/adapters.
- All runtime schema-presence branches whose purpose is accepting an older
  database shape.
- All public request/query/response aliases and old routes.
- All Nuxt redirect-only pages, route migration middleware, legacy path maps,
  and deprecated type aliases.
- Wazync untyped message payload, command aliases, old transport interface,
  status normalization, old environment aliases, and obsolete protocol catalog
  entries.

## Keep as current behavior

- Fail-closed provider kill switches and capability gates.
- Retry, circuit-breaker, filename, cache, and current-provider fallback logic
  that does not consume an old KontiveHub contract.
- Framework/library deprecation logging.
- Backup/preflight schema inspection that validates only the current canonical
  schema and never selects an old branch.

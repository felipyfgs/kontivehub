## ADDED Requirements

### Requirement: Requests validate and authorize at the boundary
Every state-changing or filter-bearing HTTP endpoint SHALL use a dedicated Form
Request for declared input and boundary authorization, or an explicitly
documented boundary validator when protocol ordering makes a Form Request
unsafe. Tenant identity SHALL come from `CurrentTenant` and authenticated
membership rather than an arbitrary `tenant_id` field.

#### Scenario: Valid application request
- **WHEN** an authenticated client submits a declared payload to `/api/v1`
- **THEN** a Form Request authorizes the actor, rejects invalid fields and passes only validated data to the controller

#### Scenario: Signed internal request
- **WHEN** an internal endpoint must verify HMAC over the raw body before parsing it
- **THEN** the endpoint verifies the raw-body signature first, rejects stale or replayed timestamp/nonce evidence within a bounded window, applies idempotency or one-time consumption to state changes and only then delegates schema validation to a dedicated tested validator without trusting tenant input

### Requirement: Controllers only orchestrate
Controllers SHALL coordinate Request, Policy, Action or Service, DTO and
Resource boundaries and SHALL NOT own reusable business workflows, multi-write
transactions, external clients or model serialization rules.

#### Scenario: Complex mutation
- **WHEN** an endpoint performs a workflow with business rules or multiple writes
- **THEN** the controller invokes a focused Action or Service with a DTO and contains only transport orchestration

### Requirement: Responses use stable transport representations
Model-backed API responses SHALL use Laravel Resources or versioned
DTO/transformers. Paginated responses SHALL preserve Laravel pagination metadata
and links, and transport fields SHALL NOT be implemented as general-purpose
Eloquent serialization methods.

#### Scenario: Paginated collection
- **WHEN** an endpoint returns a paginated model collection
- **THEN** a Resource collection preserves the existing item fields, pagination metadata and navigation links

#### Scenario: Additive contract evolution
- **WHEN** a response gains a new optional field
- **THEN** the change is additive, version-aware and protected by a compatibility test for the prior response shape

### Requirement: Authorization is policy-driven and fail-closed
Per-model access SHALL be enforced by Policies or Gates and cross-cutting route
access SHALL use explicit middleware. Denied, missing-membership and cross-tenant
paths SHALL be covered by tests.

#### Scenario: Cross-tenant identifier
- **WHEN** an authenticated tenant member references a model owned by another tenant
- **THEN** policy and tenant scope enforcement deny access without fabricating membership or leaking model existence

### Requirement: Rate limits are named and client-visible
Public, authenticated and internal endpoint classes SHALL use named Laravel rate
limiters scoped by the appropriate user, tenant, integration key or IP. A
limited request SHALL return standard limit headers and `Retry-After`, and
limiter state SHALL expire with the declared budget window.

#### Scenario: Specialized mutation limit
- **WHEN** a caller exceeds the configured limiter for a sensitive mutation
- **THEN** the API returns `429` with standard limit headers and `Retry-After` without affecting unrelated endpoint budgets

#### Scenario: Integration token budget
- **WHEN** one CT-e integration token calls from different IPs or different tokens share one IP
- **THEN** the configured integration budget follows a keyed HMAC-SHA-256 of the case-sensitive normalized Bearer, uses a stable secret key with controlled rotation, derives client IP only through the trusted-proxy chain and preserves the historical aggregate IP ceiling without exposing credentials

### Requirement: Request forgery protection remains explicit
Browser state-changing flows SHALL retain Laravel 13 request-forgery protection.
Only non-browser integration routes MAY be excluded, and every exclusion SHALL
verify an equivalent signature or capability.

#### Scenario: Browser mutation without valid CSRF context
- **WHEN** a stateful SPA request lacks valid request-forgery evidence
- **THEN** Laravel rejects it without globally disabling protection

### Requirement: API exceptions have stable safe envelopes
Expected domain failures SHALL use typed exceptions rendered centrally to stable
HTTP status and error codes. Responses SHALL NOT expose arbitrary upstream or
exception messages.

#### Scenario: Expected domain rejection
- **WHEN** a domain rule rejects an otherwise valid request
- **THEN** the exception renderer returns the documented status, safe message and stable code while preserving sanitized diagnostic context

## ADDED Requirements

### Requirement: External systems use ports and adapters
Domain workflows SHALL depend on application-owned interfaces and DTOs.
Provider SDK, cURL and Laravel HTTP details SHALL remain inside configured
adapters selected at the composition edge.

#### Scenario: Provider replacement
- **WHEN** an external provider implementation changes
- **THEN** domain services and controllers continue using the same port and portable DTO contract

### Requirement: HTTP egress is bounded and observable
Every outbound client SHALL configure connection and total timeouts, retry only
transient and idempotent failures with bounded backoff, classify unsuccessful
responses explicitly and sanitize diagnostic context.

#### Scenario: Non-retryable provider rejection
- **WHEN** an upstream returns a permanent client or contract error
- **THEN** the adapter does not retry it and returns a typed, sanitized failure to the caller

#### Scenario: Transient provider failure
- **WHEN** an idempotent request receives a retryable timeout or transient status
- **THEN** the adapter performs only the configured bounded retries and records low-cardinality outcome metrics

### Requirement: Jobs have explicit execution policies
Queued jobs SHALL be routed to named queues and define appropriate tries,
backoff, timeout, idempotency and failure behavior. Horizon tags SHALL use opaque
domain identifiers and SHALL NOT contain sensitive payloads.

#### Scenario: Job exhausts retries
- **WHEN** a job reaches its terminal attempt
- **THEN** it records a sanitized actionable failure, releases owned resources and remains visible in failed-job and Horizon telemetry

### Requirement: Horizon exposes actionable health
Queue telemetry SHALL expose throughput, runtime, retries, failures and backlog
against explicit thresholds for critical queue classes.

#### Scenario: Critical backlog breaches SLA
- **WHEN** a named critical queue exceeds its configured depth or age threshold
- **THEN** readiness or operations telemetry reports a degraded state with the queue name and safe remediation context

### Requirement: Scheduled work is coordinated
Scheduled commands that can overlap SHALL use `withoutOverlapping` with signal
release, and commands that must be singleton across replicas SHALL use
`onOneServer`. Risky or external work SHALL remain gated by configuration.

#### Scenario: Two scheduler replicas tick together
- **WHEN** two replicas evaluate the same singleton schedule at the same minute
- **THEN** only one execution acquires the schedule and the other exits without duplicating work

### Requirement: Logs and metrics are sanitized and actionable
All application logging SHALL use structured context passed through the shared
sanitizer. Free text SHALL redact credentials, authorization material, fiscal
identifiers and payload bodies before truncation; metric labels SHALL remain
allowlisted and low-cardinality.

#### Scenario: Upstream embeds a secret in an exception
- **WHEN** an upstream exception contains a short token, credential pair, CNPJ or fiscal payload
- **THEN** logs, failed-job context, persisted operational errors and metrics omit or redact the sensitive value

### Requirement: Retries terminate and poison work is quarantined
Retry state machines SHALL exclude terminal records from normal dispatch and
provide an explicit audited recovery path. A poison item SHALL not create an
unbounded request or log loop.

#### Scenario: Delivery reaches the maximum attempts
- **WHEN** a gateway or queue delivery reaches its configured maximum attempts
- **THEN** it enters a terminal error state that normal dispatch queries do not select and telemetry reports the quarantined backlog

### Requirement: Quality evidence uses project-native gates
Canonical architecture requirements SHALL be proven through the existing
PHPUnit, contract, artifact and static-analysis gates. Application and tooling
code SHALL NOT depend on the external review references used to guide this
change.

#### Scenario: Quality gates run
- **WHEN** the API quality gates execute
- **THEN** regressions fail through project-native tests and no stale open finding is marked planned against a nonexistent change

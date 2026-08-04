## ADDED Requirements

### Requirement: Gateway events use an acknowledged durable queue
Wazync SHALL publish its existing PostgreSQL outbox events to a configured NATS JetStream subject and SHALL mark an outbox event delivered only after a publish acknowledgement.

#### Scenario: NATS is temporarily unavailable
- **WHEN** publishing an event fails or times out
- **THEN** the event remains pending in the Wazync outbox and is retried without losing its stable event ID

### Requirement: Laravel consumes events idempotently
Laravel SHALL use an explicit-ack durable JetStream consumer and SHALL acknowledge an envelope only after the existing gateway event ingestion transaction has committed or an idempotent duplicate has been confirmed.

#### Scenario: Consumer crashes before acknowledgement
- **WHEN** ingestion committed but the consumer did not acknowledge the delivery
- **THEN** JetStream redelivers the event and Laravel resolves it as the same event without creating another message or attachment

### Requirement: Outbound WhatsApp commands use an acknowledged durable queue
Laravel SHALL publish outbound WhatsApp commands to a JetStream subject using the stable command ID, and Wazync SHALL explicitly acknowledge a delivery only after its command store durably accepts it or confirms an idempotent duplicate.

#### Scenario: Wazync crashes after storing a command
- **WHEN** JetStream redelivers the same command after restart
- **THEN** Wazync confirms the duplicate, acknowledges it and executes the command at most once through the existing durable worker

### Requirement: Communication media storage is selectable
Laravel SHALL write authenticated encrypted communication-media envelopes to a dedicated private filesystem disk configured as local or S3-compatible MinIO and SHALL never return a public object or presigned MinIO URL to the browser.

#### Scenario: New inbound media is ingested
- **WHEN** Laravel accepts a gateway media stream
- **THEN** MinIO stores only the encrypted `FHCM1` object and the API continues to serve plaintext exclusively after tenant authorization

### Requirement: Storage selection does not trigger migration
The selected communication-media backend SHALL remain explicit per installation and SHALL NOT automatically backfill, replay or copy historical media from another backend.

#### Scenario: Attachment predates the storage switch
- **WHEN** an installation continues with the local backend
- **THEN** authorized preview and download keep using the existing local object layout without a data migration

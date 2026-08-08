## ADDED Requirements

### Requirement: Device sticker synchronization is explicitly partial
The system SHALL ingest only recent sticker metadata and favorite-state changes actually delivered by the paired device and SHALL expose a stable partial-synchronization status without claiming a complete mirror of WhatsApp.

#### Scenario: Recent stickers arrive in history sync
- **WHEN** whatsmeow emits a history sync containing bounded `RecentStickers`
- **THEN** Wazync publishes an allowlisted idempotent event for the session without serializing media keys, direct paths or raw protobuf data

#### Scenario: Device does not provide sticker bootstrap
- **WHEN** a connected inbox produces no recent-sticker bootstrap
- **THEN** the library reports that device synchronization is unavailable or not yet observed and does not fabricate an empty complete collection

### Requirement: Favorite changes are allowlisted and convergent
The system SHALL process only App State actions whose index is the supported `favoriteSticker` shape and SHALL converge repeated favorite and unfavorite events by stable sticker identity and event order.

#### Scenario: Sticker is favorited on the paired device
- **WHEN** a valid favorite action with sufficient identity metadata is observed
- **THEN** the corresponding tenant/inbox library item becomes favorite idempotently

#### Scenario: Unknown raw App State action arrives
- **WHEN** an App State index or payload is not explicitly allowlisted
- **THEN** Wazync discards it without forwarding raw account state or secrets

### Requirement: Sticker media remains private and integrity-checked
The system SHALL download a synchronized sticker only when bounded allowlisted metadata is sufficient to verify the private media and SHALL store accepted WebP bytes on the Laravel-selected private disk.

#### Scenario: Recent sticker has complete downloadable metadata
- **WHEN** direct-path, encryption, digest, MIME and size metadata pass the configured limits
- **THEN** the bytes are fetched through the authenticated internal media flow, verified and committed once under the owning tenant and inbox

#### Scenario: Metadata is incomplete or media expired
- **WHEN** a sticker cannot be downloaded or verified safely
- **THEN** the item remains unavailable with a stable reason and no partial object or successful-sync claim is persisted

### Requirement: Sticker library is tenant-authorized and deduplicated
Laravel SHALL maintain a private sticker catalog scoped by tenant and inbox, deduplicate content by verified digest, enforce quotas and authorize every list, read, write and delete operation.

#### Scenario: Same sticker is observed repeatedly
- **WHEN** history, favorite actions or local import produce identical verified bytes for the same tenant
- **THEN** one private object is reused while source observations and inbox visibility converge without duplicate storage

#### Scenario: Operator requests another tenant's sticker
- **WHEN** an authenticated operator addresses a sticker not owned by the current tenant or authorized inbox
- **THEN** Laravel rejects the request without revealing whether the sticker exists

### Requirement: Operators can import and manage WebP stickers
The API SHALL allow authorized operators to import a bounded valid WebP from their device, list available stickers, manage KontiveHub favorites and remove library references without mutating the WhatsApp device collection implicitly.

#### Scenario: Valid local WebP is imported
- **WHEN** an authorized operator uploads a file whose signature, MIME, dimensions and size pass validation
- **THEN** Laravel stores it privately, records local-import provenance and returns a safe library resource

#### Scenario: KontiveHub favorite is changed
- **WHEN** an operator favorites or unfavorites a library item in the app
- **THEN** the app preference changes without claiming that the WhatsApp mobile favorite was changed

### Requirement: Composer exposes truthful sticker-library states
The Nuxt composer SHALL present authorized recent, favorite and locally imported stickers using semantic theme tokens and SHALL distinguish loading, partial, unavailable, empty and error states.

#### Scenario: Library has available stickers
- **WHEN** the operator opens the sticker picker for an authorized inbox
- **THEN** the picker shows bounded recent and favorite results with keyboard operation, clear provenance and an import action

#### Scenario: Device sync is partial
- **WHEN** locally stored stickers exist but the latest device collection is incomplete or unknown
- **THEN** the picker keeps usable items visible and labels device synchronization as partial rather than showing indefinite loading

### Requirement: Sending a library sticker preserves the outbound contract
The system SHALL materialize a selected library item through Laravel's existing private outbound-media contract and SHALL preserve tenant authorization, idempotency and draft recovery.

#### Scenario: Library sticker is sent
- **WHEN** an authorized operator selects an available item and submits the composer
- **THEN** Laravel creates the existing sticker outbound command using verified private bytes and no WhatsApp storage URL reaches the browser

#### Scenario: Stored object becomes unreadable before send
- **WHEN** the selected private object cannot be read or verified
- **THEN** sending fails closed with a stable recoverable reason and the composer retains the draft

### Requirement: Retention and cleanup preserve referenced media
The system SHALL apply configurable retention and per-tenant quotas while preventing deletion of objects referenced by active library items, drafts or outbound messages.

#### Scenario: Unreferenced synchronized sticker expires
- **WHEN** an item exceeds retention, is not favorite and has no protected reference
- **THEN** a tenant-safe scheduled cleanup removes its catalog reference and private object idempotently

#### Scenario: Quota is exhausted
- **WHEN** another synchronized or imported sticker would exceed the tenant quota
- **THEN** ingestion is rejected or deferred with an observable bounded reason without evicting favorites or referenced media silently

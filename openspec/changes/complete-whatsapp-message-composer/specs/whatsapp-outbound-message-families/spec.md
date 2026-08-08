## ADDED Requirements

### Requirement: Public outbound contract is typed and additive
Laravel SHALL expose documented outbound DTOs and effective capabilities for text/link, media, voice/PTT, sticker, location, contacts, poll, event and interactive families while preserving the existing singular message endpoint and fields.

#### Scenario: Existing simple client sends text
- **WHEN** a client uses the current singular endpoint with `body`, `kind` and idempotency key
- **THEN** the request remains compatible and returns one Message resource

#### Scenario: Client submits structured content
- **WHEN** a supported location, contacts, poll, event or interactive DTO is submitted
- **THEN** Laravel validates only its allowlisted fields and persists semantic content under `content_encrypted`

### Requirement: Multiple media use an idempotent batch contract
Laravel SHALL provide a tenant-authorized batch endpoint for an ordered bounded list of media items and SHALL return a stable batch plus one message result per item without changing the singular response shape.

#### Scenario: Valid batch is created
- **WHEN** all files and variants pass limits and a new `client_batch_id` is supplied
- **THEN** Laravel stages the objects, creates correlated messages/outbox entries transactionally and returns them in submitted order

#### Scenario: Batch request is replayed
- **WHEN** the same tenant, conversation and batch id are submitted with the same digest
- **THEN** the original batch/messages are returned and no duplicate WhatsApp command is produced

#### Scenario: One batch item is invalid before creation
- **WHEN** any item fails MIME, size, variant or authorization validation
- **THEN** no message or orphaned media object from that batch is committed

### Requirement: Contacts support singular and array forms
The API and Wazync SHALL support one or more bounded contacts, map one item to `contactMessage`, map multiple items to `contactsArrayMessage`, and derive display data only from validated DTOs.

#### Scenario: Several contacts are sent
- **WHEN** an authorized operator submits multiple valid vCards within the advertised limit
- **THEN** the recipient receives one contacts-array message and the local projection preserves the same ordered count

### Requirement: Media variants are explicit
Image/video/audio outbound DTOs SHALL explicitly represent caption, PTT, GIF playback, circular video and view-once variants and Wazync SHALL reject invalid family/variant combinations.

#### Scenario: GIF is sent
- **WHEN** a compatible video asset is submitted with `gif=true`
- **THEN** Wazync builds a video message with GIF playback and the projected message exposes the GIF variant

#### Scenario: View-once image is sent
- **WHEN** an image is submitted with `view_once=true` and the capability is enabled
- **THEN** the WhatsApp payload uses the privacy variant and Laravel presents an explicit privacy placeholder after send rather than a reusable viewer

#### Scenario: PTT is requested for a document
- **WHEN** a non-audio payload contains `ptt=true`
- **THEN** Laravel rejects it before media storage or command creation

### Requirement: Event messages use an allowlisted DTO
Laravel and Wazync SHALL support an outbound 1:1 event DTO with bounded title, description, start/end, timezone and location/participation fields supported by the pinned whatsmeow version.

#### Scenario: Valid event is sent
- **WHEN** the event times and required fields are valid and the inbox capability is enabled
- **THEN** Wazync builds `eventMessage` and Laravel projects the outbound item as a safe EVENT card

#### Scenario: Pinned protocol cannot build events
- **WHEN** the configured Wazync version does not pass the event builder contract
- **THEN** the event capability remains disabled and Laravel does not accept an event send request

### Requirement: GIF search is proxied and fail-closed
Any remote GIF search SHALL pass through a Laravel provider port with tenant authorization, rate limits, timeouts and allowlisted response fields, and the selected asset SHALL be revalidated before WhatsApp upload.

#### Scenario: Browser searches GIFs
- **WHEN** an authorized tenant with a configured provider performs a search
- **THEN** Laravel returns bounded preview/result data without provider credentials and the browser makes no request to the provider origin

#### Scenario: Provider fails
- **WHEN** the GIF provider times out or rejects the request
- **THEN** Laravel returns a stable unavailable response and no message draft is silently converted to another family

### Requirement: Outbound status progression is monotonic
Outbox acceptance and gateway events SHALL merge message status monotonically and SHALL never replace `SENT`, `DELIVERED`, `READ` or `PLAYED` with an earlier status.

#### Scenario: Duplicate acceptance arrives after delivery
- **WHEN** a command retry is accepted after the same message is already DELIVERED
- **THEN** the message remains DELIVERED and the duplicate command cannot create another remote send

### Requirement: Message actions converge locally
Edit, reaction and revoke commands initiated by an authorized operator SHALL reach a terminal Wazync result and SHALL update or fail the corresponding Laravel projection observably.

#### Scenario: Edit is processed by Wazync
- **WHEN** WhatsApp accepts an edit command for an outbound message
- **THEN** Laravel receives a correlated terminal event, preserves bounded history and exposes the edited content without waiting indefinitely

#### Scenario: Action fails terminally
- **WHEN** a reaction, edit or revoke cannot be applied after retries
- **THEN** the pending UI state is cleared and a stable failure is exposed without mutating the target message as successful

### Requirement: Private media remains backend-owned
All outbound media, including batch and GIF assets, SHALL be validated and streamed through Laravel's selected private local/MinIO disk to Wazync with digest and size verification.

#### Scenario: Wazync fetches an outbound attachment
- **WHEN** a queued media command requests its object with valid internal authentication
- **THEN** Laravel returns exactly the authorized bytes and digest without a public or presigned storage URL

#### Scenario: Media object is unreadable
- **WHEN** the object is absent or inaccessible to the application runtime
- **THEN** Wazync retries safely, Laravel exposes a terminal failure when exhausted and no successful delivery is claimed

## 1. Gateway projection

- [x] 1.1 Complete the pinned whatsmeow reflective catalog and add upgrade/fallback contract tests
- [x] 1.2 Unwrap associated child and future-proof messages with bounded recursion while accounting for album and placeholder controls
- [x] 1.3 Project contacts, polls, media variants, links and interactive responses without silently dropping visible 1:1 content
- [x] 1.4 Project allowlisted product, order, payment, event, invitation and call facts as read-only rich cards
- [x] 1.5 Emit privacy-safe unsupported metrics and add Go regression tests for wrappers, contact lists, rare types and loss prevention

## 2. Durable transport and storage

- [x] 2.1 Add a JetStream publisher behind the Wazync event outbox and an explicit-ack command consumer with retry/idempotency tests
- [x] 2.2 Add an explicit-ack Laravel event consumer and JetStream command publisher that reuse existing idempotent boundaries
- [x] 2.3 Add a selectable private local or MinIO/S3 communication media disk for encrypted objects
- [x] 2.4 Provision local/production NATS and MinIO services, health checks, buckets and secret-only environment configuration
- [x] 2.5 Add transport redelivery, outage, private-object and storage compatibility tests

## 3. API contract and projection

- [x] 3.1 Extend semantic DTOs, ingestion and resources so rich content remains in content and lifecycle data remains in metadata
- [x] 3.2 Add bounded server-side vCard parsing with stable normalized phone candidates
- [x] 3.3 Add the tenant-safe authorized idempotent shared-contact import endpoint and tests
- [x] 3.4 Add authorized HEAD and single-range encrypted attachment streaming with 206 and 416 behavior
- [x] 3.5 Expand the public OpenAPI message schemas and regenerate compatible frontend types
- [x] 3.6 Add Laravel tests for content families, API shape, media ranges, authorization and tenant isolation

## 4. Web presentation

- [x] 4.1 Align communication message types and helpers with semantic message.content fields
- [x] 4.2 Render contacts, location, polls, interactive responses, rich cards and explicit unsupported states in timeline bubbles
- [x] 4.3 Extract the existing shared-content gallery into one reusable authenticated media viewer
- [x] 4.4 Reuse the viewer in timeline and shared content with keyboard, counter, download, image transforms, mobile and reduced-motion behavior
- [x] 4.5 Add the shared-contact selection/import flow without trusting client-supplied contact values
- [x] 4.6 Add Nuxt unit and browser coverage for rich cards, multiple contacts, media rendering and viewer behavior

## 5. Quality gates

- [x] 5.1 Run Go tests and vet for Wazync
- [x] 5.2 Run focused Laravel tests, the API suite and Pint
- [x] 5.3 Run focused Nuxt tests, UI conformance checks and the complete web test gate
- [x] 5.4 Run the automatic code-review loop and resolve all critical and warning findings

## 6. Live observation

- [x] 6.1 Start the local stack and record privacy-safe database, JetStream and Wazync watermarks
- [ ] 6.2 Keep the test conversation open and correlate only post-watermark Wazync, JetStream, database, API, realtime and DOM evidence
- [ ] 6.3 Verify desktop and mobile rendering, decoded/playable media and viewer controls for newly received messages
- [ ] 6.4 Record the per-type live, fixture-only or failed evidence matrix and request any missing user sends

## Context

The pinned whatsmeow catalog already enumerates every protobuf field, and Laravel already stores most semantic payloads encrypted under `content_encrypted`. The current public contract only describes text/caption and the Nuxt renderer incorrectly reads location, contacts, polls, votes and interactive data from metadata. Media is encrypted as a sequential secretstream and served without byte ranges. A reusable viewer already exists inside `SharedContent.vue`.

The current local snapshot contains live attachments but also historical media without attachments or recovery state. This change is deliberately future-facing and does not replay or backfill those rows.

## Goals / Non-Goals

**Goals:**

- Prevent silent loss of user-visible 1:1 messages.
- Align Wazync, Laravel, OpenAPI and TypeScript around one additive semantic-content contract.
- Provide production-grade presentation, playback and contact import with tenant-safe authorization.
- Prove behavior with tests and fresh live observations.

**Non-Goals:**

- Supporting group, Status or newsletter conversations.
- Executing payments, orders or other commercial actions.
- Exposing view-once media or raw protobuf/provider secrets.
- Recovering historical rows that predate deployment.

## Decisions

1. **Keep semantic data in encrypted content.** Existing storage accepts additive JSON keys and avoids a database migration. Metadata remains a small public lifecycle/availability projection.
2. **Use a closed gateway catalog plus explicit fallback.** Known wrappers are unwrapped, structural markers are consumed, safe rare types become `INTERACTIVE` rich cards, and everything else becomes a visible `UNSUPPORTED` envelope. This bounds exposure while eliminating silent disappearance.
3. **Evolve API v1 additively.** The generated public OpenAPI becomes the source for the richer TypeScript shape; old text, body, caption and vCard fields remain.
4. **Resolve shared contacts on the server.** A message-bound action parses the stored vCard, selects a server-derived phone by index and reuses the canonical tenant contact. The browser never supplies arbitrary contact data to this endpoint.
5. **Extract the incumbent viewer.** A single Nuxt UI fullscreen modal serves timeline and shared-content callers. It follows the existing master-detail archetype and Chatwoot-style media behavior rather than introducing a second gallery.
6. **Implement ranges over the existing encrypted format.** Range reads decrypt sequential chunks and discard bytes before the requested interval. The 20 MB media limit keeps this bounded and avoids a storage-format migration.
7. **Make live observation a hard final checkpoint.** Watermarks separate old diagnostic data from evidence. Absence of new rows pauses work and asks the user for explicit sends.
8. **Use JetStream in both directions without discarding database idempotency.** Wazync only marks an event outbox row delivered after the JetStream publish acknowledgement. Laravel acknowledges inbound events only after idempotent ingestion commits. Laravel publishes outbound commands with their stable command ID and Wazync acknowledges only after `AcceptCommand` durably persists or confirms the duplicate. The existing signed HTTP endpoints remain bounded compatibility/fallback paths during rollout.
9. **Make communication-media storage selectable.** Laravel keeps the authenticated `FHCM1` envelope and writes that object through a dedicated private filesystem disk configured as local or S3-compatible MinIO. Browser access always passes through Laravel authorization; buckets are never public and no presigned object URL enters the public contract. Changing an installation's backend does not implicitly migrate historical objects.

## Risks / Trade-offs

- **Sequential decryption makes late ranges O(n).** → Keep the current media limit, stream chunks without buffering the full object, and test bounded suffix/end ranges.
- **WhatsApp adds protobuf fields frequently.** → Reflective catalog tests fail upgrades and a bounded unsupported counter exposes new types.
- **Malformed or exotic vCards.** → Use a bounded server parser, ignore invalid candidates and disable import when no normalized phone exists.
- **Live verification depends on an external sender.** → Stop at the watermark checkpoint and request only missing test types; never substitute old data.
- **Local dirty UI changes overlap media URLs.** → Preserve and integrate them, never revert the user's work.
- **JetStream redelivers after a consumer crash.** → Reuse the gateway event ID digest/idempotency boundary and acknowledge only committed outcomes; terminate poison envelopes without exposing their body.
- **A storage switch can strand objects written by the previous backend.** → Keep backend selection explicit and stable per installation; do not auto-copy, backfill or silently combine stores.
- **MinIO or NATS can be unavailable.** → Keep outbox rows and encrypted spool objects pending, expose readiness/lag metrics, and fail closed rather than losing an event or returning a public object URL.

## Migration Plan

1. Provision a private MinIO bucket and JetStream stream/consumers with rotated secrets supplied only through deployment configuration.
2. Deploy the Laravel JetStream event consumer, command publisher and selectable media store while HTTP delivery remains available.
3. Deploy Wazync with JetStream event publishing and command consuming enabled, then verify publish acknowledgements and both consumer lags.
4. Deploy catalog/contract changes and the compatible Nuxt renderer/shared viewer.
5. Run focused and full gates; rollback code if a gate fails because no schema/data migration is required.
6. Record fresh watermarks and perform the live observation matrix.
7. Leave all pre-deploy historical media untouched.

## Open Questions

None. Commercial content is read-only, historical recovery is excluded, and missing live evidence requires user-supplied test messages.

# Composer live acceptance evidence

Privacy-safe technical evidence only. Message bodies, encrypted payloads, phone
numbers, JIDs, contact names and credentials MUST NOT be copied here.

## Runtime and watermark (`2026-08-07T15:46:40Z`)

Composer gate watermark for change `complete-whatsapp-message-composer`.
New outbound feature rollouts remain fail-closed.

- Recorded at: `2026-08-07T15:46:40Z`
- Laravel WhatsApp transport: `jetstream`
- Communication media disk: `communication_media`
- Communication media driver: `local` (private vault `/var/vault/communication`)
- Communication media visibility: `private`
- Media object count at watermark: `1764`
- Wazync inbox `1` (`FELIPE`, tenant `2`): `CONNECTED`, enabled, default
- Inbox last_seen_at at watermark: `2026-08-07T12:32:55Z`
- GIF provider driver: `disabled`

### Outbound capabilities at watermark (disabled)

| Feature / builder | Feature flag | Builder inventory |
| --- | --- | --- |
| contacts_array | false | false |
| gif | false | false |
| ptv | false | false |
| event | false | false |
| view_once | false | false |
| media_batch | false | true (inventory only; rollout flag false) |

### Database watermark

| Metric | Value |
| --- | --- |
| Maximum `communication_messages.id` | `4177` |
| Total communication-message rows | `3820` |
| Maximum `communication_events.id` | `16722` |
| Maximum event `created_at` | `2026-08-07T15:45:51Z` |
| Total communication-event rows | `16722` |
| Maximum `communication_outbox_entries.id` | `1383` |
| Total outbox rows | `1383` |
| Maximum `communication_message_batches.id` | `0` |

### JetStream watermark

- Stream: `KONTIVEHUB_WHATSAPP`
- Last sequence: `9743` (first_seq `9744`, retained messages `0`)
- Last stream timestamp: `2026-08-07T14:47:27.5674578Z`
- `communication-events`: delivered/ack floor consumer `10904` / stream `9743`, pending `0`, ack-pending `0`, redelivered `0`
- `wazync-commands`: delivered/ack floor consumer `623` / stream `9619`, pending `0`, ack-pending `0`, redelivered `0`
- Event-consumer heartbeat file present after `communication-events` restart (`/tmp/kontivehub-communication-events-heartbeat`)

### Browser watermark

- Pending: authenticated session not yet established for this gate.
- Target: open `/communication` under tenant `contador` (id `2`), live inbox `1`, authorized test conversation selected, then record desktop viewport before any composer send.

## Post-watermark matrix

No pre-watermark row can satisfy this matrix. Classifications start only after the
browser watermark and authorized conversation are open.

| Family / variant | Classification | Evidence |
| --- | --- | --- |
| _(pending)_ | — | — |

## Checkpoint log

### `2026-08-07T15:46Z` deploy + server watermarks

- Stack containers healthy/up: api, web, nginx, postgres, redis, nats, wazync, communication-events, horizon, reverb, minio, scheduler.
- `communication-events` was restarted after temporary NATS DNS failures; consumer resumed pull waits with heartbeat file updated.
- All `COMMUNICATION_OUTBOUND_*` feature flags remain false; GIF provider disabled.
- Browser login and authorized conversation open are required before marking task 8.1 complete and before any 8.2 send.

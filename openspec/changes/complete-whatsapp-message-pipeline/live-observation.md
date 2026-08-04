# Live observation evidence

This file records only privacy-safe technical evidence. Message bodies, encrypted payloads, phone numbers, JIDs, contact names and credentials MUST NOT be copied here.

## Runtime and watermark

- Recorded at: `2026-08-03T21:03:35.866183Z` (`2026-08-03T18:03:35.866183-03:00`)
- Laravel WhatsApp transport: `jetstream`
- Communication media disk: `communication_media`
- Communication media driver: `s3`
- Communication media visibility: `private`
- MinIO bucket reachable: yes
- MinIO object count at watermark: `0` (new backend; no historical backfill)
- Wazync enabled: yes
- Wazync JetStream connected: yes
- Laravel event-consumer heartbeat age at inspection: `10s`
- Maximum `communication_messages.id`: `3157`
- Total communication-message rows: `2800`
- Maximum Wazync event creation time: `2026-08-03T21:02:44.315382Z`
- Total Wazync event rows: `15941`
- JetStream stream: `KONTIVEHUB_WHATSAPP`
- JetStream last sequence: `10`
- JetStream retained messages: `0`
- `communication-events`: delivered/ack floor `10/10`, pending `0`, ACK pending `0`, redelivered `0`
- `wazync-commands`: delivered/ack floor `0/0`, pending `0`, ACK pending `0`, redelivered `0`

### Pre-watermark message counts

These counts are diagnostic only and MUST NOT be used as live acceptance evidence.

| Kind | Provider type | Count |
| --- | --- | ---: |
| AUDIO | audioMessage | 622 |
| CONTACT | contactMessage | 15 |
| DOCUMENT | documentMessage | 140 |
| IMAGE | imageMessage | 216 |
| INTERACTIVE | interactiveMessage | 15 |
| INTERACTIVE | listMessage | 1 |
| INTERACTIVE | templateButtonReplyMessage | 3 |
| INTERACTIVE | templateMessage | 20 |
| LOCATION | locationMessage | 2 |
| STICKER | stickerMessage | 16 |
| TEXT | conversation | 1324 |
| TEXT | extendedTextMessage | 353 |
| TEXT | null | 2 |
| UNSUPPORTED | albumMessage | 6 |
| UNSUPPORTED | associatedChildMessage | 16 |
| UNSUPPORTED | placeholderMessage | 1 |
| UNSUPPORTED | protocolMessage | 31 |
| VIDEO | videoMessage | 17 |

## Post-watermark matrix

No pre-watermark row can satisfy this matrix.

| Requested type | Technical ID | Database projection | Media state | API | Realtime / DOM | Visual | Classification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Simple text and link | pending | pending | n/a | pending | pending | pending | pending |
| Image, with and without caption | pending | pending | pending | pending | pending | pending | pending |
| Album with multiple images/videos | pending | pending | pending | pending | pending | pending | pending |
| Audio and voice/PTT | pending | pending | pending | pending | pending | pending | pending |
| Video, GIF or circular video | pending | pending | pending | pending | pending | pending | pending |
| PDF/document | pending | pending | pending | pending | pending | pending | pending |
| Sticker | pending | pending | pending | pending | pending | pending | pending |
| Location | pending | pending | n/a | pending | pending | pending | pending |
| Single contact and multiple contacts | pending | pending | n/a | pending | pending | pending | pending |
| Poll | pending | pending | n/a | pending | pending | pending | pending |
| Quote, reaction, edit and deletion | pending | pending | n/a | pending | pending | pending | pending |
| View-once privacy placeholder | pending | pending | unavailable by design | pending | pending | pending | pending |
| Commercial rich cards | fixtures | contract fixtures pass | n/a | contract fixtures pass | not observed | not observed | fixture apenas |

## Observation checkpoints

### `2026-08-03T21:07Z`

- Browser: authenticated Playwright CLI headed session at desktop viewport `1440x900`.
- The two pre-existing visible conversations belong to fixture inbox `2`; the live Wazync session belongs to connected inbox `1`.
- The communication workspace remains open so a newly created live conversation can arrive through realtime before it is selected.
- Post-watermark Wazync events: one `CONTACT_IDENTITY_CHANGED` control event.
- JetStream advanced to sequence `11`; `communication-events` delivered and acknowledged sequence `11`, with pending/ACK-pending/redelivery all `0`.
- Post-watermark `communication_messages`: `0`.
- Post-watermark message elements in the open DOM: `0`.
- Result: live verification paused as required; user test sends are required. The control event is not a substitute for a message occurrence.

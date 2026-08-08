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

Scoped correlation (checkpoint `2026-08-07T12:00Z`) uses live inbox `1` (tenant `contador`), gateway-linked rows only (`gateway_event_id` present), matching `MESSAGE_RECEIVED` events where applicable, public API `200`, and DOM/visual checks under tenant session switched to tenant `2`. Conversation technical IDs: `15` (text/document/image/sticker/audio/interactive), `8` (audio/video), `20` (contact), `1411` (location). Smoke outbound rows without `gateway_event_id` (e.g. conversation `1400`) are excluded from live classification.

| Requested type | Technical ID | Database projection | Media state | API | Realtime / DOM | Visual | Classification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Simple text and link | conv `15` | gateway-linked TEXT | n/a | `200`, kind TEXT + link card | bubbles; cursor sync connected | desktop + mobile timeline | observado ao vivo |
| Image, with and without caption | conv `15` sample `3887` | IMAGE + matching `MESSAGE_RECEIVED` | READY, jpeg | attachments + stream URL | open control + viewer | viewer image, counter, rotate/zoom/download | observado ao vivo |
| Album with multiple images/videos | — | none post-WM | — | — | — | — | pending (needs user send) |
| Audio and voice/PTT | conv `8` / `15` | gateway-linked AUDIO | READY, ogg | attachments + stream URL | timeline `<audio>`; voice label | audio viewer; mobile timeline | observado ao vivo |
| Video, GIF or circular video | conv `8` msg `3178` | VIDEO inbound + event | READY, mp4 | attachments + stream URL | video open control | viewer `readyState=4` ~4.2s; GIF/circular not distinguished | observado ao vivo (video); GIF/circular pending |
| PDF/document | conv `15` | DOCUMENT inbound | READY, pdf | attachments + URL | document card + READY | desktop timeline | observado ao vivo |
| Sticker | conv `15` | STICKER inbound | READY, webp | attachments + URL | sticker open control | desktop timeline | observado ao vivo |
| Location | conv `1411` msg `3457` | LOCATION + gateway id | n/a | content.location | location card + map link | desktop | observado ao vivo |
| Single contact and multiple contacts | conv `20` msg `3846` | CONTACT inbound + event | n/a | content.contacts (1) | contact card/list | single card; phones empty → save disabled; multi pending | observado ao vivo (single); multi pending |
| Poll | — | only smoke row without gateway | n/a | — | — | — | pending (needs gateway-linked send) |
| Quote, reaction, edit and deletion | mixed | post-WM actions + revoked rows | n/a | partial | “editada” marker seen | edit observed; quote/reaction/delete not fully correlated | parcial — needs targeted sends |
| View-once privacy placeholder | — | none observed | unavailable by design | — | — | — | pending (needs user send) |
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

### `2026-08-04T13:30Z` database reinspection

- The historical `21:07Z` no-message checkpoint is no longer the current database state.
- Post-watermark rows: `79` communication messages across one tenant, one inbox and `12` conversations. They cannot be attributed to the authorized test conversation without an additional scoped correlation.
- Post-watermark gateway projections by family: audio `30`, contact `1`, document `3`, image `9`, interactive `1`, location `1`, poll `1`, sticker `2`, text `30` and video `1`.
- Attachments exist for `45` of those rows: audio `30`, document `3`, image `9`, sticker `2` and video `1`.
- The event store contains `64` correlated `MESSAGE_RECEIVED` events after the watermark. This is database/event-store evidence only.
- API, realtime/DOM, desktop/mobile rendering, decoded playback and viewer controls remain unverified for the authorized test conversation.
- Album, GIF/circular video, distinguishable PTT, multiple contacts, quote/reaction/edit/delete, view-once and live commercial cards remain missing or unclassified.
- Result: tasks 6.2–6.4 remain open. No row in this aggregate is classified as `observado ao vivo` until the authorized conversation, API, DOM and visual evidence are correlated.

### `2026-08-07T12:00Z` scoped live correlation

- Auth: tenant admin session with active tenant switched to `contador` (id `2`); fixture-only inbox `2` conversations are out of scope.
- Transport: JetStream stream `KONTIVEHUB_WHATSAPP` last sequence `9306`; `communication-events` delivered/ack `9270/9270` pending `0` ack-pending `0` redelivered `0`; `wazync-commands` delivered/ack `9306/9306` pending `0`.
- Database: post-watermark `communication_messages.id > 3157` count `877` (`861` with `gateway_event_id`); max id `4034`.
- End-to-end sample (image `3887`, conv `15`): `gateway_event_id` present, matching `MESSAGE_RECEIVED` event, attachment READY, API `200`, DOM bubble, fullscreen image viewer with counter/nav/transform/download.
- Video sample (`3178`, conv `8`): READY mp4, viewer `communication-media-viewer-video`, decoded `readyState=4`.
- Contact sample (`3846`, conv `20`): API `content.contacts`, DOM contact card/list; no normalized phones so import remains disabled by design.
- Location sample (`3457`, conv `1411`): API `content.location`, DOM location card + map link.
- Desktop viewport `1440x900` and mobile `390x844`: conversation dialog/timeline renders without horizontal overflow; media READY badges and audio/video controls visible on mobile.
- Realtime: workspace status “Sincronizando por cursor” while conversations open. Fresh arrival-without-refresh for a brand-new send was not exercised in this session; classification uses post-watermark rows already present plus connected cursor transport.
- Exclusions: conversation `1400` smoke rows without `gateway_event_id` are not live evidence.
- Result: tasks 6.2–6.4 complete for correlated types. Remaining user sends requested below.

## Missing user sends (still required)

Please send into the live connected inbox (`1` / session FELIPE) while the matching conversation stays open in `/communication`:

1. Album with multiple images/videos
2. GIF and/or circular video (distinct from regular mp4)
3. Multiple contacts in one message
4. Poll creation (so a gateway-linked POLL row appears)
5. Quote, reaction, and deletion (revoke) on a fresh message
6. View-once placeholder (must remain non-revealable)

Commercial product/order/payment cards remain `fixture apenas` unless a live commercial send becomes available.

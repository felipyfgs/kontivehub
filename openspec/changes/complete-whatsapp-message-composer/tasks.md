## 1. Baseline, capabilities and status consistency

- [x] 1.1 Inventory the pinned whatsmeow outbound builders and record tested support for contact arrays, native albums, GIF playback, PTV, events and view-once in Wazync contract tests.
- [x] 1.2 Replace the loose outbound-capabilities payload with documented discriminated DTOs covering enabled state, stable unavailability reason, MIME/byte/duration limits, batch limits and variants.
- [x] 1.3 Merge outbox acceptance and gateway receipts through the canonical status ordering so `SENT`, `DELIVERED`, `READ` and `PLAYED` cannot regress on retries or duplicate events.
- [x] 1.4 Publish correlated terminal events for outbound edit, reaction and revoke operations and project their success or stable failure in Laravel.

## 2. Laravel outbound contracts and storage

- [x] 2.1 Extend singular message validation, DTOs, actions, resources and OpenAPI additively for contacts arrays, event, GIF, PTV and view-once while preserving current text and media clients.
- [x] 2.2 Add tenant-aware policies and effective capability evaluation that combines user permission, inbox state, rollout flags and Wazync builder availability.
- [x] 2.3 Add the authorized `message-batches` endpoint with ordered items, `client_batch_id`, request digest and stable batch/message response resources.
- [x] 2.4 Implement transactional, idempotent batch creation so invalid items commit neither messages, outbox commands nor orphaned private objects.
- [x] 2.5 Validate outbound file signatures, MIME, size, digest and family/variant combinations before committing objects to the configured private local or MinIO disk.
- [x] 2.6 Stream authorized local/MinIO objects to Wazync through the internal media contract without exposing public or presigned storage URLs, including bounded retry and terminal failure.
- [x] 2.7 Add an optional tenant-aware GIF provider port with authorization, rate limit, timeout, short cache and allowlisted results; keep remote search fail-closed when no provider is configured.

## 3. Wazync builders and reliable commands

- [x] 3.1 Define versioned allowlisted outbound command DTOs for singular messages and ordered batches without accepting protobuf fields from Laravel.
- [x] 3.2 Implement and test singular versus array contact builders, preserving the validated contact order and count.
- [x] 3.3 Implement and test media builders for captions, PTT, GIF playback, PTV and view-once, rejecting incompatible combinations before upload.
- [x] 3.4 Implement and test the bounded event builder against the pinned whatsmeow descriptor and keep its capability disabled when the contract is unavailable.
- [x] 3.5 Implement ordered media-batch delivery with a native album only when proven interoperable, otherwise advertise `album_native=false` and send one correlated ordered sequence.
- [x] 3.6 Ensure JetStream commands in both directions use stable message IDs, deduplication, acknowledgements, bounded retries and terminal results without duplicate WhatsApp sends.

## 4. Typed composer foundation

- [x] 4.1 Introduce a discriminated `ComposerDraft` for text, media batch, audio, sticker, location, contacts, poll, event and interactive content with impossible field combinations excluded by type.
- [x] 4.2 Implement a per-conversation draft store that separates internal-note and WhatsApp drafts, restores citations correctly and never shares files or structured values across conversations.
- [x] 4.3 Add draft reducers, validators and the API adapter that serialize each family to JSON or `FormData` with stable idempotency and batch keys.
- [x] 4.4 Load effective inbox capabilities with the composer workspace and block a preserved open draft with the current stable reason when support changes.
- [x] 4.5 Preserve editable drafts on API, storage or queue failure and clear them only after the corresponding local message or batch acknowledgement.
- [x] 4.6 Keep binary drafts private to the live session, scoped by conversation and within memory limits; warn on controllable destructive navigation and never persist blobs in browser storage.

## 5. Composer shell and structured families

- [x] 5.1 Build a capability-gated attachment launcher with the stable first-level groups Files/media, Client/context, Create and More, limiting each decision layer to four visible choices without adaptive reordering.
- [x] 5.2 Render the launcher as an anchored accessible menu/submenu on desktop and a stepped touch-safe bottom sheet with the same titles, Back path and semantic order on mobile.
- [x] 5.3 Build validated location, contacts, poll, event and advanced interactive editors that produce typed previews rather than flattened text.
- [x] 5.4 Enforce tenant-safe contact selection, ordered multi-contact limits, poll option uniqueness/selection bounds and allowlisted event fields.
- [x] 5.5 Add a compact authorized conversation/client and inbox context surface, revalidate it on context changes and require explicit destination/privacy confirmation for sensitive variants.
- [x] 5.6 Preserve internal notes, canned responses, slash shortcuts, quoted replies, selection-aware cursor insertion and keyboard-send behavior in the refactored shell.
- [x] 5.7 Apply incumbent Nuxt UI primitives and `DESIGN.md` tokens so context, state, content and action follow scan-first hierarchy with one primary green action and no WhatsApp theme copy.

## 6. Media, expressions and voice UX

- [x] 6.1 Build ordered media/document previews with thumbnails or file metadata, per-item caption and variant controls, validation feedback, reorder and removal.
- [x] 6.2 Implement camera capture with `getUserMedia`, safe stream/object-URL cleanup and ordinary file-selection fallback on unsupported APIs or denied permission.
- [x] 6.3 Implement browser-local sticker crop/resize and bounded WebP generation with preview, validation and temporary-resource cleanup.
- [x] 6.4 Build the searchable keyboard-accessible expression picker with Unicode emoji insertion, recents and capability-gated GIF and sticker tabs.
- [x] 6.5 Route remote GIF search and selection only through Laravel, materialize or revalidate the chosen asset before send and keep local GIF upload usable independently.
- [x] 6.6 Implement the voice-recorder state machine `idle -> recording <-> paused -> preview -> sending` with duration, waveform, playback, discard and recoverable errors.
- [x] 6.7 Detect the recorded blob MIME/extension, apply capability duration/byte limits, set `ptt=true` only for compatible audio and release every microphone track/object URL on terminal transitions.
- [x] 6.8 Add the per-item lifecycle `validating -> uploading -> queued -> sent -> delivered/read` plus blocked, cancelled, failed and partial states with pt-BR cause, impact, progress and safe idempotent action.
- [x] 6.9 Add accessible names, relevant `aria-live` announcements, focus trap/restore, Escape/Back behavior, reduced motion, 44-by-44 mobile targets, safe-area/virtual-keyboard handling and functional 200 percent zoom.

## 7. Automated verification and documentation

- [x] 7.1 Add Laravel feature and contract tests for compatibility, structured DTOs, capabilities, tenant authorization, batch atomicity/idempotency, GIF proxy, private storage and monotonic status/action projection.
- [x] 7.2 Add Go tests for every advertised builder/variant, invalid combinations, media integrity, ordered batch fallback, JetStream deduplication and terminal action events; run `go test ./...` and `go vet ./...`.
- [x] 7.3 Add Nuxt unit tests for draft reducers, per-conversation isolation, serializers, capability changes, lifecycle copy/state, binary-session cleanup, selection-aware insertion, expression focus and recorder transitions.
- [x] 7.4 Add Playwright desktop/mobile coverage for grouped launchers, destination context, structured forms, media preview, per-item progress, picker, camera/microphone fixtures, keyboard/focus, safe area, 200 percent zoom, interruption, failure recovery and no-refresh timeline arrival.
- [x] 7.5 Update OpenAPI and operator-facing documentation with supported families, variants, batch semantics, limits, capability reasons and the optional GIF-provider configuration.
- [x] 7.6 Run API tests and Pint, the Web `test-gate`, Wazync tests/vet and the repository gate, recording any environment-only limitation without weakening the checks.

## 8. Post-deploy live acceptance gate

- [ ] 8.1 Deploy the stack with new capabilities disabled, register privacy-safe database, JetStream, Wazync and browser watermarks, and open the authorized test conversation before sending.
- [ ] 8.2 Exercise every capability advertised to the test inbox after the watermark and correlate composer action, message or batch ID, attachment state, outbox command, Wazync result, API resource and `[data-message-id]`.
- [ ] 8.3 Verify desktop and mobile visuals against `DESIGN.md`, destination context, grouped hierarchy, media decoding/playback, batch ordering and child states, structured cards, expression flows, voice states, focus/keyboard/zoom behavior and real-time appearance without refresh.
- [ ] 8.4 Exercise queue unavailability, unreadable outbound media, retry/idempotency and edit/reaction/revoke convergence, proving drafts are retained and delivery is never claimed or regressed prematurely.
- [ ] 8.5 Classify each family and variant as `observado ao vivo`, `fixture apenas`, `indisponível por capability` or `falhou`, requesting only missing live actions and never substituting pre-watermark data.
- [ ] 8.6 Obtain confirmation from the authorized recipient for external presentation, retain the broader inbound-test requests still missing and enable each rollout capability only after its evidence passes.

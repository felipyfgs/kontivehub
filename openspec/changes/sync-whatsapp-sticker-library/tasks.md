## 1. Pinned protocol contracts

- [x] 1.1 Add reflective whatsmeow contract tests for `HistorySync.RecentStickers`, downloadable `StickerMetadata`, `favoriteSticker` App State indexes, `StickerAction` fields and `FetchStickerPack` on the pinned revision.
- [x] 1.2 Define bounded allowlisted Wazync DTOs and stable event IDs for recent-sticker observations, favorite/unfavorite observations, availability reasons and sync watermarks without transport secrets.
- [x] 1.3 Extend the protocol event bridge to extract bounded recent stickers from history sync while preserving the existing scoped message projection and deduplication.
- [x] 1.4 Add a dedicated deny-by-default App State parser that accepts only the exact `favoriteSticker` index/shape and continues discarding every other raw action.
- [x] 1.5 Add Go tests proving malformed, oversized, duplicate, out-of-order and secret-bearing recent/favorite payloads are rejected or converge safely.

## 2. Private media materialization

- [x] 2.1 Define the internal authenticated request/response contract for materializing one observed sticker, including session scope, opaque observation ID, expected digest, MIME and byte limits.
- [x] 2.2 Implement bounded whatsmeow download for complete `StickerMetadata`, with context cancellation, digest/size verification and stable expired/incomplete/unsupported reasons.
- [x] 2.3 Correlate state-only favorite observations with recent, history-message or received-sticker metadata without treating direct paths or image hashes as durable content identity.
- [x] 2.4 Add retry, idempotency and terminal JetStream events for sticker materialization without retaining a durable catalog or plaintext library in Wazync.
- [x] 2.5 Add Go tests for successful WebP materialization, incomplete metadata, expired media, digest mismatch, oversized media, retry and duplicate commands; run `go test ./...` and `go vet ./...`.

## 3. Laravel catalog and storage

- [x] 3.1 Add tenant-scoped sticker content, inbox observation/visibility and sync-watermark models with migrations, factories, relationships, encrypted sensitive metadata and unique/idempotency constraints.
- [x] 3.2 Implement private local/MinIO storage services that validate WebP signature, MIME, dimensions, animation policy, byte limit and digest before transactional catalog commit.
- [x] 3.3 Implement event consumers that converge recent/favorite observations monotonically, request materialization only when safe and preserve explicit unavailable reasons.
- [x] 3.4 Deduplicate verified bytes by tenant digest while keeping device favorite, KontiveHub favorite, provenance, inbox visibility and last-observed timestamps separate.
- [x] 3.5 Add policies and permissions for listing, previewing, importing, favoriting and removing library items with fail-closed tenant and inbox authorization.
- [x] 3.6 Add configurable per-tenant item/byte quotas and reference-aware retention metadata without silently evicting favorites, local imports or outbound-message references.

## 4. Public API and outbound integration

- [x] 4.1 Add Form Requests, DTOs, actions, API Resources and routes for paginated library listing, authorized private preview, WebP import, app-favorite mutation and reference removal.
- [x] 4.2 Expose sync status as `partial`, `not_observed`, `syncing` or `failed` with stable reason codes and last-observed watermark; never expose a synthetic complete state.
- [x] 4.3 Extend effective outbound capabilities with sticker-library availability, limits and source classifications while keeping ordinary local sticker upload independent.
- [x] 4.4 Add a library-sticker send adapter that resolves authorized private bytes into the existing idempotent `STICKER` outbound contract and retains drafts on unreadable objects or queue failure.
- [x] 4.5 Update OpenAPI and generated Web types with opaque IDs and safe metadata only, documenting that app favorites do not mutate WhatsApp mobile favorites.
- [x] 4.6 Add Laravel feature/contract tests for multi-tenant denial, inbox scope, import validation, deduplication, pagination, favorite convergence, private preview and idempotent send.

## 5. Composer library experience

- [x] 5.1 Add a Nuxt sticker-library client/composable with request epochs or cancellation, bounded pagination and explicit loading, partial, unavailable, empty and error states.
- [x] 5.2 Extend the expression picker with theme-responsive “Recentes” and “Favoritas” library views, provenance labels, private previews and local WebP import using incumbent Nuxt UI patterns.
- [x] 5.3 Keep local upload and sticker creation usable when device synchronization is absent, and distinguish device favorite from KontiveHub favorite in pt-BR copy.
- [x] 5.4 Integrate selected library items into the discriminated composer draft and existing send lifecycle without browser access to storage or WhatsApp transport URLs.
- [x] 5.5 Add keyboard navigation, focus restore, accessible names/live announcements, reduced motion, 44 px touch targets, responsive overlays and light/dark theme coverage.
- [x] 5.6 Add Vitest and Playwright coverage for recent/favorite results, partial/no-bootstrap state, import, favorite mutation, tenant denial, theme switching, failure recovery and successful timeline arrival.

## 6. Retention, operations and rollout

- [x] 6.1 Add a tenant-safe scheduled cleanup using chunked queries, locks and reference checks, with dry-run metrics and idempotent object deletion.
- [x] 6.2 Add privacy-safe metrics/log fields for observed, materialized, unavailable, deduplicated, quota-blocked and cleaned stickers without media keys, direct paths or file content.
- [x] 6.3 Document rollout flags, quotas, retention, MinIO/local storage, partial-sync semantics, operational diagnosis and rollback procedures.
- [ ] 6.4 Run Pint and API tests, the Web `test-gate`, Wazync tests/vet and the repository gate without weakening existing checks.
- [ ] 6.5 Deploy disabled, record post-deploy database/JetStream/Wazync/browser watermarks and enable observation capture only for an authorized test inbox.
- [ ] 6.6 Verify fresh pairing and reconnect behavior, recent/favorite observations, expired media, duplicates, quotas, cleanup, private previews and outbound send against real device evidence.
- [ ] 6.7 Classify the live result as observed, partial, unavailable or failed; enable the picker capability only after tenant isolation, integrity and truthful-state checks pass.

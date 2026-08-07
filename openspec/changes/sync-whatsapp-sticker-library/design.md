## Context

The pinned whatsmeow revision exposes three relevant surfaces: `HistorySync.RecentStickers`, raw App State actions indexed as `favoriteSticker`, and `FetchStickerPack(packID)`. It does not expose a supported query that lists every saved/favorite sticker or every pack on the account. Wazync already consumes history and App State, but currently projects only one-to-one history messages and intentionally discards raw App State; it also has a bounded adapter for metadata of a known sticker pack.

The implementation crosses Wazync, JetStream, Laravel private storage and the Nuxt composer. Laravel remains the domain and authorization owner. Wazync must not become a tenant catalog, and the browser must never receive WhatsApp media keys, direct paths, provider URLs or raw synchronization payloads.

## Goals / Non-Goals

**Goals:**

- Capture recent stickers and favorite changes that the paired device actually supplies.
- Build a reusable, tenant-isolated sticker library with verified private media.
- Make partial synchronization and unavailable media truthful and observable.
- Reuse the existing outbound sticker pipeline and composer draft semantics.
- Support local WebP import independently of device synchronization.

**Non-Goals:**

- Promise a complete mirror of WhatsApp favorites, recents or installed packs.
- Scrape WhatsApp Web, access phone storage or introduce unofficial browser automation.
- Forward arbitrary App State or protobuf fields into Laravel.
- Make a KontiveHub favorite mutate the mobile WhatsApp favorite in the first delivery.
- Store media permanently in Wazync or expose WhatsApp private paths to Web.

## Decisions

### 1. Synchronization is observation-based, not collection-based

Wazync will consume bounded `RecentStickers` from history sync and allowlisted `favoriteSticker` actions as observations. Each event carries a stable opaque observation ID, safe identity/digest fields, favorite state and availability classification. Laravel records watermarks per session/inbox and exposes `partial`, `not_observed`, `syncing` or `failed`, never `complete` unless a future pinned protocol proves a completeness contract.

Alternative rejected: treat an empty history field as a complete empty library. The primary device may omit bootstrap data, so this would erase or misrepresent useful items.

### 2. Raw App State remains denied by default

The existing raw App State discard remains the default. A dedicated parser accepts only the exact `favoriteSticker` index and bounded `StickerAction` fields needed for identity and state. Unknown indexes, unexpected shapes, URLs and transport secrets are rejected before JetStream publication.

Alternative rejected: serialize `SyncActionValue` generically. It contains account-wide private state outside the 1:1 communication scope.

### 3. Laravel owns catalog and media lifetime

Wazync publishes metadata observations and, when requested through an authenticated internal command, streams downloadable sticker bytes with digest/size verification. Laravel validates the WebP signature and dimensions, writes to its configured private local/MinIO disk, and transactionally creates or updates the catalog. Wazync retains no durable library.

Alternative rejected: store the library in the Wazync database. That would move tenant authorization and product-domain ownership into the technical gateway.

### 4. Content identity and observations are separate

The catalog uses a tenant-scoped verified plaintext digest as content identity. Inbox/session observations, device-favorite state, app-favorite state, provenance and last-seen timestamps are separate records. Repeated events converge idempotently; identical content can be visible to multiple authorized inboxes without duplicating bytes.

Alternative rejected: identify items only by direct path or image hash. Paths expire and device identifiers may not be stable or sufficient for integrity.

### 5. Device and KontiveHub favorites are distinct

`device_favorite` reflects the latest observed WhatsApp action. `app_favorite` is an operator-managed library preference. The UI can combine them for display but labels provenance and never reports app changes as synchronized back to the phone.

Alternative rejected: send App State mutations from KontiveHub immediately. The current project has no tested, pinned contract proving safe bidirectional sticker-favorite mutation.

### 6. Existing outbound sticker command is reused

Selecting a library item resolves an authorized Laravel object into the existing `STICKER` draft/send adapter. New public resources use opaque library IDs; internal object identifiers and transport metadata remain server-side. Send failures preserve the composer draft.

Alternative rejected: give Web a presigned URL and make it re-upload the sticker. That exposes private media unnecessarily and wastes bandwidth.

### 7. Retention is reference-aware and fail-closed

Non-favorite synchronized observations receive bounded retention. Favorites, local imports and media referenced by messages/drafts are protected. Cleanup uses a lock, tenant-scoped chunks and an object-reference check; quota exhaustion produces an explicit event/state instead of silent eviction.

## Risks / Trade-offs

- [WhatsApp omits or changes synchronization fields] → Pin reflective contract tests to the exact whatsmeow revision, advertise partial status and disable device sync when the contract diverges.
- [Favorite actions lack sufficient download metadata] → Preserve state-only observations and materialize bytes only after correlation with recent/history/message metadata.
- [Expired direct paths prevent import] → Mark the item unavailable, retain safe metadata briefly and allow local import; never claim successful synchronization.
- [Duplicate or out-of-order events regress state] → Use stable event IDs, per-observation timestamps/watermarks and monotonic convergence rules.
- [Private media increases storage] → Enforce MIME/dimension/byte limits, deduplicate by digest, apply quotas and reference-aware retention.
- [Raw App State leaks account data] → Keep a single allowlisted parser, secret-key scanners and tests proving raw payloads never enter events/logs.
- [UI implies device parity] → Use explicit “parcial”, “observado no dispositivo” and “favorito no KontiveHub” copy with distinct states.

## Migration Plan

1. Add schema, models, policies and private-storage services with the feature disabled.
2. Add Wazync reflective tests and allowlisted events without enabling consumption.
3. Deploy Laravel consumers and OpenAPI endpoints behind tenant/inbox rollout flags.
4. Enable observation capture for an authorized test inbox and record a post-deploy watermark.
5. Verify recent/favorite observations, media integrity, deduplication, tenant denial, quotas and cleanup.
6. Enable the Nuxt library picker only for inboxes whose effective capability is advertised.
7. Roll back by disabling capture and UI flags; preserve catalog rows/objects until reference-safe cleanup runs.

## Open Questions

- Whether the pinned device sends `RecentStickers` reliably on both fresh pairing and reconnect must be classified by live acceptance.
- Whether a favorite action can always be correlated to downloadable recent/message metadata must be measured before advertising materialization.
- Default quota, retention duration and whether app favorites are office-wide or user-specific require product confirmation before rollout.

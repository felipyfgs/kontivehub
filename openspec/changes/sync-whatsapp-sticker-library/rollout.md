# Rollout — whatsapp sticker library

Privacy-safe operational notes. Do not paste media keys, direct paths, JIDs, phone numbers or WebP bytes into tickets or logs.

## Feature flags

| Flag / env | Default | Meaning |
| --- | --- | --- |
| `COMMUNICATION_STICKER_LIBRARY_ENABLED` | `true` (local) | Enables library list/import/preview/favorite UI+API. |
| `COMMUNICATION_STICKER_DEVICE_SYNC_ENABLED` | `false` | Enables ingestion of device observations and materialization commands. Keep off until post-deploy watermarks are recorded. |
| Quotas | `max_item_bytes=1MiB`, `max_dimension=512`, `max_items_per_tenant=500`, `max_bytes_per_tenant=100MiB` | Fail closed on exceed; never silently evict favorites or referenced objects. |
| `COMMUNICATION_STICKER_RETENTION_DAYS` | `30` | Applies to non-protected synchronized observations only. |
| `COMMUNICATION_STICKER_ALLOW_ANIMATED` | `true` | Animated WebP policy. |

Storage uses the existing private `communication_media` disk (local or MinIO/S3). Buckets stay private; browsers only receive authorized Laravel preview streams.

## Partial-sync semantics

- Status values: `partial`, `not_observed`, `syncing`, `failed`. Never advertise `complete`.
- Device favoriting observed via allowlisted `favoriteSticker` is `device_favorite`.
- Operator preference in KontiveHub is `app_favorite` and does **not** mutate WhatsApp mobile favorites.
- Missing bootstrap, expired media and incomplete metadata remain explicit unavailable reasons.

## Rollout sequence

1. Deploy schema/models/API with **device sync disabled**.
2. Confirm private disk health and JetStream consumers.
3. Record privacy-safe watermarks (max message/event ids, JetStream sequence, browser session).
4. Enable `COMMUNICATION_STICKER_DEVICE_SYNC_ENABLED` for one authorized test inbox only.
5. Verify recent/favorite observations, materialization integrity, tenant denial, quotas and cleanup dry-run.
6. Enable picker capability for that inbox after isolation checks pass.
7. Expand inbox/tenant rollout gradually.

## Diagnosis

| Symptom | Check |
| --- | --- |
| Always `not_observed` | Device sync flag, Wazync history/App State allowlist, session connectivity |
| Items stuck `PENDING_MATERIALIZATION` | JetStream command consumer, Wazync `STICKER_MATERIALIZE`, spool download |
| Preview 409 | Private object unreadable; availability should flip to unreadable reason |
| Quota errors | Tenant item/byte counters; favorites/imports are not auto-evicted |
| UI shows empty but import works | Expected when device sync is off or not yet observed |

## Rollback

1. Disable device sync (`COMMUNICATION_STICKER_DEVICE_SYNC_ENABLED=false`).
2. Optionally disable library UI (`COMMUNICATION_STICKER_LIBRARY_ENABLED=false`).
3. Leave catalog rows/objects until reference-safe cleanup runs; do not mass-delete.

## Cleanup

Run `php artisan communication:cleanup-sticker-library --dry-run` before applying deletes. Cleanup must skip `retention_protected`, favorites and message-referenced content. Scheduled daily at `04:10` via `routes/console.php`.

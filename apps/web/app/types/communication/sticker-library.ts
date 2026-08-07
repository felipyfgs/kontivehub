export type StickerLibraryFilter = 'recent' | 'favorites'

export type StickerLibrarySyncStatus
  = | 'partial'
    | 'not_observed'
    | 'syncing'
    | 'failed'

export type StickerLibrarySource
  = | 'LOCAL_IMPORT'
    | 'DEVICE_RECENT'
    | 'DEVICE_FAVORITE'
    | 'DEVICE_MESSAGE'
    | 'local_import'
    | 'recent'
    | 'device_favorite'
    | 'message'
    | 'unknown'

export interface StickerLibraryItem {
  /** Opaque Laravel-owned identifier. Never a storage key or WhatsApp path. */
  id: string
  label?: string | null
  source: StickerLibrarySource
  available: boolean
  unavailable_reason?: string | null
  app_favorite: boolean
  device_favorite: boolean
  animated?: boolean
  mime_type?: string | null
  byte_size?: number | null
  last_observed_at?: string | null
  created_at?: string | null
}

export interface StickerLibraryListMeta {
  current_page: number
  last_page: number
  per_page?: number
  total?: number
  sync_status: StickerLibrarySyncStatus
  sync_reason?: string | null
  last_observed_at?: string | null
}

export interface StickerLibraryListResponse {
  data: StickerLibraryItem[]
  meta: StickerLibraryListMeta
}

export type StickerLibraryViewStatus
  = | 'idle'
    | 'loading'
    | 'ready'
    | 'partial'
    | 'unavailable'
    | 'empty'
    | 'error'

export interface StickerLibraryView {
  filter: StickerLibraryFilter
  items: StickerLibraryItem[]
  status: StickerLibraryViewStatus
  syncStatus: StickerLibrarySyncStatus | null
  reason: string | null
  page: number
  lastPage: number
  loadingMore: boolean
}

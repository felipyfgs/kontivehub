import type { Ref } from 'vue'
import { reactive, readonly, ref, watch } from 'vue'
import type {
  StickerLibraryFilter,
  StickerLibraryItem,
  StickerLibraryListResponse,
  StickerLibraryView,
  StickerLibraryViewStatus
} from '~/types/communication/sticker-library'

const STICKER_LIBRARY_PAGE_SIZE = 24
const STICKER_LIBRARY_MAX_PAGES = 5

export interface CommunicationStickerLibraryApi {
  list: (
    params: { inbox_id: number, filter: StickerLibraryFilter, page: number, per_page: number },
    options?: { signal?: AbortSignal }
  ) => Promise<StickerLibraryListResponse>
  preview: (id: string, options?: { signal?: AbortSignal }) => Promise<Blob>
  import: (body: { inbox_id: number, file: File }) => Promise<{ data: StickerLibraryItem }>
  favorite: (id: string, favorite: boolean) => Promise<{ data: StickerLibraryItem }>
  remove: (id: string) => Promise<unknown>
}

export interface CommunicationStickerLibraryOptions {
  pageSize?: number
  maxPages?: number
  createObjectURL?: (blob: Blob) => string
  revokeObjectURL?: (url: string) => void
}

function createView(filter: StickerLibraryFilter): StickerLibraryView {
  return {
    filter,
    items: [],
    status: 'idle',
    syncStatus: null,
    reason: null,
    page: 0,
    lastPage: 1,
    loadingMore: false
  }
}

function responseStatus(response: StickerLibraryListResponse): StickerLibraryViewStatus {
  if (response.meta.sync_status === 'failed') return 'unavailable'
  if (response.data.length === 0 && response.meta.sync_status === 'not_observed') return 'unavailable'
  if (response.data.length === 0 && response.meta.sync_status === 'syncing') return 'loading'
  if (response.data.length === 0) return 'empty'
  if (response.meta.sync_status !== 'partial') return 'partial'
  return 'partial'
}

function syncReason(response: StickerLibraryListResponse): string | null {
  if (response.meta.sync_reason) return response.meta.sync_reason
  if (response.meta.sync_status === 'not_observed') {
    return 'O dispositivo ainda não forneceu figurinhas. A sincronização é parcial.'
  }
  if (response.meta.sync_status === 'syncing') {
    return 'As figurinhas observadas no dispositivo estão sendo sincronizadas.'
  }
  if (response.meta.sync_status === 'failed') {
    return 'A sincronização do dispositivo está indisponível. Você ainda pode usar um arquivo local.'
  }
  if (response.meta.sync_status === 'partial') {
    return 'A biblioteca mostra somente figurinhas observadas no dispositivo; a coleção pode estar incompleta.'
  }
  return null
}

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

function opaqueStickerId(id: string): boolean {
  return /^[A-Za-z0-9_-]{8,128}$/.test(id)
}

export function createCommunicationStickerLibrary(
  api: CommunicationStickerLibraryApi,
  inboxId: Ref<number | null>,
  options: CommunicationStickerLibraryOptions = {}
) {
  const pageSize = Math.min(48, Math.max(1, options.pageSize ?? STICKER_LIBRARY_PAGE_SIZE))
  const maxPages = Math.min(10, Math.max(1, options.maxPages ?? STICKER_LIBRARY_MAX_PAGES))
  const createObjectURL = options.createObjectURL ?? URL.createObjectURL.bind(URL)
  const revokeObjectURL = options.revokeObjectURL ?? URL.revokeObjectURL.bind(URL)
  const views = reactive<Record<StickerLibraryFilter, StickerLibraryView>>({
    recent: createView('recent'),
    favorites: createView('favorites')
  })
  const previewUrls = ref(new Map<string, string>())
  const mutatingIds = ref(new Set<string>())
  const importing = ref(false)
  const importError = ref<string | null>(null)
  const listControllers = new Map<StickerLibraryFilter, AbortController>()
  const previewControllers = new Map<string, AbortController>()
  const epochs: Record<StickerLibraryFilter, number> = { recent: 0, favorites: 0 }
  let contextEpoch = 0

  function reset() {
    contextEpoch++
    for (const controller of listControllers.values()) controller.abort()
    for (const controller of previewControllers.values()) controller.abort()
    listControllers.clear()
    previewControllers.clear()
    for (const url of previewUrls.value.values()) revokeObjectURL(url)
    previewUrls.value = new Map()
    views.recent = createView('recent')
    views.favorites = createView('favorites')
    importing.value = false
    importError.value = null
  }

  function updateEveryView(item: StickerLibraryItem) {
    for (const filter of ['recent', 'favorites'] as const) {
      const index = views[filter].items.findIndex(current => current.id === item.id)
      if (index >= 0) views[filter].items.splice(index, 1, item)
      if (filter === 'favorites' && !item.app_favorite && !item.device_favorite && index >= 0) {
        views[filter].items.splice(index, 1)
      }
    }
  }

  async function loadPreviews(items: readonly StickerLibraryItem[], requestContextEpoch: number) {
    await Promise.all(items.filter(item => item.available && !previewUrls.value.has(item.id)).map(async (item) => {
      if (!opaqueStickerId(item.id)) return
      const controller = new AbortController()
      previewControllers.get(item.id)?.abort()
      previewControllers.set(item.id, controller)
      try {
        const blob = await api.preview(item.id, { signal: controller.signal })
        if (requestContextEpoch !== contextEpoch || controller.signal.aborted) return
        const mime = blob.type.split(';')[0]?.toLowerCase()
        if (mime !== 'image/webp') return
        const next = new Map(previewUrls.value)
        const previous = next.get(item.id)
        if (previous) revokeObjectURL(previous)
        next.set(item.id, createObjectURL(blob))
        previewUrls.value = next
      } catch (error) {
        if (!isAbortError(error)) {
          // A failed preview remains unavailable visually; list metadata stays truthful.
        }
      } finally {
        if (previewControllers.get(item.id) === controller) previewControllers.delete(item.id)
      }
    }))
  }

  async function load(filter: StickerLibraryFilter, append = false) {
    const currentInboxId = inboxId.value
    if (!currentInboxId) {
      views[filter] = createView(filter)
      return
    }

    const nextPage = append ? views[filter].page + 1 : 1
    if (nextPage > maxPages || (append && nextPage > views[filter].lastPage)) return
    const epoch = ++epochs[filter]
    const requestContextEpoch = contextEpoch
    listControllers.get(filter)?.abort()
    const controller = new AbortController()
    listControllers.set(filter, controller)
    if (append) views[filter].loadingMore = true
    else {
      views[filter].status = 'loading'
      views[filter].reason = null
    }

    try {
      const response = await api.list({
        inbox_id: currentInboxId,
        filter,
        page: nextPage,
        per_page: pageSize
      }, { signal: controller.signal })
      if (epoch !== epochs[filter]
        || requestContextEpoch !== contextEpoch
        || currentInboxId !== inboxId.value) return

      const items = append
        ? [...views[filter].items, ...response.data.filter(item => !views[filter].items.some(current => current.id === item.id))]
        : response.data
      views[filter].items = items.slice(0, pageSize * maxPages)
      views[filter].page = Math.min(response.meta.current_page || nextPage, maxPages)
      views[filter].lastPage = Math.min(response.meta.last_page || 1, maxPages)
      views[filter].syncStatus = response.meta.sync_status
      views[filter].status = responseStatus({ ...response, data: views[filter].items })
      views[filter].reason = syncReason(response)
      void loadPreviews(response.data, requestContextEpoch)
    } catch (error) {
      if (isAbortError(error) || epoch !== epochs[filter]) return
      views[filter].status = 'error'
      views[filter].reason = 'Não foi possível carregar a biblioteca de figurinhas.'
    } finally {
      if (epoch === epochs[filter]) {
        views[filter].loadingMore = false
        if (listControllers.get(filter) === controller) listControllers.delete(filter)
      }
    }
  }

  async function refresh() {
    await Promise.all([load('recent'), load('favorites')])
  }

  async function materialize(item: StickerLibraryItem): Promise<File> {
    if (!item.available || !opaqueStickerId(item.id)) {
      throw new Error(item.unavailable_reason || 'Esta figurinha não está disponível para envio.')
    }
    const blob = await api.preview(item.id)
    const mime = blob.type.split(';')[0]?.toLowerCase()
    if (mime !== 'image/webp') throw new Error('A figurinha privada retornou um formato inválido.')
    const safeId = item.id.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 48)
    return new File([blob], `figurinha-${safeId}.webp`, { type: 'image/webp' })
  }

  async function importSticker(file: File): Promise<StickerLibraryItem> {
    const currentInboxId = inboxId.value
    if (!currentInboxId) throw new Error('Selecione uma caixa de entrada antes de importar.')
    if (file.type !== 'image/webp' && !file.name.toLocaleLowerCase('pt-BR').endsWith('.webp')) {
      throw new Error('Selecione uma figurinha WebP para importar.')
    }
    importing.value = true
    importError.value = null
    try {
      const response = await api.import({ inbox_id: currentInboxId, file })
      await refresh()
      return response.data
    } catch (error) {
      importError.value = error instanceof Error
        ? error.message
        : 'Não foi possível importar a figurinha.'
      throw error
    } finally {
      importing.value = false
    }
  }

  async function toggleFavorite(item: StickerLibraryItem) {
    if (mutatingIds.value.has(item.id) || !opaqueStickerId(item.id)) return
    mutatingIds.value = new Set(mutatingIds.value).add(item.id)
    try {
      const response = await api.favorite(item.id, !item.app_favorite)
      updateEveryView(response.data)
    } finally {
      const next = new Set(mutatingIds.value)
      next.delete(item.id)
      mutatingIds.value = next
    }
  }

  async function remove(item: StickerLibraryItem) {
    if (!opaqueStickerId(item.id)) return
    await api.remove(item.id)
    for (const filter of ['recent', 'favorites'] as const) {
      views[filter].items = views[filter].items.filter(current => current.id !== item.id)
    }
    const url = previewUrls.value.get(item.id)
    if (url) revokeObjectURL(url)
    const next = new Map(previewUrls.value)
    next.delete(item.id)
    previewUrls.value = next
  }

  watch(inboxId, reset, { flush: 'sync' })

  return {
    views: readonly(views),
    previewUrls: readonly(previewUrls),
    mutatingIds: readonly(mutatingIds),
    importing: readonly(importing),
    importError: readonly(importError),
    load,
    refresh,
    materialize,
    importSticker,
    toggleFavorite,
    remove,
    dispose: reset
  }
}

export function useCommunicationStickerLibrary(inboxId: Ref<number | null>) {
  const api = useApi()
  const library = createCommunicationStickerLibrary({
    list: api.communication.stickers.list,
    preview: api.communication.stickers.preview,
    import: api.communication.stickers.import,
    favorite: api.communication.stickers.favorite,
    remove: api.communication.stickers.remove
  }, inboxId)
  onBeforeUnmount(library.dispose)
  return library
}

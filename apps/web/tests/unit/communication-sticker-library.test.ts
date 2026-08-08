import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { createCommunicationApi } from '~/composables/api/createCommunicationApi'
import {
  createCommunicationStickerLibrary,
  type CommunicationStickerLibraryApi
} from '~/composables/useCommunicationStickerLibrary'
import type {
  StickerLibraryItem,
  StickerLibraryListResponse
} from '~/types/communication/sticker-library'

function sticker(overrides: Partial<StickerLibraryItem> = {}): StickerLibraryItem {
  return {
    id: 'sticker_12345678',
    label: 'Saudação',
    source: 'recent',
    available: true,
    app_favorite: false,
    device_favorite: false,
    ...overrides
  }
}

function response(
  data: StickerLibraryItem[],
  syncStatus: StickerLibraryListResponse['meta']['sync_status'] = 'partial'
): StickerLibraryListResponse {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      sync_status: syncStatus
    }
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((accept) => {
    resolve = accept
  })
  return { promise, resolve }
}

function libraryApi(overrides: Partial<CommunicationStickerLibraryApi> = {}): CommunicationStickerLibraryApi {
  return {
    list: vi.fn().mockResolvedValue(response([])),
    preview: vi.fn().mockResolvedValue(new Blob(['webp'], { type: 'image/webp' })),
    import: vi.fn().mockResolvedValue({ data: sticker({ source: 'local_import' }) }),
    favorite: vi.fn().mockImplementation(async (_id, favorite) => ({
      data: sticker({ app_favorite: favorite })
    })),
    remove: vi.fn().mockResolvedValue(undefined),
    ...overrides
  }
}

describe('communication sticker library API', () => {
  it('usa somente rotas Laravel, preview privado e multipart para importação', async () => {
    const client = vi.fn().mockResolvedValue({ data: sticker() })
    const api = createCommunicationApi(client, value => value)
    const file = new File(['webp'], 'figurinha.webp', { type: 'image/webp' })

    await api.communication.stickers.list({
      inbox_id: 42,
      filter: 'recent',
      page: 1,
      per_page: 24
    })
    await api.communication.stickers.preview('sticker_12345678')
    await api.communication.stickers.import({ inbox_id: 42, file })
    await api.communication.stickers.favorite('sticker_12345678', true)

    expect(client).toHaveBeenCalledWith('/api/v1/communication/inboxes/42/stickers', expect.objectContaining({
      query: expect.objectContaining({ page: 1, per_page: 24 })
    }))
    expect(client).toHaveBeenCalledWith(
      '/api/v1/communication/stickers/sticker_12345678/preview',
      expect.objectContaining({ responseType: 'blob', headers: { Accept: 'image/webp' } })
    )
    const importCall = client.mock.calls.find(call => call[1]?.method === 'POST')
    expect(importCall?.[1]?.body).toBeInstanceOf(FormData)
    expect(importCall?.[0]).toBe('/api/v1/communication/inboxes/42/stickers/import')
    expect(importCall?.[1]?.body.get('file')).toEqual(expect.objectContaining({
      name: 'figurinha.webp', type: 'image/webp', size: 4
    }))
    expect(client).toHaveBeenCalledWith(
      '/api/v1/communication/stickers/sticker_12345678/favorite',
      { method: 'PUT', body: { favorite: true } }
    )
  })

  it('rejeita IDs que poderiam escapar da rota privada', async () => {
    const client = vi.fn()
    const api = createCommunicationApi(client, value => value)

    await expect(api.communication.stickers.preview('../storage/key')).rejects.toThrow('Figurinha inválida.')
    await expect(api.communication.stickers.remove('https://provider.example/id')).rejects.toThrow('Figurinha inválida.')
    expect(client).not.toHaveBeenCalled()
  })
})

describe('communication sticker library state', () => {
  it('ignora respostas antigas ao trocar de inbox e mantém a paginação limitada', async () => {
    const first = deferred<StickerLibraryListResponse>()
    const list = vi.fn()
      .mockImplementationOnce(() => first.promise)
      .mockResolvedValueOnce(response([sticker({ id: 'sticker_new_inbox' })]))
    const inboxId = ref<number | null>(1)
    const library = createCommunicationStickerLibrary(libraryApi({ list }), inboxId, {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn(),
      maxPages: 2
    })

    const staleLoad = library.load('recent')
    inboxId.value = 2
    await library.load('recent')
    first.resolve(response([sticker({ id: 'sticker_old_inbox' })]))
    await staleLoad

    expect(library.views.recent.items.map(item => item.id)).toEqual(['sticker_new_inbox'])
    expect(library.views.recent.status).toBe('partial')
    expect(list.mock.calls[0]?.[1]?.signal.aborted).toBe(true)
  })

  it('distingue ausência de bootstrap de vazio e materializa seleção como File WebP', async () => {
    const api = libraryApi({
      list: vi.fn().mockResolvedValue(response([], 'not_observed')),
      preview: vi.fn().mockResolvedValue(new Blob(['webp'], { type: 'image/webp' }))
    })
    const library = createCommunicationStickerLibrary(api, ref(7), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    await library.load('recent')
    const file = await library.materialize(sticker())

    expect(library.views.recent.status).toBe('unavailable')
    expect(library.views.recent.reason).toContain('sincronização é parcial')
    expect(file).toMatchObject({ type: 'image/webp', name: 'figurinha-sticker_12345678.webp' })
  })

  it('separa favorito do KontiveHub do favorito observado no dispositivo', async () => {
    const current = sticker({ device_favorite: true, app_favorite: false })
    const api = libraryApi({
      list: vi.fn().mockResolvedValue(response([current])),
      favorite: vi.fn().mockResolvedValue({
        data: sticker({ device_favorite: true, app_favorite: true })
      })
    })
    const library = createCommunicationStickerLibrary(api, ref(9), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    await library.load('recent')
    await library.toggleFavorite(current)

    expect(api.favorite).toHaveBeenCalledWith(current.id, true)
    expect(library.views.recent.items[0]?.app_favorite).toBe(true)
    expect(library.views.recent.items[0]?.device_favorite).toBe(true)
  })

  it('trata importação local com sucesso e falha de validação', async () => {
    const imported = sticker({ id: 'sticker_imported_99', source: 'local_import' })
    const api = libraryApi({
      list: vi.fn().mockResolvedValue(response([imported])),
      import: vi.fn().mockResolvedValue({ data: imported })
    })
    const library = createCommunicationStickerLibrary(api, ref(11), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    const file = new File(['webp'], 'nova.webp', { type: 'image/webp' })
    const result = await library.importSticker(file)
    expect(result.id).toBe(imported.id)
    expect(library.views.recent.items).toHaveLength(1)
    expect(library.importing.value).toBe(false)
    expect(library.importError.value).toBeNull()

    const invalidFile = new File(['png'], 'foto.png', { type: 'image/png' })
    await expect(library.importSticker(invalidFile)).rejects.toThrow('Selecione uma figurinha WebP')
  })

  it('classifica falha de carregamento e recupera após retry', async () => {
    const list = vi.fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(response([sticker({ id: 'sticker_retry_ok' })]))
    const library = createCommunicationStickerLibrary(libraryApi({ list }), ref(12), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    await library.load('recent')
    expect(library.views.recent.status).toBe('error')
    expect(library.views.recent.reason).toContain('Não foi possível carregar')

    await library.load('recent')
    expect(library.views.recent.status).toBe('partial')
    expect(library.views.recent.items[0]?.id).toBe('sticker_retry_ok')
  })

  it('nega acesso de outro tenant sem revelar existência', async () => {
    const forbidden = Object.assign(new Error('Forbidden'), { status: 403 })
    const api = libraryApi({
      list: vi.fn().mockRejectedValue(forbidden)
    })
    const library = createCommunicationStickerLibrary(api, ref(13), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    await library.load('recent')
    expect(library.views.recent.status).toBe('error')
    expect(api.list).toHaveBeenCalledWith(expect.objectContaining({ inbox_id: 13 }), expect.any(Object))
  })

  it('falha fechada ao materializar figurinha indisponível ou com MIME inválido', async () => {
    const unavailable = sticker({ available: false, unavailable_reason: 'Mídia expirada' })
    const api = libraryApi({
      preview: vi.fn().mockResolvedValue(new Blob(['png'], { type: 'image/png' }))
    })
    const library = createCommunicationStickerLibrary(api, ref(14), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn()
    })

    await expect(library.materialize(unavailable)).rejects.toThrow('Mídia expirada')
    await expect(library.materialize(sticker({ id: 'bad id!' }))).rejects.toThrow('não está disponível')

    const valid = sticker({ id: 'sticker_valid_01' })
    await expect(library.materialize(valid)).rejects.toThrow('formato inválido')
  })

  it('respeita paginação limitada e deduplicação por digest', async () => {
    const a = sticker({ id: 'sticker_a1' })
    const b = sticker({ id: 'sticker_b2' })
    const list = vi.fn()
      .mockResolvedValueOnce({ data: [a], meta: { current_page: 1, last_page: 2, sync_status: 'partial' } })
      .mockResolvedValueOnce({ data: [a, b], meta: { current_page: 2, last_page: 2, sync_status: 'partial' } })
    const library = createCommunicationStickerLibrary(libraryApi({ list }), ref(15), {
      createObjectURL: () => 'blob:preview',
      revokeObjectURL: vi.fn(),
      maxPages: 2,
      pageSize: 24
    })

    await library.load('recent')
    await library.load('recent', true)
    expect(library.views.recent.items.map(i => i.id)).toEqual(['sticker_a1', 'sticker_b2'])
    expect(library.views.recent.page).toBe(2)
  })

  it('remove preview e revoga URL ao excluir item', async () => {
    const item = sticker({ id: 'sticker_rm_01' })
    const revoke = vi.fn()
    const api = libraryApi({
      list: vi.fn().mockResolvedValue(response([item])),
      preview: vi.fn().mockResolvedValue(new Blob(['webp'], { type: 'image/webp' }))
    })
    const library = createCommunicationStickerLibrary(api, ref(16), {
      createObjectURL: () => 'blob:sticker_rm_01',
      revokeObjectURL: revoke
    })

    await library.load('recent')
    await new Promise(resolve => setTimeout(resolve, 10))
    await library.remove(item)
    expect(library.views.recent.items).toHaveLength(0)
    expect(revoke).toHaveBeenCalledWith('blob:sticker_rm_01')
  })
})

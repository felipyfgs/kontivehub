import { describe, expect, it, vi } from 'vitest'
import { createCommunicationApi } from '~/composables/api/createCommunicationApi'

describe('communication GIF API', () => {
  it('expõe resultados Laravel completos e busca somente asset privado como Blob', async () => {
    const client = vi.fn().mockResolvedValue(new Blob(['gif'], { type: 'video/mp4' }))
    const api = createCommunicationApi(client, value => value)
    const token = 'AbC123dEf456GhI789jKl012MnO345pQr678StUv'

    await expect(api.communication.catalog.fetchGifAsset(`/api/v1/communication/gifs/${token}/asset`)).resolves.toBeInstanceOf(Blob)
    expect(client).toHaveBeenCalledWith(`/api/v1/communication/gifs/${token}/asset`, expect.objectContaining({
      method: 'GET',
      responseType: 'blob',
      headers: { Accept: 'video/mp4,video/webm' }
    }))
  })

  it('rejeita qualquer URL ou rota que não seja o asset privado exato', async () => {
    const client = vi.fn()
    const api = createCommunicationApi(client, value => value)

    await expect(api.communication.catalog.fetchGifAsset('https://provider.example/gif.mp4')).rejects.toThrow('Arquivo de GIF inválido.')
    await expect(api.communication.catalog.fetchGifAsset('/api/v1/communication/gifs/short/asset')).rejects.toThrow('Arquivo de GIF inválido.')
    expect(client).not.toHaveBeenCalled()
  })

  it('carrega capabilities efetivas para a inbox selecionada', async () => {
    const client = vi.fn().mockResolvedValue({ data: { enabled: true } })
    const api = createCommunicationApi(client, value => value)

    await api.communication.catalog.outboundCapabilities({ inbox_id: 42 })

    expect(client).toHaveBeenCalledWith(
      '/api/v1/communication/outbound-capabilities',
      { query: { inbox_id: 42 }, timeout: 10_000 }
    )
  })
})

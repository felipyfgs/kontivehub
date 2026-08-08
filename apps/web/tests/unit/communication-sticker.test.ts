import { describe, expect, it, vi } from 'vitest'
import { CommunicationStickerError, createCommunicationSticker } from '~/utils/communication-sticker'

describe('communication sticker', () => {
  it('recorta, limita a dimensão e produz WebP liberando a URL de origem', async () => {
    const drawImage = vi.fn()
    const revokeObjectURL = vi.fn()
    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({ drawImage }),
      toBlob: (callback: BlobCallback) => callback(new Blob(['webp'], { type: 'image/webp' }))
    } as unknown as HTMLCanvasElement
    const sticker = await createCommunicationSticker(new File(['image'], 'foto.png', { type: 'image/png' }), {
      crop: { x: 20, y: 10, width: 400, height: 200 }, maxDimension: 200, maxBytes: 100
    }, {
      createObjectURL: () => 'blob:source',
      revokeObjectURL,
      loadImage: vi.fn().mockResolvedValue({ width: 800, height: 600 }),
      createCanvas: () => canvas
    })

    expect(sticker).toMatchObject({ name: 'figurinha.webp', type: 'image/webp' })
    expect(canvas).toMatchObject({ width: 200, height: 100 })
    expect(drawImage).toHaveBeenCalledWith(expect.anything(), 20, 10, 400, 200, 0, 0, 200, 100)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:source')
  })

  it('falha explicitamente para recorte inválido e WebP acima do limite', async () => {
    const source = new File(['image'], 'foto.png', { type: 'image/png' })
    const base = { createObjectURL: () => 'blob:source', revokeObjectURL: vi.fn(), loadImage: vi.fn().mockResolvedValue({ width: 20, height: 20 }) }
    await expect(createCommunicationSticker(source, { crop: { x: 10, y: 10, width: 20, height: 20 }, maxDimension: 20, maxBytes: 10 }, base)).rejects.toBeInstanceOf(CommunicationStickerError)
    await expect(createCommunicationSticker(source, { maxDimension: 20, maxBytes: 1 }, {
      ...base,
      createCanvas: () => ({ getContext: () => ({ drawImage: vi.fn() }), toBlob: (callback: BlobCallback) => callback(new Blob(['too-large'], { type: 'image/webp' })) } as unknown as HTMLCanvasElement)
    })).rejects.toThrow('excede o limite')
  })
})

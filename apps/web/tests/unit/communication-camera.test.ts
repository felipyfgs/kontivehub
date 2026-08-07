import { describe, expect, it, vi } from 'vitest'
import { useCommunicationCamera } from '~/composables/useCommunicationCamera'

function stream() {
  const track = { stop: vi.fn() }
  return { getTracks: () => [track], track } as unknown as MediaStream & { track: { stop: ReturnType<typeof vi.fn> } }
}

describe('communication camera', () => {
  it('captura um File normal e libera stream e URL temporário', async () => {
    const activeStream = stream()
    const revokeObjectURL = vi.fn()
    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({ drawImage: vi.fn() }),
      toBlob: (callback: BlobCallback) => callback(new Blob(['foto'], { type: 'image/jpeg' }))
    } as unknown as HTMLCanvasElement
    const camera = useCommunicationCamera({
      mediaDevices: { getUserMedia: vi.fn().mockResolvedValue(activeStream) },
      createCanvas: () => canvas,
      createObjectURL: () => 'blob:camera',
      revokeObjectURL
    })

    await camera.start()
    const file = await camera.capture({ videoWidth: 640, videoHeight: 480 } as HTMLVideoElement)

    expect(file).toMatchObject({ name: 'camera.jpg', type: 'image/jpeg' })
    expect(camera.state.value).toBe('preview')
    expect(activeStream.track.stop).toHaveBeenCalledOnce()
    camera.dispose()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:camera')
  })

  it('oferece fallback de arquivo quando a API não existe ou a permissão é negada', async () => {
    const unsupported = useCommunicationCamera({ mediaDevices: undefined })
    expect(await unsupported.start()).toBe(false)
    expect(unsupported.state.value).toBe('fallback')

    const denied = useCommunicationCamera({
      mediaDevices: { getUserMedia: vi.fn().mockRejectedValue(new DOMException('denied', 'NotAllowedError')) }
    })
    expect(await denied.start()).toBe(false)
    expect(denied.error.value).toContain('Permissão')
  })
})

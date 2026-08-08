import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { isPttCompatibleMime, recordedAudioExtension, useCommunicationVoiceRecorder } from '~/composables/useCommunicationVoiceRecorder'

class FakeMediaRecorder {
  static instance: FakeMediaRecorder | null = null
  mimeType = 'audio/ogg; codecs=opus'
  ondataavailable: ((event: BlobEvent) => void) | null = null
  onstop: (() => void) | null = null
  start = vi.fn()
  pause = vi.fn()
  resume = vi.fn()
  stop = vi.fn(() => {
    this.ondataavailable?.({ data: new Blob(['voice'], { type: this.mimeType }) } as BlobEvent)
    this.onstop?.()
  })

  constructor(_stream: MediaStream) {
    FakeMediaRecorder.instance = this
  }
}

function mediaStream() {
  const track = { stop: vi.fn() }
  return { getTracks: () => [track], track } as unknown as MediaStream & { track: { stop: ReturnType<typeof vi.fn> } }
}

describe('communication voice recorder', () => {
  it('segue gravação, pausa, preview, envio e limpa track e URL', async () => {
    let time = 0
    const stream = mediaStream()
    const revokeObjectURL = vi.fn()
    const recorder = useCommunicationVoiceRecorder({ maxBytes: 100, maxDurationSeconds: 60 }, {
      mediaDevices: { getUserMedia: vi.fn().mockResolvedValue(stream) },
      MediaRecorder: FakeMediaRecorder as unknown as typeof MediaRecorder,
      createObjectURL: () => 'blob:voice',
      revokeObjectURL,
      now: () => time,
      setInterval: () => 1 as unknown as ReturnType<typeof setInterval>,
      clearInterval: vi.fn()
    })

    await recorder.start()
    expect(recorder.state.value).toBe('recording')
    time = 2_000
    recorder.pause()
    expect(recorder.state.value).toBe('paused')
    recorder.resume()
    recorder.stop()

    expect(recorder.state.value).toBe('preview')
    expect(recorder.recorded.value).toMatchObject({ extension: 'ogg', ptt: true, durationSeconds: 2 })
    expect(stream.track.stop).toHaveBeenCalledOnce()
    expect(recorder.beginSending()).toMatchObject({ ptt: true })
    recorder.finishSending()
    expect(recorder.state.value).toBe('idle')
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:voice')
  })

  it('limita waveform, aplica limites e mantém erro recuperável', async () => {
    const stream = mediaStream()
    const recorder = useCommunicationVoiceRecorder({ maxBytes: 2, maxDurationSeconds: 60 }, {
      mediaDevices: { getUserMedia: vi.fn().mockResolvedValue(stream) },
      MediaRecorder: FakeMediaRecorder as unknown as typeof MediaRecorder,
      createObjectURL: () => 'blob:voice',
      revokeObjectURL: vi.fn()
    })
    await recorder.start()
    for (let index = 0; index < 70; index++) recorder.appendWaveformSample(index / 70)
    expect(recorder.waveform.value).toHaveLength(64)
    recorder.stop()
    expect(recorder.state.value).toBe('error')
    expect(recorder.error.value).toContain('tamanho máximo')
    recorder.recover()
    expect(recorder.state.value).toBe('idle')
  })

  it('preserva o preview para recuperar um ACK negativo e reenviar', async () => {
    const stream = mediaStream()
    const revokeObjectURL = vi.fn()
    const recorder = useCommunicationVoiceRecorder({ maxBytes: 100, maxDurationSeconds: 60 }, {
      mediaDevices: { getUserMedia: vi.fn().mockResolvedValue(stream) },
      MediaRecorder: FakeMediaRecorder as unknown as typeof MediaRecorder,
      createObjectURL: () => 'blob:voice',
      revokeObjectURL
    })

    await recorder.start()
    recorder.stop()
    const original = recorder.recorded.value
    expect(recorder.beginSending()).toBe(original)
    expect(recorder.failSending('A fila não aceitou a gravação.')).toBe(true)
    expect(recorder.state.value).toBe('error')
    expect(recorder.recorded.value).toBe(original)
    expect(recorder.previewUrl.value).toBe('blob:voice')
    expect(revokeObjectURL).not.toHaveBeenCalled()

    recorder.recover()
    expect(recorder.state.value).toBe('preview')
    expect(recorder.beginSending()).toBe(original)
    recorder.finishSending()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:voice')
  })

  it('deriva extensão e PTT do MIME efetivamente gravado', () => {
    expect(recordedAudioExtension('audio/ogg; codecs=opus')).toBe('ogg')
    expect(recordedAudioExtension('audio/webm')).toBe('webm')
    expect(isPttCompatibleMime('audio/ogg; codecs=opus')).toBe(true)
    expect(isPttCompatibleMime('audio/webm')).toBe(false)
  })

  it('integra o composer ao recorder sem manter o controle legado', () => {
    const composer = readFileSync(resolve(process.cwd(), 'app/components/communication/Composer.vue'), 'utf8')

    expect(composer).toContain('useCommunicationVoiceRecorder')
    expect(composer).toContain('voiceRecorder.beginSending()')
    expect(composer).toContain('voiceRecorder.failSending')
    expect(composer).toContain('voiceRecorder.finishSending()')
    expect(composer).toContain('voiceRecorder.dispose()')
    expect(composer).not.toContain('let mediaRecorder')
    expect(composer).not.toContain('let mediaStream')
    expect(composer).not.toContain('let recordingChunks')
  })
})

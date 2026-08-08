import { computed, ref } from 'vue'

export type CommunicationVoiceRecorderState = 'idle' | 'recording' | 'paused' | 'preview' | 'sending' | 'error'

export interface CommunicationVoiceRecorderLimits {
  maxBytes: number
  maxDurationSeconds: number
}

export interface CommunicationVoiceRecorderDependencies {
  mediaDevices?: Pick<MediaDevices, 'getUserMedia'>
  MediaRecorder?: typeof MediaRecorder
  createObjectURL?: (value: Blob) => string
  revokeObjectURL?: (url: string) => void
  now?: () => number
  setInterval?: typeof globalThis.setInterval
  clearInterval?: typeof globalThis.clearInterval
}

export interface RecordedVoice {
  file: File
  mimeType: string
  extension: string
  durationSeconds: number
  ptt: boolean
}

const extensions: Record<string, string> = {
  'audio/ogg': 'ogg',
  'audio/webm': 'webm',
  'audio/mp4': 'm4a',
  'audio/mpeg': 'mp3',
  'audio/wav': 'wav'
}

export function recordedAudioExtension(mimeType: string): string {
  const primaryMime = mimeType.split(';')[0]?.toLowerCase() ?? ''
  return extensions[primaryMime] ?? 'webm'
}

export function isPttCompatibleMime(mimeType: string): boolean {
  const normalized = mimeType.toLowerCase()
  return normalized.startsWith('audio/ogg') || normalized.startsWith('audio/opus')
}

export function useCommunicationVoiceRecorder(
  limits: CommunicationVoiceRecorderLimits,
  dependencies: CommunicationVoiceRecorderDependencies = {}
) {
  const mediaDevices = dependencies.mediaDevices ?? globalThis.navigator?.mediaDevices
  const Recorder = dependencies.MediaRecorder ?? globalThis.MediaRecorder
  const createObjectURL = dependencies.createObjectURL ?? URL.createObjectURL.bind(URL)
  const revokeObjectURL = dependencies.revokeObjectURL ?? URL.revokeObjectURL.bind(URL)
  const now = dependencies.now ?? Date.now
  const setTimer = dependencies.setInterval ?? globalThis.setInterval
  const clearTimerHandle = dependencies.clearInterval ?? globalThis.clearInterval
  const state = ref<CommunicationVoiceRecorderState>('idle')
  const error = ref<string | null>(null)
  const durationSeconds = ref(0)
  const waveform = ref<number[]>([])
  const previewUrl = ref<string | null>(null)
  const recorded = ref<RecordedVoice | null>(null)
  let stream: MediaStream | null = null
  let recorder: MediaRecorder | null = null
  let startedAt = 0
  let elapsedBeforePause = 0
  let timer: ReturnType<typeof setInterval> | null = null
  let chunks: Blob[] = []
  let audioContext: AudioContext | null = null
  let analyser: AnalyserNode | null = null

  function stopTracks() {
    stream?.getTracks().forEach(track => track.stop())
    stream = null
  }
  function clearActiveTimer() {
    if (timer) clearTimerHandle(timer)
    timer = null
  }
  function revokePreview() {
    if (previewUrl.value) revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }
  function resetTerminal(next: CommunicationVoiceRecorderState = 'idle') {
    clearActiveTimer()
    stopTracks()
    recorder = null
    analyser?.disconnect()
    analyser = null
    if (audioContext && audioContext.state !== 'closed') void audioContext.close()
    audioContext = null
    chunks = []
    revokePreview()
    recorded.value = null
    durationSeconds.value = 0
    waveform.value = []
    elapsedBeforePause = 0
    state.value = next
  }
  function fail(message: string) {
    resetTerminal('error')
    error.value = message
  }
  function tick() {
    durationSeconds.value = elapsedBeforePause + Math.floor((now() - startedAt) / 1000)
    sampleWaveform()
    if (durationSeconds.value > limits.maxDurationSeconds) {
      recorder?.stop()
      fail('A gravação excede a duração máxima permitida.')
    }
  }
  function appendWaveformSample(value: number) {
    if (!Number.isFinite(value)) return
    waveform.value = [...waveform.value.slice(-63), Math.max(0, Math.min(1, value))]
  }
  function sampleWaveform() {
    if (!analyser) return
    const samples = new Uint8Array(analyser.fftSize)
    analyser.getByteTimeDomainData(samples)
    const average = samples.reduce((total, sample) => total + Math.abs(sample - 128), 0) / samples.length
    appendWaveformSample(average / 128)
  }
  function beginWaveform(stream: MediaStream) {
    const Context = globalThis.AudioContext
    if (!Context) return
    try {
      audioContext = new Context()
      analyser = audioContext.createAnalyser()
      analyser.fftSize = 256
      audioContext.createMediaStreamSource(stream).connect(analyser)
    } catch {
      analyser = null
      if (audioContext && audioContext.state !== 'closed') void audioContext.close()
      audioContext = null
    }
  }
  async function start() {
    resetTerminal()
    error.value = null
    if (!mediaDevices?.getUserMedia || !Recorder) {
      fail('A gravação de voz não é compatível com este navegador.')
      return false
    }
    try {
      stream = await mediaDevices.getUserMedia({ audio: true })
      beginWaveform(stream)
      recorder = new Recorder(stream)
      chunks = []
      recorder.ondataavailable = (event) => {
        if (event.data.size) chunks.push(event.data)
      }
      recorder.onstop = () => finalize()
      startedAt = now()
      recorder.start()
      timer = setTimer(tick, 1000)
      state.value = 'recording'
      return true
    } catch {
      fail('Não foi possível acessar o microfone. Verifique a permissão e tente novamente.')
      return false
    }
  }
  function pause() {
    if (state.value !== 'recording' || !recorder) return
    tick()
    elapsedBeforePause = durationSeconds.value
    clearActiveTimer()
    recorder.pause()
    state.value = 'paused'
  }
  function resume() {
    if (state.value !== 'paused' || !recorder) return
    startedAt = now()
    recorder.resume()
    timer = setTimer(tick, 1000)
    state.value = 'recording'
  }
  function stop() {
    if (!['recording', 'paused'].includes(state.value) || !recorder) return
    if (state.value === 'recording') tick()
    clearActiveTimer()
    recorder.stop()
  }
  function finalize() {
    if (state.value === 'error') return
    clearActiveTimer()
    stopTracks()
    const mimeType = recorder?.mimeType || chunks[0]?.type || 'audio/webm'
    const blob = new Blob(chunks, { type: mimeType })
    recorder = null
    chunks = []
    if (!blob.size) return fail('A gravação não contém áudio.')
    if (blob.size > limits.maxBytes) return fail('A gravação excede o tamanho máximo permitido.')
    if (durationSeconds.value > limits.maxDurationSeconds) return fail('A gravação excede a duração máxima permitida.')
    const extension = recordedAudioExtension(blob.type || mimeType)
    const file = new File([blob], `mensagem-de-voz.${extension}`, { type: blob.type || mimeType })
    revokePreview()
    previewUrl.value = createObjectURL(file)
    recorded.value = { file, mimeType: file.type, extension, durationSeconds: durationSeconds.value, ptt: isPttCompatibleMime(file.type) }
    state.value = 'preview'
  }
  function beginSending() {
    if (state.value !== 'preview' || !recorded.value) return null
    state.value = 'sending'
    return recorded.value
  }
  function finishSending() {
    if (state.value === 'sending') resetTerminal()
  }
  function failSending(message = 'Não foi possível enviar a mensagem de voz. Tente novamente.') {
    if (state.value !== 'sending' || !recorded.value || !previewUrl.value) return false
    error.value = message
    state.value = 'error'
    return true
  }
  function discard() {
    resetTerminal()
    error.value = null
  }
  function recover() {
    if (state.value !== 'error') return
    if (recorded.value && previewUrl.value) {
      error.value = null
      state.value = 'preview'
      return
    }
    discard()
  }

  return {
    state,
    error,
    durationSeconds,
    waveform,
    previewUrl,
    recorded,
    canSend: computed(() => state.value === 'preview' && !!recorded.value),
    start,
    pause,
    resume,
    stop,
    beginSending,
    finishSending,
    failSending,
    discard,
    recover,
    appendWaveformSample,
    dispose: discard
  }
}

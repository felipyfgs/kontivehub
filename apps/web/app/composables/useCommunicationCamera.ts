import { computed, ref, type ComputedRef } from 'vue'

export type CommunicationCameraState = 'idle' | 'streaming' | 'preview' | 'fallback' | 'error'

export interface CommunicationCameraDependencies {
  mediaDevices?: Pick<MediaDevices, 'getUserMedia'>
  createObjectURL?: (value: Blob) => string
  revokeObjectURL?: (url: string) => void
  createCanvas?: () => HTMLCanvasElement
}

export interface CommunicationCamera {
  state: Readonly<ReturnType<typeof ref<CommunicationCameraState>>>
  stream: Readonly<ReturnType<typeof ref<MediaStream | null>>>
  previewUrl: Readonly<ReturnType<typeof ref<string | null>>>
  capturedFile: Readonly<ReturnType<typeof ref<File | null>>>
  error: Readonly<ReturnType<typeof ref<string | null>>>
  fallbackAvailable: ComputedRef<boolean>
  start: () => Promise<boolean>
  capture: (video: HTMLVideoElement, fileName?: string) => Promise<File | null>
  clear: () => void
  dispose: () => void
}

function fallbackMessage(error: unknown): string {
  if (error instanceof DOMException && error.name === 'NotAllowedError') return 'Permissão para câmera negada. Selecione um arquivo para continuar.'
  if (error instanceof DOMException && error.name === 'NotFoundError') return 'Nenhuma câmera foi encontrada. Selecione um arquivo para continuar.'
  return 'Não foi possível abrir a câmera. Selecione um arquivo para continuar.'
}

export function useCommunicationCamera(dependencies: CommunicationCameraDependencies = {}): CommunicationCamera {
  const mediaDevices = dependencies.mediaDevices ?? globalThis.navigator?.mediaDevices
  const createObjectURL = dependencies.createObjectURL ?? URL.createObjectURL.bind(URL)
  const revokeObjectURL = dependencies.revokeObjectURL ?? URL.revokeObjectURL.bind(URL)
  const createCanvas = dependencies.createCanvas ?? (() => document.createElement('canvas'))
  const state = ref<CommunicationCameraState>('idle')
  const stream = ref<MediaStream | null>(null)
  const previewUrl = ref<string | null>(null)
  const capturedFile = ref<File | null>(null)
  const error = ref<string | null>(null)

  function stopTracks() {
    stream.value?.getTracks().forEach(track => track.stop())
    stream.value = null
  }

  function revokePreview() {
    if (previewUrl.value) revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }

  function clear() {
    stopTracks()
    revokePreview()
    capturedFile.value = null
    error.value = null
    state.value = 'idle'
  }

  async function start(): Promise<boolean> {
    clear()
    if (!mediaDevices?.getUserMedia) {
      state.value = 'fallback'
      error.value = 'Câmera não é compatível com este navegador. Selecione um arquivo para continuar.'
      return false
    }
    try {
      stream.value = await mediaDevices.getUserMedia({ video: true })
      state.value = 'streaming'
      return true
    } catch (cause) {
      state.value = 'fallback'
      error.value = fallbackMessage(cause)
      return false
    }
  }

  async function capture(video: HTMLVideoElement, fileName = 'camera.jpg'): Promise<File | null> {
    if (state.value !== 'streaming') {
      error.value = 'Inicie a câmera antes de capturar uma imagem.'
      state.value = 'error'
      return null
    }
    const width = video.videoWidth || video.clientWidth
    const height = video.videoHeight || video.clientHeight
    if (!width || !height) {
      error.value = 'A câmera ainda não forneceu uma imagem para captura.'
      return null
    }
    const canvas = createCanvas()
    canvas.width = width
    canvas.height = height
    const context = canvas.getContext('2d')
    if (!context) {
      error.value = 'Não foi possível preparar a captura da câmera.'
      state.value = 'error'
      stopTracks()
      return null
    }
    context.drawImage(video, 0, 0, width, height)
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.92))
    if (!blob) {
      error.value = 'Não foi possível gerar a imagem capturada.'
      state.value = 'error'
      stopTracks()
      return null
    }
    stopTracks()
    revokePreview()
    capturedFile.value = new File([blob], fileName, { type: blob.type || 'image/jpeg' })
    previewUrl.value = createObjectURL(capturedFile.value)
    state.value = 'preview'
    return capturedFile.value
  }

  return {
    state,
    stream,
    previewUrl,
    capturedFile,
    error,
    fallbackAvailable: computed(() => state.value === 'fallback'),
    start,
    capture,
    clear,
    dispose: clear
  }
}

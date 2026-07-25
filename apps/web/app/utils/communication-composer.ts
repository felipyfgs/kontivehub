import type { CommunicationSendKind } from '~/types/communication'

export const COMMUNICATION_REACTION_EMOJIS = [
  '👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '🎉',
  '✅', '👀', '🤝', '💡', '📌', '🚀', '😍', '🤔'
] as const

export const COMMUNICATION_RECORDER_MIME_TYPES = [
  'audio/ogg;codecs=opus',
  'audio/mp4',
  'audio/webm;codecs=opus',
  'audio/webm'
] as const

export interface CommunicationComposerKeyEvent {
  key: string
  shiftKey: boolean
  isComposing?: boolean
  keyCode?: number
}

/** Enter envia; Shift+Enter quebra linha; IME nunca dispara envio no meio da composição. */
export function shouldSubmitCommunicationComposer(event: CommunicationComposerKeyEvent): boolean {
  return event.key === 'Enter'
    && !event.shiftKey
    && event.isComposing !== true
    && event.keyCode !== 229
}

export function preferredCommunicationRecorderMimeType(
  supports: (mimeType: string) => boolean
): string | null {
  return COMMUNICATION_RECORDER_MIME_TYPES.find(supports) ?? null
}

export function communicationRecordingExtension(mimeType: string): 'ogg' | 'm4a' | 'webm' {
  const normalized = mimeType.toLowerCase()
  if (normalized.startsWith('audio/ogg')) return 'ogg'
  if (normalized.startsWith('audio/mp4')) return 'm4a'
  return 'webm'
}

export function communicationSendKindForMime(mimeType: string): CommunicationSendKind {
  const normalized = mimeType.toLowerCase().split(';', 1)[0] || ''
  if (normalized.startsWith('image/')) return 'IMAGE'
  if (normalized.startsWith('audio/')) return 'AUDIO'
  if (normalized.startsWith('video/')) return 'VIDEO'
  return 'DOCUMENT'
}

export function formatCommunicationRecordingDuration(totalSeconds: number): string {
  const bounded = Math.max(0, Math.floor(totalSeconds))
  const minutes = Math.floor(bounded / 60)
  const seconds = String(bounded % 60).padStart(2, '0')
  return `${minutes}:${seconds}`
}

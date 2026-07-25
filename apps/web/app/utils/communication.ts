import type {
  CommunicationChatPresenceSignal,
  CommunicationContactPresenceSignal,
  CommunicationConversation,
  CommunicationConversationStatus,
  CommunicationEvent,
  CommunicationInboxStatus,
  CommunicationMessage,
  CommunicationMessagePollVote,
  CommunicationMessageStatus,
  CommunicationRealtimeState
} from '~/types/communication'

export type CommunicationBadgeColor
  = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

export interface CommunicationStatusMeta {
  label: string
  color: CommunicationBadgeColor
  icon: string
}

export interface CommunicationConversationImageEvidence {
  messageId: number
  previewUrl: string | null
}

export const COMMUNICATION_INBOX_STATUS: Record<CommunicationInboxStatus, CommunicationStatusMeta> = {
  DISCONNECTED: { label: 'Desconectado', color: 'neutral', icon: 'i-lucide-circle-off' },
  CONNECTING: { label: 'Conectando', color: 'warning', icon: 'i-lucide-loader-circle' },
  CONNECTED: { label: 'Conectado', color: 'success', icon: 'i-lucide-circle-check' }
}

export const COMMUNICATION_CONVERSATION_STATUS: Record<CommunicationConversationStatus, CommunicationStatusMeta> = {
  OPEN: { label: 'Aberta', color: 'primary', icon: 'i-lucide-message-circle' },
  PENDING: { label: 'Pendente', color: 'warning', icon: 'i-lucide-clock-3' },
  RESOLVED: { label: 'Resolvida', color: 'success', icon: 'i-lucide-circle-check' },
  SNOOZED: { label: 'Adiada', color: 'info', icon: 'i-lucide-alarm-clock' }
}

export const COMMUNICATION_MESSAGE_STATUS: Record<CommunicationMessageStatus, CommunicationStatusMeta> = {
  QUEUED: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock' },
  ACCEPTED: { label: 'Aceita', color: 'info', icon: 'i-lucide-check' },
  SENT: { label: 'Enviada', color: 'info', icon: 'i-lucide-check-check' },
  DELIVERED: { label: 'Entregue', color: 'success', icon: 'i-lucide-check-check' },
  READ: { label: 'Lida', color: 'primary', icon: 'i-lucide-check-check' },
  FAILED: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-x' },
  UNKNOWN: { label: 'Resultado incerto', color: 'warning', icon: 'i-lucide-circle-help' },
  CANCELED: { label: 'Cancelada', color: 'neutral', icon: 'i-lucide-ban' }
}

export const COMMUNICATION_REALTIME_META: Record<CommunicationRealtimeState, CommunicationStatusMeta> = {
  disabled: { label: 'Tempo real desativado', color: 'neutral', icon: 'i-lucide-wifi-off' },
  connecting: { label: 'Conectando', color: 'warning', icon: 'i-lucide-loader-circle' },
  connected: { label: 'Tempo real ativo', color: 'success', icon: 'i-lucide-radio' },
  unavailable: { label: 'Sincronizando por cursor', color: 'warning', icon: 'i-lucide-refresh-cw' }
}

export const COMMUNICATION_MESSAGE_KIND: Record<CommunicationMessage['kind'], CommunicationStatusMeta> = {
  TEXT: { label: 'Mensagem', color: 'neutral', icon: 'i-lucide-message-square-text' },
  IMAGE: { label: 'Imagem', color: 'info', icon: 'i-lucide-image' },
  AUDIO: { label: 'Áudio', color: 'info', icon: 'i-lucide-audio-lines' },
  VIDEO: { label: 'Vídeo', color: 'info', icon: 'i-lucide-video' },
  DOCUMENT: { label: 'Documento', color: 'neutral', icon: 'i-lucide-file-text' },
  STICKER: { label: 'Sticker', color: 'info', icon: 'i-lucide-sticker' },
  LOCATION: { label: 'Localização', color: 'success', icon: 'i-lucide-map-pin' },
  CONTACT: { label: 'Contato', color: 'primary', icon: 'i-lucide-contact' },
  POLL: { label: 'Enquete', color: 'primary', icon: 'i-lucide-list-checks' },
  INTERACTIVE: { label: 'Interação', color: 'primary', icon: 'i-lucide-mouse-pointer-click' },
  NOTE: { label: 'Nota interna', color: 'warning', icon: 'i-lucide-sticky-note' }
}

const successfulMessageRank: Partial<Record<CommunicationMessageStatus, number>> = {
  QUEUED: 10,
  ACCEPTED: 20,
  SENT: 30,
  DELIVERED: 40,
  READ: 50
}

/** Espelha a projeção monotônica do backend para merges locais/realtime. */
export function mergeCommunicationMessageStatus(
  current: CommunicationMessageStatus,
  incoming: CommunicationMessageStatus
): CommunicationMessageStatus {
  if (current === incoming || current === 'READ' || current === 'CANCELED') return current
  if (incoming === 'CANCELED') {
    return current === 'QUEUED' || current === 'ACCEPTED' ? incoming : current
  }
  if (incoming === 'FAILED' || incoming === 'UNKNOWN') {
    return (successfulMessageRank[current] ?? 0) <= 20 ? incoming : current
  }
  if (current === 'FAILED' || current === 'UNKNOWN') {
    return (successfulMessageRank[incoming] ?? 0) >= 30 ? incoming : current
  }
  return (successfulMessageRank[incoming] ?? 0) > (successfulMessageRank[current] ?? 0)
    ? incoming
    : current
}

export function mergeCommunicationMessages(
  current: CommunicationMessage[],
  incoming: CommunicationMessage[]
): CommunicationMessage[] {
  const byId = new Map<number, CommunicationMessage>()
  for (const message of current) byId.set(message.id, message)
  for (const message of incoming) {
    const previous = byId.get(message.id)
    byId.set(message.id, previous
      ? {
          ...previous,
          ...message,
          status: mergeCommunicationMessageStatus(previous.status, message.status),
          attachments: message.attachments ?? previous.attachments
        }
      : message)
  }
  return [...byId.values()].sort((a, b) => {
    const time = String(a.occurred_at || '').localeCompare(String(b.occurred_at || ''))
    return time || a.id - b.id
  })
}

/** Merge idempotente que não apaga a timeline já carregada ao atualizar a lista. */
export function mergeCommunicationConversations(
  current: CommunicationConversation[],
  incoming: CommunicationConversation[]
): CommunicationConversation[] {
  const byId = new Map<number, CommunicationConversation>()
  for (const conversation of current) byId.set(conversation.id, conversation)
  for (const conversation of incoming) {
    const previous = byId.get(conversation.id)
    byId.set(conversation.id, previous
      ? {
          ...previous,
          ...conversation,
          messages: conversation.messages
            ? mergeCommunicationMessages(previous.messages ?? [], conversation.messages)
            : previous.messages
        }
      : conversation)
  }
  return [...byId.values()].sort((a, b) => {
    if (a.priority !== b.priority) return b.priority - a.priority
    const time = String(b.last_message_at || '').localeCompare(String(a.last_message_at || ''))
    return time || b.id - a.id
  })
}

export function mergeCommunicationEvents(
  current: CommunicationEvent[],
  incoming: CommunicationEvent[]
): CommunicationEvent[] {
  const byCursor = new Map<number, CommunicationEvent>()
  for (const event of current) byCursor.set(event.cursor, event)
  for (const event of incoming) byCursor.set(event.cursor, event)
  return [...byCursor.values()].sort((a, b) => a.cursor - b.cursor)
}

export function latestCommunicationCursor(events: CommunicationEvent[], fallback = 0): number {
  return events.reduce((cursor, event) => Math.max(cursor, event.cursor), fallback)
}

/** Normaliza cursor WS/API (number ou string digitável) para comparação monotônica. */
export function normalizeCommunicationCursor(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value) && value >= 0) return value
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const parsed = Number(value)
    return Number.isInteger(parsed) && parsed >= 0 ? parsed : null
  }
  return null
}

export function isCommunicationEphemeralEvent(event: CommunicationEvent): boolean {
  return event.type === 'CHAT_PRESENCE_CHANGED' || event.type === 'CONTACT_PRESENCE_CHANGED'
}

function signalExpiry(payload: Record<string, unknown>, now: number, fallbackSeconds: number): number {
  const candidate = typeof payload.ttl_seconds === 'number'
    ? payload.ttl_seconds
    : Number(payload.ttl_seconds)
  const seconds = Number.isFinite(candidate)
    ? Math.max(1, Math.min(300, Math.trunc(candidate)))
    : fallbackSeconds
  return now + seconds * 1000
}

/** Normaliza apenas o payload efêmero allowlisted; PAUSED remove typing imediatamente. */
export function communicationSignalFromEvent(
  event: CommunicationEvent,
  now = Date.now()
): CommunicationChatPresenceSignal | CommunicationContactPresenceSignal | null {
  const conversationId = event.conversation_id
  if (!Number.isInteger(conversationId) || !conversationId || conversationId < 1) return null

  if (event.type === 'CHAT_PRESENCE_CHANGED') {
    const presence = event.payload.presence
    if (presence === 'PAUSED') return null
    if (presence !== 'COMPOSING' && presence !== 'RECORDING') return null
    const media = event.payload.media
    return {
      kind: 'chat',
      conversation_id: conversationId,
      presence,
      media: media === 'TEXT' || media === 'AUDIO' ? media : null,
      expires_at: signalExpiry(event.payload, now, 15)
    }
  }

  if (event.type !== 'CONTACT_PRESENCE_CHANGED' || typeof event.payload.available !== 'boolean') {
    return null
  }
  return {
    kind: 'contact',
    conversation_id: conversationId,
    available: event.payload.available,
    last_seen: typeof event.payload.last_seen === 'string' ? event.payload.last_seen : null,
    expires_at: signalExpiry(event.payload, now, 60)
  }
}

export function isCommunicationSignalActive(
  signal: CommunicationChatPresenceSignal | CommunicationContactPresenceSignal | null | undefined,
  now = Date.now()
): boolean {
  return Boolean(signal && signal.expires_at > now)
}

export function communicationMessageSummary(message?: CommunicationMessage | null): string {
  if (!message) return 'Mensagem indisponível'
  if (message.metadata?.revoked) return 'Mensagem apagada'
  const body = message.body?.trim()
  if (body) return body
  if (message.kind === 'LOCATION') return message.metadata?.location?.name || 'Localização compartilhada'
  if (message.kind === 'CONTACT') return message.metadata?.contact?.display_name || 'Contato compartilhado'
  if (message.kind === 'POLL') return message.metadata?.poll?.name || 'Enquete'
  if (message.kind === 'INTERACTIVE') return message.metadata?.interactive?.title || 'Mensagem interativa'
  return COMMUNICATION_MESSAGE_KIND[message.kind].label
}

export function communicationConversationImageEvidence(
  conversation: CommunicationConversation
): CommunicationConversationImageEvidence | null {
  const message = conversation.last_message ?? conversation.messages?.at(-1)
  if (!message
    || message.direction !== 'INBOUND'
    || message.kind !== 'IMAGE'
    || message.metadata?.revoked) {
    return null
  }

  const attachment = message.attachments?.find(item =>
    !item.purged_at && item.mime_type.startsWith('image/')
  )
  const previewUrl = attachment?.preview_url?.trim() || null

  return {
    messageId: message.id,
    previewUrl
  }
}

export function communicationPollVotes(message: CommunicationMessage): CommunicationMessagePollVote[] {
  const votes = message.metadata?.poll_votes
  if (!votes) return []
  return Array.isArray(votes) ? votes : Object.values(votes)
}

export function communicationPollVoteCount(message: CommunicationMessage, option: string): number {
  return communicationPollVotes(message).reduce((total, vote) =>
    total + (vote.option_names?.includes(option) ? 1 : 0), 0)
}

export function communicationDisplayName(conversation: CommunicationConversation | null): string {
  if (!conversation) return 'Conversa'
  const clientNames = [...new Set(
    (conversation.clients ?? []).map(client => client.name.trim()).filter(Boolean)
  )]
  if (clientNames.length === 1) return clientNames[0] || 'Cliente'
  if (clientNames.length > 1) return `${clientNames[0]} +${clientNames.length - 1}`

  return conversation.contact?.name?.trim()
    || conversation.contact?.address || conversation.contact?.address_masked
    || `Contato #${conversation.contact?.id ?? conversation.id}`
}

export function communicationContactLabel(conversation: CommunicationConversation | null): string | null {
  if (!conversation) return null
  const contact = conversation.contact?.name?.trim()
  const address = (conversation.contact?.address || conversation.contact?.address_masked)?.trim()
  const hasClient = Boolean(conversation.clients?.some(client => client.name.trim()))
  if (hasClient && contact && address) return `${contact} · ${address}`
  if (hasClient) return contact || address || null
  if (contact && address && contact !== address) return address
  return null
}

export function formatCommunicationDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
}

export function communicationAttachmentFilename(message: CommunicationMessage, attachmentId: number): string {
  const extensionByMime: Record<string, string> = {
    'application/pdf': 'pdf',
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp',
    'audio/ogg': 'ogg',
    'audio/mpeg': 'mp3',
    'video/mp4': 'mp4',
    'text/plain': 'txt',
    'application/zip': 'zip'
  }
  const attachment = message.attachments?.find(item => item.id === attachmentId)
  if (attachment?.filename?.trim()) return attachment.filename.trim()
  const extension = attachment ? extensionByMime[attachment.mime_type] : undefined
  return `anexo-${attachmentId}${extension ? `.${extension}` : ''}`
}

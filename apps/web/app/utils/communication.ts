import type { ChatPresenceSignal, ContactPresenceSignal, Event, RealtimeState } from '~/types/communication/realtime'
import type { Conversation, ConversationStatus } from '~/types/communication/conversations'
import type { InboxStatus } from '~/types/communication/inboxes'
import type { Message, MessageAvailability, MessagePollVote, MessageStatus } from '~/types/communication/messages'
import type { ProfilePictureState } from '~/types/communication/contacts'
import { resolveApiUrl } from '~/utils/api-url'

export function communicationProfilePictureUrl(
  subject?: {
    profile_picture_url?: string | null
    profile_picture_state?: ProfilePictureState
  } | null
): string | null {
  const url = subject?.profile_picture_url?.trim()
  return subject?.profile_picture_state === 'READY' && url ? url : null
}

export function communicationProfilePictureSrc(
  subject: Parameters<typeof communicationProfilePictureUrl>[0],
  apiBase: string
): string | undefined {
  const url = communicationProfilePictureUrl(subject)
  return url ? resolveApiUrl(url, apiBase) : undefined
}

export type BadgeColor
  = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

export interface StatusMeta {
  label: string
  color: BadgeColor
  icon: string
}

export interface ConversationImageEvidence {
  messageId: number
  previewUrl: string | null
}

export const COMMUNICATION_INBOX_STATUS: Record<InboxStatus, StatusMeta> = {
  DISCONNECTED: { label: 'Desconectado', color: 'neutral', icon: 'i-lucide-circle-off' },
  CONNECTING: { label: 'Conectando', color: 'warning', icon: 'i-lucide-loader-circle' },
  CONNECTED: { label: 'Conectado', color: 'success', icon: 'i-lucide-circle-check' }
}

export const COMMUNICATION_CONVERSATION_STATUS: Record<ConversationStatus, StatusMeta> = {
  OPEN: { label: 'Aberta', color: 'primary', icon: 'i-lucide-message-circle' },
  PENDING: { label: 'Pendente', color: 'warning', icon: 'i-lucide-clock-3' },
  RESOLVED: { label: 'Resolvida', color: 'success', icon: 'i-lucide-circle-check' },
  SNOOZED: { label: 'Adiada', color: 'info', icon: 'i-lucide-alarm-clock' }
}

export const COMMUNICATION_MESSAGE_STATUS: Record<MessageStatus, StatusMeta> = {
  QUEUED: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock' },
  ACCEPTED: { label: 'Aceita', color: 'info', icon: 'i-lucide-check' },
  SENT: { label: 'Enviada', color: 'info', icon: 'i-lucide-check-check' },
  DELIVERED: { label: 'Entregue', color: 'success', icon: 'i-lucide-check-check' },
  READ: { label: 'Lida', color: 'primary', icon: 'i-lucide-check-check' },
  PLAYED: { label: 'Reproduzida', color: 'primary', icon: 'i-lucide-check-check' },
  FAILED: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-x' },
  UNKNOWN: { label: 'Resultado incerto', color: 'warning', icon: 'i-lucide-circle-help' },
  CANCELED: { label: 'Cancelada', color: 'neutral', icon: 'i-lucide-ban' }
}

/** Resolve status de receipt com fallback seguro para valores desconhecidos da API. */
export function communicationMessageStatusMeta(
  status?: string | null
): StatusMeta {
  if (status && status in COMMUNICATION_MESSAGE_STATUS) {
    return COMMUNICATION_MESSAGE_STATUS[status as MessageStatus]
  }
  return COMMUNICATION_MESSAGE_STATUS.UNKNOWN
}

export const COMMUNICATION_REALTIME_META: Record<RealtimeState, StatusMeta> = {
  disabled: { label: 'Tempo real desativado', color: 'neutral', icon: 'i-lucide-wifi-off' },
  connecting: { label: 'Conectando', color: 'warning', icon: 'i-lucide-loader-circle' },
  connected: { label: 'Tempo real ativo', color: 'success', icon: 'i-lucide-radio' },
  unavailable: { label: 'Sincronizando por cursor', color: 'warning', icon: 'i-lucide-refresh-cw' }
}

export const COMMUNICATION_MESSAGE_KIND: Record<Message['kind'], StatusMeta> = {
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

const successfulMessageRank: Partial<Record<MessageStatus, number>> = {
  QUEUED: 10,
  ACCEPTED: 20,
  SENT: 30,
  DELIVERED: 40,
  READ: 50,
  PLAYED: 60
}

/** Espelha a projeção monotônica do backend para merges locais/realtime. */
export function mergeCommunicationMessageStatus(
  current: MessageStatus,
  incoming: MessageStatus
): MessageStatus {
  if (current === incoming || current === 'READ' || current === 'PLAYED' || current === 'CANCELED') {
    return current === 'READ' && incoming === 'PLAYED' ? incoming : current
  }
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
  current: Message[],
  incoming: Message[]
): Message[] {
  const byId = new Map<number, Message>()
  for (const message of current) byId.set(message.id, message)
  for (const message of incoming) {
    const previous = byId.get(message.id)
    byId.set(message.id, previous
      ? {
          ...previous,
          ...message,
          status: mergeCommunicationMessageStatus(previous.status, message.status),
          body: message.body?.trim() ? message.body : previous.body,
          content: mergeCommunicationMessageContent(previous.content, message.content),
          attachments: message.attachments !== undefined ? message.attachments : previous.attachments,
          availability: communicationMessageAvailability(message.availability) ?? previous.availability
        }
      : message)
  }
  return [...byId.values()].sort((a, b) => {
    const time = String(a.occurred_at || '').localeCompare(String(b.occurred_at || ''))
    return time || a.id - b.id
  })
}

function communicationMessageAvailability(
  availability?: MessageAvailability | null
): MessageAvailability | null {
  return availability?.state ? availability : null
}

function mergeCommunicationMessageContent(
  current: Message['content'],
  incoming: Message['content']
): Message['content'] {
  if (!incoming) return current
  const text = incoming.text?.trim() || current?.text?.trim() || null
  const caption = incoming.caption?.trim() || current?.caption?.trim() || null
  return text || caption ? { ...current, ...incoming, text, caption } : current
}

/** Texto compatível para recursos antigos e a projeção pública aditiva. */
export function communicationMessageBody(message: Message): string | null {
  return message.body?.trim()
    || message.content?.text?.trim()
    || message.content?.caption?.trim()
    || null
}

export function communicationAvailabilityPlaceholder(message: Message): string | null {
  switch (message.availability?.state) {
    case 'UNSUPPORTED': return 'Este tipo de mensagem ainda não é compatível.'
    case 'MEDIA_RETRY_AVAILABLE': return 'Esta mídia histórica pode ser recuperada.'
    case 'MEDIA_REQUESTED': return 'A recuperação desta mídia foi solicitada.'
    case 'MEDIA_FAILED': return 'Não foi possível recuperar esta mídia.'
    case 'UNAVAILABLE': return 'Conteúdo indisponível.'
    default: {
      const hasContent = Boolean(communicationMessageBody(message))
        || Boolean(message.attachments?.length)
      return hasContent ? null : 'Conteúdo indisponível.'
    }
  }
}

/** Merge idempotente que não apaga a timeline já carregada ao atualizar a lista. */
export function mergeCommunicationConversations(
  current: Conversation[],
  incoming: Conversation[]
): Conversation[] {
  const byId = new Map<number, Conversation>()
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
  current: Event[],
  incoming: Event[]
): Event[] {
  const byCursor = new Map<number, Event>()
  for (const event of current) byCursor.set(event.cursor, event)
  for (const event of incoming) byCursor.set(event.cursor, event)
  return [...byCursor.values()].sort((a, b) => a.cursor - b.cursor)
}

export function latestCommunicationCursor(events: Event[], fallback = 0): number {
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

export function isCommunicationEphemeralEvent(event: Event): boolean {
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
  event: Event,
  now = Date.now()
): ChatPresenceSignal | ContactPresenceSignal | null {
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
  signal: ChatPresenceSignal | ContactPresenceSignal | null | undefined,
  now = Date.now()
): boolean {
  return Boolean(signal && signal.expires_at > now)
}

export function communicationMessageSummary(message?: Message | null): string {
  if (!message) return 'Mensagem indisponível'
  if (message.metadata?.revoked) return 'Mensagem apagada'
  const body = communicationMessageBody(message)
  if (body) return body
  if (message.kind === 'LOCATION') return message.metadata?.location?.name || 'Localização compartilhada'
  if (message.kind === 'CONTACT') return message.metadata?.contact?.display_name || 'Contato compartilhado'
  if (message.kind === 'POLL') return message.metadata?.poll?.name || 'Enquete'
  if (message.kind === 'INTERACTIVE') return message.metadata?.interactive?.title || 'Mensagem interativa'
  return COMMUNICATION_MESSAGE_KIND[message.kind].label
}

export function communicationConversationImageEvidence(
  conversation: Conversation
): ConversationImageEvidence | null {
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

export function communicationPollVotes(message: Message): MessagePollVote[] {
  const votes = message.metadata?.poll_votes
  if (!votes) return []
  return Array.isArray(votes) ? votes : Object.values(votes)
}

export function communicationPollVoteCount(message: Message, option: string): number {
  return communicationPollVotes(message).reduce((total, vote) =>
    total + (vote.option_names?.includes(option) ? 1 : 0), 0)
}

/** Retorna somente o E.164 seguro apresentado pela API. */
export function communicationPeerAddress(conversation: Conversation | null): string | null {
  if (!conversation?.contact) return null
  return conversation.contact.phone?.trim() || null
}

function looksMaskedAddress(value: string, conversation: Conversation): boolean {
  const masked = conversation.contact?.address_masked?.trim()
  if (masked && value === masked) return true
  return /[*•●·]{2,}/u.test(value)
}

export function communicationDisplayName(conversation: Conversation | null): string {
  if (!conversation) return 'Conversa'
  const address = communicationPeerAddress(conversation)
  const resolved = conversation.display_title?.trim()
  if (resolved && !looksMaskedAddress(resolved, conversation)) return resolved
  if (resolved && address) return address

  const clientNames = [...new Set(
    (conversation.clients ?? []).map(client => client.name.trim()).filter(Boolean)
  )]
  if (clientNames.length === 1) return clientNames[0] || 'Cliente'
  if (clientNames.length > 1) return `${clientNames[0]} +${clientNames.length - 1}`

  return conversation.contact?.name?.trim()
    || address
    || `Contato #${conversation.contact?.id ?? conversation.id}`
}

export function communicationSecondaryTitle(conversation: Conversation | null): string | null {
  if (!conversation) return null
  const secondary = conversation.secondary_title?.trim()
  if (secondary) {
    const address = communicationPeerAddress(conversation)
    if (looksMaskedAddress(secondary, conversation)) return address
    return secondary
  }
  return communicationContactLabel(conversation)
}

/** Linha de telefone/endereço da lista: sempre preferir número completo. */
export function communicationListPhoneLine(conversation: Conversation | null): string {
  if (!conversation) return '—'
  const address = communicationPeerAddress(conversation)
  if (address) return address
  const secondary = conversation.secondary_title?.trim()
  if (secondary && !looksMaskedAddress(secondary, conversation)) return secondary
  return '—'
}

export function communicationPreviewText(conversation: Conversation | null): string | null {
  if (!conversation) return null
  const preview = conversation.preview
  if (preview?.text?.trim()) return preview.text.trim()
  const last = conversation.last_message
  if (!last) return null
  if (last.metadata?.revoked_at || last.metadata?.revoked) return 'Mensagem apagada'
  const body = communicationMessageBody(last)
  if (body) return body.slice(0, 160)
  return communicationAvailabilityPlaceholder(last)
}

/** ISO de adiamento relativo (horas a partir de agora). */
export function communicationSnoozeUntil(hours: number): string {
  return new Date(Date.now() + hours * 60 * 60 * 1000).toISOString()
}

/** ISO de adiamento até amanhã às 09:00 local. */
export function communicationSnoozeTomorrowMorning(): string {
  const date = new Date()
  date.setDate(date.getDate() + 1)
  date.setHours(9, 0, 0, 0)
  return date.toISOString()
}

export function communicationContactLabel(conversation: Conversation | null): string | null {
  if (!conversation) return null
  const address = communicationPeerAddress(conversation)
  if (conversation.secondary_title?.trim()) {
    const secondary = conversation.secondary_title.trim()
    if (looksMaskedAddress(secondary, conversation)) return address
    return secondary
  }
  const contact = conversation.contact?.name?.trim()
  const hasClient = Boolean(conversation.clients?.some(client => client.name.trim()))
  if (hasClient && contact && address) return `${contact} · ${address}`
  if (hasClient) return contact || address || null
  if (contact && address && contact !== address) return address
  return address
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

export function communicationAttachmentFilename(message: Message, attachmentId: number): string {
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

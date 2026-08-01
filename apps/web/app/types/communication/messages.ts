export type MessageDirection = 'INBOUND' | 'OUTBOUND' | 'INTERNAL'
export type MessageKind
  = | 'TEXT'
    | 'IMAGE'
    | 'AUDIO'
    | 'VIDEO'
    | 'DOCUMENT'
    | 'STICKER'
    | 'LOCATION'
    | 'CONTACT'
    | 'POLL'
    | 'INTERACTIVE'
    | 'NOTE'
export type SendKind = Extract<MessageKind, 'TEXT' | 'IMAGE' | 'AUDIO' | 'VIDEO' | 'DOCUMENT' | 'STICKER'>
export type MessageSource = 'HUMAN' | 'FISCAL_AUTOMATION' | 'GATEWAY'
export type MessageStatus = 'QUEUED' | 'ACCEPTED' | 'SENT' | 'DELIVERED' | 'READ' | 'PLAYED' | 'FAILED' | 'UNKNOWN' | 'CANCELED'
export type MessageAvailabilityState = 'AVAILABLE' | 'UNSUPPORTED' | 'MEDIA_RETRY_AVAILABLE' | 'MEDIA_REQUESTED' | 'MEDIA_FAILED' | 'UNAVAILABLE'

export interface Attachment {
  id: number
  filename: string
  mime_type: string
  size_bytes: number
  sha256: string
  download_url: string
  preview_url?: string | null
  purged_at?: string | null
}

export interface MessageLocation {
  latitude: number
  longitude: number
  name?: string | null
  address?: string | null
}

export interface MessageContact {
  display_name?: string | null
  vcard?: string | null
}

export interface MessagePoll {
  name?: string | null
  options?: string[]
  selectable_options?: number | null
}

export interface MessagePollVote {
  option_names?: string[]
  option_hashes?: string[]
}

export interface MessageInteractive {
  mode?: string | null
  title?: string | null
  description?: string | null
  options?: string[]
}

export interface MessageInteractiveResponse {
  text?: string | null
  selected_id?: string | null
}

/** Conteúdo textual aditivo da API; `body` segue como apresentação canônica. */
export interface MessageContent {
  text?: string | null
  caption?: string | null
}

/** Estado público allowlisted de conteúdo/mídia, sem detalhes do gateway. */
export interface MessageAvailability {
  state: MessageAvailabilityState
  recoverable: boolean
}

export interface MessageMetadata {
  edited_at?: string | null
  revoked?: boolean
  revoked_at?: string | null
  poll?: MessagePoll | null
  poll_votes?: Record<string, MessagePollVote> | MessagePollVote[]
  location?: MessageLocation | null
  contact?: MessageContact | null
  interactive?: MessageInteractive | null
  interactive_response?: MessageInteractiveResponse | null
  history?: boolean
  ephemeral?: boolean
  view_once?: boolean
  media_state?: string | null
  media_error_code?: string | null
  reactions?: string[]
}

export interface Message {
  id: number
  conversation_id: number
  direction: MessageDirection
  kind: MessageKind
  source: MessageSource
  status: MessageStatus
  body?: string | null
  content?: MessageContent | null
  availability?: MessageAvailability | null
  reply_to_message_id?: number | null
  author_membership_id?: number | null
  occurred_at?: string | null
  sent_at?: string | null
  delivered_at?: string | null
  read_at?: string | null
  metadata?: MessageMetadata
  attachments?: Attachment[]
}

export interface ComposerPayload {
  body: string
  internalNote: boolean
  replyToMessageId: number | null
  file: File | null
  kind: SendKind
  ptt: boolean
}

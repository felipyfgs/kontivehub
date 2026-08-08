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
    | 'UNSUPPORTED'
    | 'NOTE'
export type SendKind = Extract<MessageKind, 'TEXT' | 'IMAGE' | 'AUDIO' | 'VIDEO' | 'DOCUMENT' | 'STICKER'>
export type MessageSource = 'HUMAN' | 'FISCAL_AUTOMATION' | 'GATEWAY' | 'FLOW_AUTOMATION'
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
  caption?: string | null
  live?: boolean
  accuracy_meters?: number | null
  sequence?: number | null
}

export interface MessageContactPhone {
  label: string
  phone: string
}

export interface MessageContact {
  display_name?: string | null
  vcard?: string | null
  phones?: MessageContactPhone[]
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
  selected_id?: string | null
  display_text?: string | null
  name?: string | null
}

export interface MessageInteractiveResponse {
  text?: string | null
  selected_id?: string | null
}

/** Conteúdo textual aditivo da API; `body` segue como apresentação canônica. */
export interface MessageContent {
  text?: string | null
  caption?: string | null
  link_preview?: {
    url: string
    title?: string | null
    description?: string | null
  } | null
  location?: MessageLocation | null
  contacts?: MessageContact[]
  poll?: MessagePoll | null
  interactive?: MessageInteractive | null
  rich_card?: {
    category: 'PRODUCT' | 'ORDER' | 'PAYMENT' | 'EVENT' | 'CALL' | 'INVITE' | 'SYSTEM'
    title: string
    description?: string | null
    facts?: Array<{ label: string, value: string }>
  } | null
  ptt?: boolean
  gif?: boolean
  animated?: boolean
  duration_seconds?: number | null
  reactions?: string[]
  poll_votes?: MessagePollVote[]
  interactive_response?: MessageInteractiveResponse | null
  content_present?: boolean
  variants?: string[]
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
  history?: boolean
  ephemeral?: boolean
  view_once?: boolean
  media_state?: string | null
  media_error_code?: string | null
}

export interface Message {
  id: number
  conversation_id: number
  direction: MessageDirection
  kind: MessageKind
  provider_type?: string | null
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
  played_at?: string | null
  revoked_at?: string | null
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

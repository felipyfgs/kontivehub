export type ComposerDraftFamily
  = | 'TEXT'
    | 'MEDIA_BATCH'
    | 'AUDIO'
    | 'STICKER'
    | 'LOCATION'
    | 'CONTACTS'
    | 'POLL'
    | 'EVENT'
    | 'INTERACTIVE'

export interface ComposerDraftContext {
  tenantId: number
  inboxId: number
  conversationId: number
}

export interface ComposerDestinationContext {
  conversation: string
  client: string | null
  inbox: string
  destinationMasked: string | null
}

export interface ComposerCitation {
  replyToMessageId: number
}

export interface ComposerSubmissionKeys {
  idempotencyKey: string
}

export interface ComposerBatchSubmissionKeys extends ComposerSubmissionKeys {
  clientBatchId: string
}

interface ComposerWhatsappDraftBase {
  channel: 'WHATSAPP'
  citation: ComposerCitation | null
}

export interface ComposerTextDraft extends ComposerWhatsappDraftBase {
  family: 'TEXT'
  body: string
  submission: ComposerSubmissionKeys
}

export type ComposerMediaKind = 'IMAGE' | 'VIDEO' | 'DOCUMENT'

export interface ComposerMediaItem {
  clientItemId: string
  file: File
  kind: ComposerMediaKind
  caption: string
  gif: boolean
  ptv: boolean
  viewOnce: boolean
}

export interface ComposerMediaBatchDraft extends ComposerWhatsappDraftBase {
  family: 'MEDIA_BATCH'
  items: readonly ComposerMediaItem[]
  /** UI-only confirmation; it is deliberately not serialized to Laravel. */
  sensitiveConfirmed?: boolean
  submission: ComposerBatchSubmissionKeys
}

export interface ComposerAudioDraft extends ComposerWhatsappDraftBase {
  family: 'AUDIO'
  file: File
  ptt: boolean
  submission: ComposerSubmissionKeys
}

export interface ComposerStickerDraft extends ComposerWhatsappDraftBase {
  family: 'STICKER'
  file: File | null
  libraryStickerId: string | null
  submission: ComposerSubmissionKeys
}

export interface ComposerLocationDraft extends ComposerWhatsappDraftBase {
  family: 'LOCATION'
  location: { latitude: number, longitude: number, name?: string, address?: string }
  submission: ComposerSubmissionKeys
}

export interface ComposerContactsDraft extends ComposerWhatsappDraftBase {
  family: 'CONTACTS'
  contacts: readonly { displayName: string, vcard: string }[]
  submission: ComposerSubmissionKeys
}

export interface ComposerPollDraft extends ComposerWhatsappDraftBase {
  family: 'POLL'
  poll: { name: string, options: readonly string[], selectableOptions: number }
  submission: ComposerSubmissionKeys
}

export interface ComposerEventDraft extends ComposerWhatsappDraftBase {
  family: 'EVENT'
  event: {
    title: string
    description?: string
    startsAt: string
    endsAt: string
    timezone: string
    location?: string
  }
  submission: ComposerSubmissionKeys
}

export interface ComposerInteractiveDraft extends ComposerWhatsappDraftBase {
  family: 'INTERACTIVE'
  interactive: { type: 'BUTTONS' | 'LIST', title: string, body: string, actions: readonly { id: string, title: string }[] }
  submission: ComposerSubmissionKeys
}

export interface ComposerInternalNoteDraft {
  channel: 'INTERNAL_NOTE'
  family: 'TEXT'
  body: string
  citation: ComposerCitation | null
}

export type ComposerWhatsappDraft
  = | ComposerTextDraft
    | ComposerMediaBatchDraft
    | ComposerAudioDraft
    | ComposerStickerDraft
    | ComposerLocationDraft
    | ComposerContactsDraft
    | ComposerPollDraft
    | ComposerEventDraft
    | ComposerInteractiveDraft

export type ComposerDraft = ComposerWhatsappDraft | ComposerInternalNoteDraft

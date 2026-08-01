import type { ComputedRef, Ref } from 'vue'

export interface Event {
  cursor: number
  type: string
  inbox_id?: number | null
  conversation_id?: number | null
  message_id?: number | null
  payload: Record<string, unknown>
  occurred_at: string
}

export type ChatPresence = 'COMPOSING' | 'PAUSED' | 'RECORDING'
export type PresenceMedia = 'TEXT' | 'AUDIO'

export interface ChatPresenceSignal {
  kind: 'chat'
  conversation_id: number
  presence: Exclude<ChatPresence, 'PAUSED'>
  media?: PresenceMedia | null
  expires_at: number
}

export interface ContactPresenceSignal {
  kind: 'contact'
  conversation_id: number
  available: boolean
  last_seen?: string | null
  expires_at: number
}

export interface ConversationSignals {
  chat?: ChatPresenceSignal | null
  contact?: ContactPresenceSignal | null
}

export interface SyncMeta {
  next_cursor: number
  has_more: boolean
}

export interface RealtimeEvent extends Event {
  cursor: number
}

export type RealtimeState = 'disabled' | 'connecting' | 'connected' | 'unavailable'

export interface RealtimeService {
  enabled: boolean
  state: Readonly<Ref<RealtimeState>>
  /** True somente após ao menos um canal privado inscrito com sucesso. */
  channelsReady: ComputedRef<boolean>
  subscribeInbox: (inboxId: number, handler: (event: RealtimeEvent) => void) => () => void
  subscribeTenant: (tenantId: number, handler: (event: RealtimeEvent) => void) => () => void
  disconnect: () => void
}

import type { InboxStatus } from './inboxes'

export type RecipientMode = 'PRIMARY' | 'ALL_ELIGIBLE' | 'SELECTED'

export interface AutomationPolicy {
  id: number
  module_key: string
  submodule_key: string
  inbox_id?: number | null
  inbox_name?: string | null
  is_enabled: boolean
  send_day: number
  send_time: string
  timezone: string
  recipient_mode: RecipientMode
  template_key: string
  template_version: string
  lock_version: number
}

export interface AutomationMeta {
  supported_scopes: string[]
  inboxes: Array<{ id: number, name: string, status: InboxStatus, enabled: boolean }>
  tenant_enabled: boolean
  global_enabled: boolean
}

export interface RecipientIdentity {
  id: number
  masked: string
  is_primary: boolean
  receives_automatic: boolean
}

export interface RecipientConfiguration {
  client_id: number
  preference_id?: number | null
  recipient_mode: RecipientMode
  lock_version: number
  selected_identity_ids: number[]
  identities: RecipientIdentity[]
}

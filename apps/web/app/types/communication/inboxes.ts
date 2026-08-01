export type InboxStatus = 'DISCONNECTED' | 'CONNECTING' | 'CONNECTED'

export interface Inbox {
  id: number
  name: string
  status: InboxStatus
  address_masked?: string | null
  is_enabled: boolean
  is_default: boolean
  work_department_id?: number | null
  lock_version: number
  connected_at?: string | null
  last_seen_at?: string | null
  members_count?: number
  member_ids?: number[]
  members?: Array<{ id: number, name?: string | null }>
}

export interface FeatureMeta {
  global_enabled: boolean
  gateway_enabled: boolean
  tenant_enabled: boolean
  departments?: Array<{
    id: number
    name: string
    code: string
    color?: string | null
    is_active: boolean
  }>
}

export interface QueuedCommand {
  command_id: string | null
  type: string
  status: string
}

export interface PairingState {
  event?: string | null
  status?: string | null
  code?: string | null
  qr_code?: string | null
  pairing_code?: string | null
  expires_at?: string | null
  error_code?: string | null
  commands?: string[]
  [key: string]: unknown
}

export interface SessionStatus {
  session_id: string
  status: InboxStatus
  desired_connected: boolean
  reconnect_count: number
  connected: boolean
  logged_in: boolean
  ready: boolean
  has_credentials: boolean
  pairing?: PairingState | null
  pairing_expires_at?: string | null
  pairing_error_code?: string | null
}

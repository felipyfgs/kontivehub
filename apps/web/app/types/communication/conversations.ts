import type { ClientReference, ContactSummary, Label } from './contacts'
import type { Message } from './messages'

export type ConversationStatus = 'OPEN' | 'PENDING' | 'RESOLVED' | 'SNOOZED'

export interface ConversationPreview {
  kind: string
  text?: string | null
  attachment_kind?: string | null
  direction?: import('./messages').MessageDirection | null
}

export interface ConversationReadState {
  version: number
  last_read_through_message_id: number | null
}

export interface ConversationTimelineMeta {
  older_cursor: string | null
  newer_cursor: string | null
  first_unread_message_id: number | null
  snapshot_through_message_id: number | null
  read_state_version: number
  unread_count: number
  limit: number
}

export interface ConversationTimelineState {
  meta: ConversationTimelineMeta
  divider_message_id: number | null
  initialized: boolean
  initial_read_pending: boolean
  manual_unread: boolean
  loading: boolean
  loading_older: boolean
  loading_newer: boolean
  error: string | null
}

export interface Conversation {
  id: number
  inbox_id: number
  status: ConversationStatus
  work_department_id?: number | null
  assignee_membership_id?: number | null
  priority: number
  snoozed_until?: string | null
  last_message_at?: string | null
  lock_version: number
  messages_count?: number
  unread_count?: number
  first_unread_message_id?: number | null
  last_read_message_id?: number | null
  last_read_at?: string | null
  read_state?: ConversationReadState | null
  display_name?: string | null
  display_name_source?: string | null
  display_title?: string | null
  display_title_source?: string | null
  secondary_title?: string | null
  preview?: ConversationPreview | null
  contact?: ContactSummary | null
  clients?: ClientReference[]
  labels?: Label[]
  last_message?: Message | null
  messages?: Message[]
}

export interface ConversationListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type ConversationSortBy
  = | 'last_activity_desc'
    | 'last_activity_asc'
    | 'created_desc'
    | 'created_asc'
    | 'unread_desc'
    | 'priority_desc'
    | 'priority_asc'
export type ListPreferenceStatus = ConversationStatus | 'ALL'
export type ConversationQuickView = 'OPEN' | 'UNREAD' | 'UNASSIGNED' | 'PENDING' | 'SNOOZED' | 'RESOLVED' | 'ALL'
export type ConversationAction
  = | { type: 'MARK_READ' }
    | { type: 'MARK_UNREAD' }
    | { type: 'SET_STATUS', status: ConversationStatus, snoozed_until?: string | null }
    | { type: 'SET_ASSIGNEE', assignee_membership_id: number | null }
    | { type: 'SET_DEPARTMENT', work_department_id: number | null }
    | { type: 'SET_LABEL', label_id: number, assigned: boolean }

export interface ConversationActionPayload {
  conversation: Conversation
  action: ConversationAction
}

export interface ConversationListPreferences {
  status: ListPreferenceStatus
  sort_by: ConversationSortBy
  is_default?: boolean
}

export type BulkAction = 'SET_STATUS' | 'SET_ASSIGNEE' | 'SET_DEPARTMENT' | 'ADD_LABELS' | 'REMOVE_LABELS' | 'MARK_READ' | 'MARK_UNREAD'
export type BulkOperationStatus = 'QUEUED' | 'RUNNING' | 'COMPLETED' | 'COMPLETED_WITH_ERRORS' | 'FAILED'
export type BulkItemStatus = 'QUEUED' | 'PROCESSING' | 'SUCCEEDED' | 'SKIPPED' | 'FAILED'

export interface BulkOperationSubmitItem {
  conversation_id: number
  lock_version?: number
  through_message_id?: number
  read_state_version?: number
}

export interface BulkOperationParams {
  status?: ConversationStatus
  snoozed_until?: string | null
  assignee_membership_id?: number | null
  work_department_id?: number | null
  label_ids?: number[]
}

export interface BulkOperation {
  id: string
  public_id?: string
  action: BulkAction
  params?: BulkOperationParams & Record<string, unknown>
  status: BulkOperationStatus
  is_terminal: boolean
  item_count: number
  processed_count: number
  succeeded_count: number
  skipped_count: number
  failed_count: number
  error_code?: string | null
  error_message?: string | null
  queued_at?: string | null
  started_at?: string | null
  completed_at?: string | null
  created_at?: string | null
}

export interface BulkOperationResultItem {
  id: number
  item_index: number
  conversation_id: number
  resolved_conversation_id?: number | null
  inbox_id: number
  status: BulkItemStatus
  result_code?: string | null
  result_message?: string | null
  attempts: number
  processed_at?: string | null
}

export interface BulkOperationItemsMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ConversationInitiation {
  conversation: Conversation
  message: Message
  reused_conversation: boolean
}

export interface ConversationInitiationCapability {
  enabled: boolean
  reason: string | null
  requires_permission: string
}

export interface OutboundCapabilities {
  enabled: boolean
  requires_permission: string
  kinds: Record<string, Record<string, unknown>>
  max_media_bytes: number
  conversation_initiation: ConversationInitiationCapability
}

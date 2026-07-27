import type { ComputedRef, Ref } from 'vue'

export type CommunicationInboxStatus = 'DISCONNECTED' | 'CONNECTING' | 'CONNECTED'

export type CommunicationConversationStatus = 'OPEN' | 'PENDING' | 'RESOLVED' | 'SNOOZED'
export type CommunicationMessageDirection = 'INBOUND' | 'OUTBOUND' | 'INTERNAL'
export type CommunicationMessageKind
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
export type CommunicationSendKind = Extract<CommunicationMessageKind,
  'TEXT' | 'IMAGE' | 'AUDIO' | 'VIDEO' | 'DOCUMENT' | 'STICKER'>
export type CommunicationMessageSource = 'HUMAN' | 'FISCAL_AUTOMATION' | 'GATEWAY'
export type CommunicationMessageStatus
  = | 'QUEUED'
    | 'ACCEPTED'
    | 'SENT'
    | 'DELIVERED'
    | 'READ'
    | 'FAILED'
    | 'UNKNOWN'
    | 'CANCELED'
export type CommunicationRecipientMode = 'PRIMARY' | 'ALL_ELIGIBLE' | 'SELECTED'

export interface CommunicationInbox {
  id: number
  name: string
  status: CommunicationInboxStatus
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

export interface CommunicationFeatureMeta {
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

export interface CommunicationLabel {
  id: number
  name: string
  color: string
}

export interface CommunicationClientReference {
  id: number
  name: string
}

export interface CommunicationContactSummary {
  id: number
  name?: string | null
  is_provisional?: boolean | null
  address_masked?: string | null
  address?: string | null
}

export interface CommunicationAttachment {
  id: number
  filename: string
  mime_type: string
  size_bytes: number
  sha256: string
  download_url: string
  preview_url?: string | null
  purged_at?: string | null
}

export interface CommunicationMessageLocation {
  latitude: number
  longitude: number
  name?: string | null
  address?: string | null
}

export interface CommunicationMessageContact {
  display_name?: string | null
  vcard?: string | null
}

export interface CommunicationMessagePoll {
  name?: string | null
  options?: string[]
  selectable_options?: number | null
}

export interface CommunicationMessagePollVote {
  option_names?: string[]
  option_hashes?: string[]
}

export interface CommunicationMessageInteractive {
  mode?: string | null
  title?: string | null
  description?: string | null
  options?: string[]
}

export interface CommunicationMessageInteractiveResponse {
  text?: string | null
  selected_id?: string | null
}

/**
 * Projeção allowlisted de CommunicationMessageResource. IDs remotos, chaves,
 * direct paths e protobufs deliberadamente não fazem parte deste contrato.
 */
export interface CommunicationMessageMetadata {
  edited_at?: string | null
  revoked?: boolean
  revoked_at?: string | null
  poll?: CommunicationMessagePoll | null
  poll_votes?: Record<string, CommunicationMessagePollVote> | CommunicationMessagePollVote[]
  location?: CommunicationMessageLocation | null
  contact?: CommunicationMessageContact | null
  interactive?: CommunicationMessageInteractive | null
  interactive_response?: CommunicationMessageInteractiveResponse | null
  history?: boolean
  ephemeral?: boolean
  view_once?: boolean
  media_state?: string | null
  media_error_code?: string | null
  reactions?: string[]
}

export interface CommunicationMessage {
  id: number
  conversation_id: number
  direction: CommunicationMessageDirection
  kind: CommunicationMessageKind
  source: CommunicationMessageSource
  status: CommunicationMessageStatus
  body?: string | null
  reply_to_message_id?: number | null
  author_membership_id?: number | null
  occurred_at?: string | null
  sent_at?: string | null
  delivered_at?: string | null
  read_at?: string | null
  metadata?: CommunicationMessageMetadata
  attachments?: CommunicationAttachment[]
}

export interface CommunicationComposerPayload {
  body: string
  internalNote: boolean
  replyToMessageId: number | null
  file: File | null
  kind: CommunicationSendKind
  ptt: boolean
}

export interface CommunicationConversation {
  id: number
  inbox_id: number
  status: CommunicationConversationStatus
  work_department_id?: number | null
  assignee_membership_id?: number | null
  priority: number
  snoozed_until?: string | null
  last_message_at?: string | null
  lock_version: number
  messages_count?: number
  contact?: CommunicationContactSummary | null
  clients?: CommunicationClientReference[]
  labels?: CommunicationLabel[]
  last_message?: CommunicationMessage | null
  messages?: CommunicationMessage[]
}

export interface CommunicationConversationListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface CommunicationIdentityLink {
  id: number
  client_id: number
  client_name?: string | null
  client_contact_id?: number | null
  client_contact_name?: string | null
  is_primary: boolean
  receives_automatic: boolean
}

/** Params de listagem do catálogo (GET /communication/contacts). */
export interface CommunicationContactListParams {
  q?: string
  is_active?: boolean
  is_provisional?: boolean
  linked?: boolean
  include_inactive?: boolean
  sort?: 'name' | 'id' | 'created_at'
  sort_direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export type CommunicationContactSortField = NonNullable<CommunicationContactListParams['sort']>

export interface CommunicationIdentity {
  id: number
  channel: 'WHATSAPP' | string
  address_masked: string
  address?: string | null
  is_active: boolean
  links: CommunicationIdentityLink[]
}

export interface CommunicationContact {
  id: number
  name?: string | null
  is_provisional: boolean
  is_active: boolean
  identities?: CommunicationIdentity[]
  purged_at?: string | null
}

export interface CommunicationCannedResponse {
  id: number
  title: string
  shortcut: string
  body: string
  is_active: boolean
  lock_version: number
}

/** Params de gestão (GET /communication/canned-responses?manage=1). */
export interface CommunicationCannedResponseListParams {
  q?: string
  is_active?: boolean
  manage?: boolean | 1 | 0
  page?: number
  per_page?: number
}

export interface CommunicationCannedResponseWriteBody {
  title: string
  shortcut: string
  body: string
  is_active?: boolean
  lock_version?: number
}

export interface CommunicationCannedRenderResult {
  body: string
}

/** Status de metadado do fluxo (paused ≠ binding disabled). */
export type CommunicationFlowStatus = 'paused' | 'active'

/** Tipos allowlisted do engine de fluxos (espelho PHP). */
export type CommunicationFlowNodeType
  = | 'start'
    | 'message'
    | 'quick_reply'
    | 'question'
    | 'condition'
    | 'delay'
    | 'action'
    | 'handoff'
    | 'end'

export type CommunicationFlowActionKind = 'label' | 'assignee' | 'status'
export type CommunicationFlowConditionOperator = 'eq' | 'contains'
export type CommunicationFlowConditionField
  = | 'contact.name'
    | 'conversation.status'
    | 'last_inbound_text'

export interface CommunicationFlowNodePosition {
  x: number
  y: number
}

export interface CommunicationFlowNode {
  id: string
  type: CommunicationFlowNodeType
  data?: Record<string, unknown>
  position?: CommunicationFlowNodePosition
  label?: string
}

export interface CommunicationFlowEdge {
  id?: string
  source: string
  target: string
  label?: string
  branch?: string
  data?: Record<string, unknown>
}

export interface CommunicationFlowGraph {
  nodes: CommunicationFlowNode[]
  edges: CommunicationFlowEdge[]
}

export interface CommunicationFlow {
  id: number
  name: string
  status: CommunicationFlowStatus
  lock_version: number
  created_at?: string | null
  updated_at?: string | null
  draft?: CommunicationFlowDraft | null
  versions?: CommunicationFlowVersion[]
  bindings?: CommunicationFlowBinding[]
}

export interface CommunicationFlowDraft {
  id: number
  flow_id: number
  graph: CommunicationFlowGraph
  graph_digest: string
  lock_version: number
  updated_at?: string | null
}

export interface CommunicationFlowVersion {
  id: number
  flow_id: number
  version: number
  graph_digest: string
  published_at?: string | null
  published_by_membership_id?: number | null
}

export type CommunicationFlowRunStatus
  = | 'pending'
    | 'running'
    | 'waiting_input'
    | 'waiting_delay'
    | 'waiting_outbox'
    | 'paused'
    | 'handed_off'
    | 'completed'
    | 'stopped'
    | 'failed'
    | 'purged'

export interface CommunicationFlowRun {
  id: number
  flow_id: number
  flow_version_id: number
  binding_id: number | null
  conversation_id: number | null
  status: CommunicationFlowRunStatus | string
  current_node_id?: string | null
  started_at?: string | null
  finished_at?: string | null
  waiting_until?: string | null
}

export interface CommunicationFlowDryRunStep {
  seq: number
  node_id: string
  node_type: string
  status: string
  detail: Record<string, unknown>
}

export interface CommunicationFlowDryRunResult {
  valid: boolean
  graph_digest: string
  outcome: string
  steps: CommunicationFlowDryRunStep[]
  errors?: CommunicationFlowGraphError[]
  side_effects?: {
    outbox_created: boolean
    flow_run_persisted: boolean
    correlation_jobs_dispatched: boolean
    gateway_called: boolean
  }
}

export interface CommunicationFlowDryRunContext {
  contact_name?: string | null
  conversation_status?: string | null
  last_inbound_text?: string | null
  question_answers?: Record<string, string>
}

export interface CommunicationFlowPreviewResult {
  graph: CommunicationFlowGraph
  graph_digest: string
  masked_paths: string[]
}

export interface CommunicationFlowBinding {
  id: number
  flow_id: number
  inbox_id: number
  published_version_id: number | null
  enabled: boolean
  lock_version: number
}

export interface CommunicationFlowListMeta {
  flows_enabled: boolean
}

export interface CommunicationFlowGraphError {
  path: string
  code: string
  message: string
}

export interface CommunicationFlowValidateResult {
  valid: boolean
  graph_digest: string
  errors?: CommunicationFlowGraphError[]
}

export interface CommunicationFlowPublishResult {
  version: CommunicationFlowVersion
  flow: CommunicationFlow
  bindings_enabled: number
}

export interface CommunicationEvent {
  cursor: number
  type: string
  inbox_id?: number | null
  conversation_id?: number | null
  message_id?: number | null
  payload: Record<string, unknown>
  occurred_at: string
}

export type CommunicationChatPresence = 'COMPOSING' | 'PAUSED' | 'RECORDING'
export type CommunicationPresenceMedia = 'TEXT' | 'AUDIO'

export interface CommunicationChatPresenceSignal {
  kind: 'chat'
  conversation_id: number
  presence: Exclude<CommunicationChatPresence, 'PAUSED'>
  media?: CommunicationPresenceMedia | null
  expires_at: number
}

export interface CommunicationContactPresenceSignal {
  kind: 'contact'
  conversation_id: number
  available: boolean
  last_seen?: string | null
  expires_at: number
}

export interface CommunicationConversationSignals {
  chat?: CommunicationChatPresenceSignal | null
  contact?: CommunicationContactPresenceSignal | null
}

export interface CommunicationQueuedCommand {
  command_id: string | null
  type: string
  status: string
}

export interface CommunicationSyncMeta {
  next_cursor: number
  has_more: boolean
}

export interface CommunicationPairingState {
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

export interface CommunicationSessionStatus {
  session_id: string
  status: CommunicationInboxStatus
  desired_connected: boolean
  reconnect_count: number
  connected: boolean
  logged_in: boolean
  ready: boolean
  has_credentials: boolean
  pairing?: CommunicationPairingState | null
  pairing_expires_at?: string | null
  pairing_error_code?: string | null
}

export interface CommunicationAutomationPolicy {
  id: number
  module_key: string
  submodule_key: string
  inbox_id?: number | null
  inbox_name?: string | null
  is_enabled: boolean
  send_day: number
  send_time: string
  timezone: string
  recipient_mode: CommunicationRecipientMode
  template_key: string
  template_version: string
  lock_version: number
}

export interface CommunicationAutomationMeta {
  supported_scopes: string[]
  inboxes: Array<{
    id: number
    name: string
    status: CommunicationInboxStatus
    enabled: boolean
  }>
  tenant_enabled: boolean
  global_enabled: boolean
}

export interface CommunicationRecipientIdentity {
  id: number
  masked: string
  is_primary: boolean
  receives_automatic: boolean
}

export interface CommunicationRecipientConfiguration {
  client_id: number
  preference_id?: number | null
  recipient_mode: CommunicationRecipientMode
  lock_version: number
  selected_identity_ids: number[]
  identities: CommunicationRecipientIdentity[]
}

export interface CommunicationRealtimeEvent extends CommunicationEvent {
  cursor: number
}

export type CommunicationRealtimeState
  = | 'disabled'
    | 'connecting'
    | 'connected'
    | 'unavailable'

export interface CommunicationRealtimeService {
  enabled: boolean
  state: Readonly<Ref<CommunicationRealtimeState>>
  /** True somente após ao menos um canal privado inscrito com sucesso. */
  channelsReady: ComputedRef<boolean>
  subscribeInbox: (
    inboxId: number,
    handler: (event: CommunicationRealtimeEvent) => void
  ) => () => void
  subscribeTenant: (
    tenantId: number,
    handler: (event: CommunicationRealtimeEvent) => void
  ) => () => void
  disconnect: () => void
}

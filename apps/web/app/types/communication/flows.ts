/** Status de metadado do fluxo (paused ≠ binding disabled). */
export type FlowStatus = 'paused' | 'active'

/** Tipos allowlisted do engine de fluxos (espelho PHP). */
export type FlowNodeType
  = | 'start'
    | 'message'
    | 'quick_reply'
    | 'question'
    | 'condition'
    | 'delay'
    | 'action'
    | 'handoff'
    | 'end'

export type FlowActionKind = 'label' | 'assignee' | 'status'
export type FlowConditionOperator = 'eq' | 'contains'
export type FlowConditionField
  = | 'contact.name'
    | 'conversation.status'
    | 'last_inbound_text'

export interface FlowNodePosition {
  x: number
  y: number
}

export interface FlowNode {
  id: string
  type: FlowNodeType
  data?: Record<string, unknown>
  position?: FlowNodePosition
  label?: string
}

export interface FlowEdge {
  id?: string
  source: string
  target: string
  label?: string
  branch?: string
  data?: Record<string, unknown>
}

export interface FlowGraph {
  nodes: FlowNode[]
  edges: FlowEdge[]
}

export interface Flow {
  id: number
  name: string
  status: FlowStatus
  lock_version: number
  created_at?: string | null
  updated_at?: string | null
  draft?: FlowDraft | null
  versions?: FlowVersion[]
  bindings?: FlowBinding[]
}

export interface FlowDraft {
  id: number
  flow_id: number
  graph: FlowGraph
  graph_digest: string
  lock_version: number
  updated_at?: string | null
}

export interface FlowVersion {
  id: number
  flow_id: number
  version: number
  graph_digest: string
  published_at?: string | null
  published_by_membership_id?: number | null
}

export type FlowRunStatus
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

export interface FlowRun {
  id: number
  flow_id: number
  flow_version_id: number
  binding_id: number | null
  conversation_id: number | null
  status: FlowRunStatus | string
  current_node_id?: string | null
  started_at?: string | null
  finished_at?: string | null
  waiting_until?: string | null
}

export interface FlowDryRunStep {
  seq: number
  node_id: string
  node_type: string
  status: string
  detail: Record<string, unknown>
}

export interface FlowDryRunResult {
  valid: boolean
  graph_digest: string
  outcome: string
  steps: FlowDryRunStep[]
  errors?: FlowGraphError[]
  side_effects?: {
    outbox_created: boolean
    flow_run_persisted: boolean
    correlation_jobs_dispatched: boolean
    gateway_called: boolean
  }
}

export interface FlowDryRunContext {
  contact_name?: string | null
  conversation_status?: string | null
  last_inbound_text?: string | null
  question_answers?: Record<string, string>
}

export interface FlowPreviewResult {
  graph: FlowGraph
  graph_digest: string
  masked_paths: string[]
}

export interface FlowBinding {
  id: number
  flow_id: number
  inbox_id: number
  published_version_id: number | null
  enabled: boolean
  lock_version: number
}

export interface FlowListMeta {
  flows_enabled: boolean
}

export interface FlowGraphError {
  path: string
  code: string
  message: string
}

export interface FlowValidateResult {
  valid: boolean
  graph_digest: string
  errors?: FlowGraphError[]
}

export interface FlowPublishResult {
  version: FlowVersion
  flow: Flow
  bindings_enabled: number
}

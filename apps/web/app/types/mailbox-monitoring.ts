export type MailboxMonitoringMode = 'ECONOMICO' | 'DIARIO_COMPLETO'
export type MailboxPriceSource = 'OFFICIAL' | 'SHADOW' | 'UNKNOWN'

export interface MailboxCostPreview {
  operation: 'LISTAR' | 'DETALHE'
  quantity: number
  estimated_cost_micros: number | null
  unit_cost_micros: number | null
  currency: string | null
  price_source: MailboxPriceSource
  price_revision: string | null
  allowed: boolean
  block_reason: string | null
  budget_micros: number | null
  spent_micros: number
}

export interface MailboxMonitoringStatus {
  enabled: boolean
  runtime_enabled: boolean
  mode: MailboxMonitoringMode
  daily_time: string
  timezone: string
  reconciliation_days: number
  auto_detail_limit: number
  monthly_budget_micros: number | null
  coverage: {
    initialized_clients: number
    pending_clients: number
    blocked_clients: number
    failed_clients: number
  }
  last_free_check_at: string | null
  last_paid_check_at: string | null
  last_full_reconciliation_at: string | null
  last_dispatched_at: string | null
  next_due_at: string | null
  indicator_note: string
}

export interface MailboxSyncPreview {
  mode: MailboxMonitoringMode
  eligible_clients: number
  clients_to_list: number
  event_batches: number
  cost: MailboxCostPreview
  can_confirm: boolean
}

export interface MailboxSyncResponse {
  duplicate: boolean
  status: 'ACCEPTED'
  runs_enqueued?: number
  preview?: MailboxSyncPreview
}

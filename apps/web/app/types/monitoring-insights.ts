/**
 * DTO público de GET /api/v1/fiscal/monitoring/insights
 */

export interface MonitoringInsightsKpis {
  clients_total: number | null
  pending_open: number | null
  findings_active: number | null
  modules_with_error: number | null
}

export interface MonitoringInsightsPendingItem {
  id: number
  client_id: number
  code?: string | null
  title?: string | null
  detail?: string | null
  severity?: string | null
  status?: string | null
  situation?: string | null
  due_at?: string | null
  created_at?: string | null
}

export interface MonitoringInsightsPending {
  total: number
  by_severity: Record<string, number>
  items: MonitoringInsightsPendingItem[]
}

export interface MonitoringInsightsRbt12Client {
  client_id: number
  display_name: string
  total_cents: number
  rbt12_value?: string | number | null
  status: string
  period_key?: string | null
}

export interface MonitoringInsightsRbt12 {
  clients: MonitoringInsightsRbt12Client[]
}

export interface MonitoringInsightsMailbox {
  buckets: {
    important: number
    up_to_date: number
    other: number
  }
  scanned?: number
  total_messages?: number
  others_breakdown?: Array<{ label: string, count: number }>
  sample?: Array<{
    id: number
    client_id: number
    subject_preview?: string | null
    category_label?: string | null
    received_at_official?: string | null
    bucket: string
  }>
}

export interface MonitoringInsightsNotification {
  id: string
  type: 'pending' | 'finding' | 'alert' | string
  severity?: string | null
  title: string
  body?: string | null
  client_id?: number | null
  occurred_at?: string | null
  deep_link?: string | null
}

export interface MonitoringInsightsDeclarationsAbsence {
  up_to_date_count: number
  open_count: number
  by_obligation: Array<{
    obligation_code: string
    obligation_name?: string | null
    up_to_date: number
    open: number
  }>
}

export interface MonitoringInsightsSitfis {
  counters: {
    up_to_date: number
    pending: number
    attention: number
    error?: number
    processing?: number
    blocked?: number
    unknown?: number
    unsupported?: number
    not_applicable?: number
  }
  total_clients?: number
  coverage?: string | null
  data_origin?: string | null
  is_synthetic?: boolean
  as_of?: string | null
}

export interface MonitoringInsightsObligationProgress {
  code: string
  label: string
  completed: number | null
  total: number | null
  error?: number
  coverage?: string | null
  data_origin?: string | null
  is_synthetic?: boolean
}

export interface MonitoringInsightsPayload {
  as_of: string
  kpis: MonitoringInsightsKpis
  pending: MonitoringInsightsPending | null
  rbt12: MonitoringInsightsRbt12 | null
  mailbox: MonitoringInsightsMailbox | null
  notifications: { items: MonitoringInsightsNotification[] } | null
  declarations_absence: MonitoringInsightsDeclarationsAbsence | null
  sitfis: MonitoringInsightsSitfis | null
  obligations_progress: MonitoringInsightsObligationProgress[] | null
  partial_errors?: string[] | null
}

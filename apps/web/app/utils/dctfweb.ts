import type {
  DctfwebClientDetail,
  DctfwebClientRow,
  DctfwebClientSummary,
  DctfwebDeclarationState,
  DctfwebHistoryPayload,
  DctfwebHistoryPeriod,
  PgdasdCommunicationPreference,
  PgdasdTrackingStatus
} from '~/types/fiscal-modules'
import { formatDateTime } from '~/utils/format'
import { pgdasdTrackingMeta } from '~/utils/pgdasd'

export interface DctfwebStateMeta {
  label: string
  description: string
  color: 'success' | 'warning' | 'error' | 'neutral' | 'info'
  icon: string
}

const DECLARATION_META: Record<DctfwebDeclarationState, DctfwebStateMeta> = {
  CURRENT: {
    label: 'Em dia',
    description: 'Declaração do período esperado localizada com recibo válido.',
    color: 'success',
    icon: 'i-lucide-circle-check'
  },
  NO_MOVEMENT_VALID: {
    label: 'Sem movimento',
    description: 'Declaração sem movimento válida para o período.',
    color: 'success',
    icon: 'i-lucide-circle-minus'
  },
  DUE_WITHIN_DEADLINE: {
    label: 'No prazo',
    description: 'Ausência confirmada, mas o prazo mensal ainda não venceu.',
    color: 'warning',
    icon: 'i-lucide-clock-3'
  },
  OVERDUE_NOT_FOUND: {
    label: 'Sem declaração',
    description: 'Consulta produtiva após o prazo confirmou a ausência da declaração.',
    color: 'error',
    icon: 'i-lucide-circle-alert'
  },
  UNVERIFIED: {
    label: 'Não verificado',
    description: 'Evidência insuficiente para classificar o período (fail-closed).',
    color: 'neutral',
    icon: 'i-lucide-circle-help'
  }
}

export function dctfwebSummary(row?: DctfwebClientRow | null): DctfwebClientSummary | null {
  if (!row?.detail) return null
  const d = row.detail
  const nested = d.dctfweb
  if (nested) {
    return {
      expected_period_key: nested.expected_period_key || nested.period_key,
      period_key: nested.period_key,
      category: nested.category,
      declaration_state: nested.declaration_state || d.declaration_state,
      declaration_state_reason: nested.declaration_state_reason,
      last_declaration: nested.last_declaration || d.last_declaration,
      latest_declaration: nested.last_declaration || null,
      last_search_at: nested.last_search_at || nested.last_valid_query_at || d.last_search_at,
      last_valid_query_at: nested.last_valid_query_at || d.last_productive_consulted_at,
      last_productive_consulted_at: d.last_productive_consulted_at,
      calendar_verified: nested.calendar_verified,
      communication: nested.communication || d.communication,
      has_history: nested.has_history ?? d.has_history,
      has_tracking: nested.has_tracking ?? d.has_tracking,
      links: d.links
    }
  }

  if (d.declaration_state == null && d.last_declaration == null && d.communication == null) {
    return null
  }

  return {
    declaration_state: d.declaration_state,
    last_declaration: d.last_declaration,
    last_search_at: d.last_search_at,
    last_productive_consulted_at: d.last_productive_consulted_at,
    communication: d.communication,
    has_history: d.has_history,
    has_tracking: d.has_tracking,
    links: d.links
  }
}

export function dctfwebDeclarationState(value?: string | null): DctfwebDeclarationState {
  const state = String(value || '').toUpperCase() as DctfwebDeclarationState
  return state in DECLARATION_META ? state : 'UNVERIFIED'
}

export function dctfwebDeclarationMeta(value?: string | null): DctfwebStateMeta {
  return DECLARATION_META[dctfwebDeclarationState(value)]
}

/** Competência exibida como MM/AAAA. */
export function formatDctfwebPeriod(value?: string | null): string {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const technical = raw.match(/^(\d{4})-?(\d{2})$/)
  const displayed = raw.match(/^(\d{2})\/(\d{4})$/)
  const year = technical?.[1] || displayed?.[2]
  const month = technical?.[2] || displayed?.[1]
  if (!year || !month || Number(month) < 1 || Number(month) > 12) return '—'
  return `${month}/${year}`
}

export function formatDctfwebDate(value?: string | null): string {
  if (!value) return '—'
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return '—'
    const dd = String(d.getDate()).padStart(2, '0')
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const yyyy = d.getFullYear()
    return `${dd}/${mm}/${yyyy}`
  } catch {
    return '—'
  }
}

export function dctfwebLastDeclarationLabel(summary?: DctfwebClientSummary | null): string {
  const decl = summary?.latest_declaration || summary?.last_declaration
  if (!decl || typeof decl !== 'object') return '—'
  const period = formatDctfwebPeriod(
    (decl as { period_key?: string }).period_key || summary?.expected_period_key
  )
  return period === '—' ? '—' : period
}

export function dctfwebHistoryPeriods(
  history?: DctfwebHistoryPayload | DctfwebHistoryPeriod[] | null
): DctfwebHistoryPeriod[] {
  if (!history) return []
  if (Array.isArray(history)) return history
  return history.periods || history.history || []
}

export function dctfwebTrackingMeta(value?: string | null) {
  return pgdasdTrackingMeta(value as PgdasdTrackingStatus | null)
}

export function dctfwebCanRequestAutomatic(
  preference?: PgdasdCommunicationPreference | null
): boolean {
  if (!preference) return false
  const eligible = new Set(preference.eligible_channels || [])
  const emailOk = preference.email_enabled && eligible.has('EMAIL')
  const whatsOk = preference.whatsapp_enabled && eligible.has('WHATSAPP')
  return emailOk || whatsOk
}

export function dctfwebSituationTooltip(summary?: DctfwebClientSummary | null): string {
  const meta = dctfwebDeclarationMeta(summary?.declaration_state)
  const reason = summary?.declaration_state_reason?.trim() || meta.description
  return [
    `Situação: ${meta.label}.`,
    `Últ. declaração: ${dctfwebLastDeclarationLabel(summary)}.`,
    `Última busca: ${formatDateTime(summary?.last_search_at || summary?.last_valid_query_at)}.`,
    `Motivo: ${reason}`
  ].join(' ')
}

export function isDctfwebCapsule(submodule?: string | null): boolean {
  const s = String(submodule || '').toUpperCase()
  return s === 'DCTFWEB' || s === 'DCTF' || s === ''
}

export function isMitCapsule(submodule?: string | null): boolean {
  return String(submodule || '').toUpperCase() === 'MIT'
}

export type { DctfwebClientDetail, DctfwebClientSummary, DctfwebDeclarationState }

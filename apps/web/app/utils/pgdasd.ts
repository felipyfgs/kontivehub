import type {
  PgdasdClientSummary,
  PgdasdCommunicationPreference,
  PgdasdDasPaymentState,
  PgdasdDeclarationState,
  PgdasdHistoryPayload,
  PgdasdHistoryPeriod,
  PgdasdRbt12Summary,
  PgdasdTrackingStatus,
  SimplesMeiClientRow
} from '~/types/fiscal-modules'
import { formatAmountCents, formatCurrency } from '~/utils/format'

export interface PgdasdStateMeta {
  label: string
  description: string
  color: 'success' | 'warning' | 'error' | 'neutral' | 'info'
  icon: string
}

const DECLARATION_META: Record<PgdasdDeclarationState, PgdasdStateMeta> = {
  CURRENT: {
    label: 'Em dia',
    description: 'A declaração do período esperado foi localizada.',
    color: 'success',
    icon: 'i-lucide-circle-check'
  },
  DUE_WITHIN_DEADLINE: {
    label: 'No prazo',
    description: 'A declaração ainda não foi localizada, mas o prazo confiável não terminou.',
    color: 'warning',
    icon: 'i-lucide-clock-3'
  },
  OVERDUE_NOT_FOUND: {
    label: 'Atrasado',
    description: 'Uma consulta válida posterior ao prazo confirmou a ausência da declaração.',
    color: 'error',
    icon: 'i-lucide-circle-alert'
  },
  UNVERIFIED: {
    label: 'Não verificado',
    description: 'Ainda não existe evidência produtiva suficiente para classificar o período.',
    color: 'neutral',
    icon: 'i-lucide-circle-help'
  }
}

const PAYMENT_META: Record<PgdasdDasPaymentState, PgdasdStateMeta> = {
  PAID: {
    label: 'Em dia',
    description: 'Pagamento localizado.',
    color: 'success',
    icon: 'i-lucide-circle-check'
  },
  UNPAID: {
    label: 'Pendências',
    description: 'Há DAS do período esperado sem pagamento localizado na Receita.',
    color: 'warning',
    icon: 'i-lucide-circle-dollar-sign'
  },
  NO_DAS: {
    label: 'Sem movimento',
    description: 'Nenhum DAS gerado no período.',
    color: 'neutral',
    icon: 'i-lucide-file-x'
  },
  UNVERIFIED: {
    label: 'Não verificado',
    description: 'Ainda não há evidência suficiente para classificar o pagamento do DAS.',
    color: 'neutral',
    icon: 'i-lucide-circle-help'
  }
}

const TRACKING_META: Record<PgdasdTrackingStatus, PgdasdStateMeta> = {
  NOT_CONFIGURED: {
    label: 'Envio não configurado',
    description: 'Configure um canal e um contato elegível para preparar o envio automático.',
    color: 'warning',
    icon: 'i-lucide-message-square-warning'
  },
  NO_HISTORY: {
    label: 'Sem histórico de envio',
    description: 'Nenhum envio foi registrado para este cliente.',
    color: 'neutral',
    icon: 'i-lucide-message-square-dashed'
  },
  SCHEDULED: {
    label: 'Agendado para o cutoff',
    description: 'O envio aguarda o dia e horário definidos na política do escritório.',
    color: 'info',
    icon: 'i-lucide-calendar-clock'
  },
  QUEUED: {
    label: 'Na fila',
    description: 'O envio está aguardando processamento.',
    color: 'warning',
    icon: 'i-lucide-clock-3'
  },
  ACCEPTED: {
    label: 'Aceito pelo gateway',
    description: 'O comando foi persistido pelo gateway e aguarda confirmação remota.',
    color: 'info',
    icon: 'i-lucide-check'
  },
  SENT: {
    label: 'Enviado',
    description: 'O provedor aceitou o envio.',
    color: 'info',
    icon: 'i-lucide-send'
  },
  DELIVERED: {
    label: 'Entregue',
    description: 'A entrega foi confirmada.',
    color: 'success',
    icon: 'i-lucide-message-square-check'
  },
  READ: {
    label: 'Lido',
    description: 'A leitura foi confirmada pelo canal.',
    color: 'success',
    icon: 'i-lucide-message-circle-check'
  },
  PARTIAL: {
    label: 'Entrega parcial',
    description: 'Os canais ou destinatários possuem resultados diferentes.',
    color: 'warning',
    icon: 'i-lucide-message-square-more'
  },
  FAILED: {
    label: 'Falha no envio',
    description: 'O envio não foi concluído.',
    color: 'error',
    icon: 'i-lucide-message-square-x'
  },
  UNKNOWN: {
    label: 'Resultado incerto',
    description: 'A entrega não pôde ser confirmada sem risco de duplicação.',
    color: 'warning',
    icon: 'i-lucide-circle-help'
  },
  SKIPPED_NO_DOCUMENT: {
    label: 'Sem documento da competência',
    description: 'O cutoff venceu sem o documento canônico exato; o envio foi encerrado e não reabrirá automaticamente.',
    color: 'warning',
    icon: 'i-lucide-file-warning'
  },
  CANCELED: {
    label: 'Envio cancelado',
    description: 'O envio foi cancelado.',
    color: 'neutral',
    icon: 'i-lucide-ban'
  }
}

export function pgdasdSummary(row?: SimplesMeiClientRow | null): PgdasdClientSummary | null {
  if (!row?.detail) return null
  if (row.detail.pgdasd) return row.detail.pgdasd

  const hasLegacySummary = row.detail.declaration_state != null
    || row.detail.last_declaration != null
    || row.detail.rbt12 != null
    || row.detail.last_productive_consulted_at != null
    || row.detail.communication != null
    || row.detail.payment_state != null
  if (!hasLegacySummary) return null

  return {
    expected_period_key: row.detail.period_key,
    latest_declaration: row.detail.last_declaration,
    declaration_state: row.detail.declaration_state,
    payment_state: row.detail.payment_state,
    payment_state_reason: row.detail.payment_state_reason,
    payment_das_count: row.detail.payment_das_count,
    payment_unpaid_count: row.detail.payment_unpaid_count,
    payment_paid_count: row.detail.payment_paid_count,
    payment_open_competencies: row.detail.payment_open_competencies,
    last_valid_query_at: row.detail.last_productive_consulted_at,
    rbt12: row.detail.rbt12,
    documents: row.detail.documents,
    communication: row.detail.communication
  }
}

export function pgdasdDeclarationState(value?: string | null): PgdasdDeclarationState {
  const state = String(value || '').toUpperCase() as PgdasdDeclarationState
  return state in DECLARATION_META ? state : 'UNVERIFIED'
}

export function pgdasdDeclarationMeta(value?: string | null): PgdasdStateMeta {
  return DECLARATION_META[pgdasdDeclarationState(value)]
}

export function pgdasdDasPaymentState(value?: string | null): PgdasdDasPaymentState {
  const state = String(value || '').toUpperCase() as PgdasdDasPaymentState
  return state in PAYMENT_META ? state : 'UNVERIFIED'
}

export function pgdasdDasPaymentMeta(value?: string | null): PgdasdStateMeta {
  return PAYMENT_META[pgdasdDasPaymentState(value)]
}
export interface PgdasdPaymentDetailItem {
  label: string
  value: string
  /** Valor monetário de PA unpaid — destaque de débito na UI. */
  isDebit?: boolean
}

/**
 * Itens do popover Pagamento no nível do cliente:
 * PAID/NO_DAS → cartão curto na UI; UNVERIFIED → traço;
 * UNPAID com competências → só linhas MM/YYYY · valor (ou "—");
 * UNPAID sem lista → situação + descrição (util).
 */
export function pgdasdPaymentDetailItems(summary?: PgdasdClientSummary | null): PgdasdPaymentDetailItem[] {
  const state = pgdasdDasPaymentState(summary?.payment_state)
  const meta = pgdasdDasPaymentMeta(state)

  if (state === 'UNPAID') {
    const competencies = summary?.payment_open_competencies || []
    if (competencies.length > 0) {
      return competencies.map(competency => ({
        label: formatPgdasdPeriod(competency.period_key),
        value: formatAmountCents(competency.amount_cents),
        isDebit: competency.amount_cents != null
      }))
    }
  }

  return [
    { label: 'Situação', value: meta.label },
    { label: 'Detalhe', value: meta.description }
  ]
}

export function pgdasdTrackingStatus(value?: string | null): PgdasdTrackingStatus {
  const status = String(value || '').toUpperCase() as PgdasdTrackingStatus
  return status in TRACKING_META ? status : 'NO_HISTORY'
}

export function pgdasdTrackingMeta(value?: string | null): PgdasdStateMeta {
  return TRACKING_META[pgdasdTrackingStatus(value)]
}

export function pgdasdDeclarationPeriod(summary?: PgdasdClientSummary | null): string {
  return formatPgdasdPeriod(
    summary?.latest_declaration?.period_key || summary?.expected_period_key
  )
}

/** Formata as chaves oficiais YYYY-MM/YYYYMM sem expor o formato técnico na UI. */
export function formatPgdasdPeriod(value?: string | null): string {
  const raw = String(value || '').trim()
  const technical = raw.match(/^(\d{4})-?(\d{2})$/)
  const displayed = raw.match(/^(\d{2})\/(\d{4})$/)
  const year = technical?.[1] || displayed?.[2]
  const month = technical?.[2] || displayed?.[1]
  if (!year || !month || Number(month) < 1 || Number(month) > 12) return '—'
  return `${month}/${year}`
}

export function pgdasdRbt12UnavailableLabel(reason?: string | null): string {
  const code = String(reason || '').trim().toUpperCase()
  const labels: Record<string, string> = {
    EMPTY_TEXT: 'Extrato sem texto legível para leitura do RBT12.',
    INVALID_EXPECTED_PERIOD: 'Período de apuração inválido para validar o extrato.',
    PERIOD_MISMATCH: 'O extrato não confere com o período de apuração esperado.',
    CONFLICTING_VALUES: 'Há valores de RBT12 conflitantes no extrato; o sistema não escolhe um deles.',
    EXACT_RBT12_VALUE_NOT_FOUND: 'Não foi possível localizar o RBT12 inequívoco no extrato do DAS.',
    COMPOSITION_MISMATCH: 'Mercado interno + externo não fecha com o total do RBT12 no extrato.',
    EXTRACT_QUERY_FAILED: 'A consulta do extrato do DAS falhou; o RBT12 não foi atualizado.',
    EXTRATO_ARTIFACT_MISSING: 'Extrato do DAS não ficou disponível para leitura do RBT12.',
    EXTRATO_EVIDENCE_MISSING: 'Evidência do extrato indisponível para leitura do RBT12.',
    PDF_TEXT_EXTRACTION_FAILED: 'Não foi possível ler o texto do PDF do extrato.',
    READ_OR_PARSE_FAILED: 'Falha ao ler ou interpretar o extrato do DAS.',
    NO_DAS: 'Não há DAS de origem para consultar o extrato e obter o RBT12.'
  }
  return labels[code]
    || reason?.trim()
    || 'RBT12 (receita bruta dos 12 meses anteriores ao PA) indisponível. O sistema não estima valores ausentes ou ambíguos.'
}

export interface PgdasdRbt12DetailItem {
  label: string
  value: string
}

/** Itens para o painel/popover de detalhe do RBT12 (lista enxuta). */
export function pgdasdRbt12DetailItems(rbt12?: PgdasdRbt12Summary | null): PgdasdRbt12DetailItem[] {
  const parsed = rbt12?.status === 'PARSED'
  const hasValue = rbt12?.total_cents != null || Boolean(rbt12?.rbt12_value)
  if (!rbt12 || !parsed || !hasValue) {
    return [{
      label: 'Situação',
      value: pgdasdRbt12UnavailableLabel(
        rbt12?.availability_reason || rbt12?.unavailable_reason
      )
    }]
  }

  const total = rbt12.total_cents != null
    ? formatAmountCents(rbt12.total_cents)
    : formatCurrency(rbt12.rbt12_value)
  const internal = rbt12.internal_market_cents ?? rbt12.composition?.internal_market_cents
  const external = rbt12.external_market_cents ?? rbt12.composition?.external_market_cents
  const items: PgdasdRbt12DetailItem[] = [
    { label: 'RBT12', value: total || '—' }
  ]
  if (internal != null) {
    items.push({ label: 'Mercado interno', value: formatAmountCents(internal) })
  }
  if (external != null) {
    items.push({ label: 'Mercado externo', value: formatAmountCents(external) })
  }
  if (rbt12.rpa_cents != null) {
    items.push({ label: 'RPA', value: formatAmountCents(rbt12.rpa_cents) })
  }
  if (rbt12.period_key) {
    items.push({ label: 'PA', value: formatPgdasdPeriod(rbt12.period_key) })
  }
  return items
}

/** @deprecated Preferir `pgdasdRbt12DetailItems` no popover; mantido para compat de testes. */
export function pgdasdRbt12Tooltip(rbt12?: PgdasdRbt12Summary | null): string {
  return pgdasdRbt12DetailItems(rbt12)
    .map(item => `${item.label}: ${item.value}`)
    .join(' · ')
}

export function pgdasdCanRequestAutomatic(
  preference?: PgdasdCommunicationPreference | null
): boolean {
  if (!preference) return false
  const eligible = new Set(preference.eligible_channels || [])
  return (preference.email_enabled && eligible.has('EMAIL'))
    || (preference.whatsapp_enabled && eligible.has('WHATSAPP'))
}

/** Aceita o envelope oficial e o array aditivo usado durante deploy escalonado. */
export function pgdasdHistoryPeriods(
  payload?: PgdasdHistoryPayload | PgdasdHistoryPeriod[] | null
): PgdasdHistoryPeriod[] {
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.periods)) return payload.periods
  if (Array.isArray(payload?.history)) return payload.history
  return []
}

/**
 * Anos-calendário disponíveis para o seletor do histórico PGDAS-D.
 * Sempre inclui o ano corrente e os anos presentes em `period_key` / seed.
 */
export function pgdasdHistoryCalendarYears(
  payload?: PgdasdHistoryPayload | PgdasdHistoryPeriod[] | null,
  seedYears: Iterable<number> = [],
  now: Date = new Date()
): number[] {
  const years = new Set<number>()
  years.add(now.getFullYear())
  for (const year of seedYears) {
    if (Number.isInteger(year) && year >= 2000 && year <= 2100) years.add(year)
  }
  for (const period of pgdasdHistoryPeriods(payload)) {
    const match = /^(\d{4})/.exec(String(period.period_key || ''))
    if (!match) continue
    const year = Number(match[1])
    if (year >= 2000 && year <= 2100) years.add(year)
  }
  return [...years].sort((a, b) => b - a)
}

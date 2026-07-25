/**
 * Triagem interna da Caixa Postal — somente NEW / IN_REVIEW / RESOLVED.
 * Nunca altera ciência/leitura oficial no canal da RFB.
 */

export type MailboxTriageStatus = 'NEW' | 'IN_REVIEW' | 'RESOLVED'

export const MAILBOX_TRIAGE_STATUSES: readonly MailboxTriageStatus[] = [
  'NEW',
  'IN_REVIEW',
  'RESOLVED'
] as const

export const MAILBOX_TRIAGE_LABELS: Record<MailboxTriageStatus, string> = {
  NEW: 'Nova',
  IN_REVIEW: 'Em análise',
  RESOLVED: 'Resolvida'
}

export const MAILBOX_TRIAGE_FILTER_ITEMS = [
  { label: 'Todas as triagens', value: 'all' },
  { label: 'Nova', value: 'NEW' },
  { label: 'Em análise', value: 'IN_REVIEW' },
  { label: 'Resolvida', value: 'RESOLVED' }
] as const

export const MAILBOX_TRIAGE_SELECT_ITEMS = [
  { label: 'Nova', value: 'NEW' },
  { label: 'Em análise', value: 'IN_REVIEW' },
  { label: 'Resolvida', value: 'RESOLVED' }
] as const

export function isMailboxTriageStatus(value: unknown): value is MailboxTriageStatus {
  return typeof value === 'string'
    && (MAILBOX_TRIAGE_STATUSES as readonly string[]).includes(value.toUpperCase())
}

/** Label pt-BR da triagem interna (nunca expor NEW/IN_REVIEW cru na UI). */
export function mailboxTriageLabel(value?: string | null): string {
  const parsed = parseMailboxTriageStatus(value)
  if (parsed) return MAILBOX_TRIAGE_LABELS[parsed]
  return value?.trim() || '—'
}

/** Normaliza e valida; retorna null se inválido (ex.: DISMISSED / UNTRIAGED). */
export function parseMailboxTriageStatus(value: unknown): MailboxTriageStatus | null {
  if (typeof value !== 'string') return null
  const upper = value.trim().toUpperCase()
  return isMailboxTriageStatus(upper) ? upper : null
}

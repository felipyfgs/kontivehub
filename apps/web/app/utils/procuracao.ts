/**
 * Labels e tons de UI para estado oficial de procuração (sync e-CAC).
 * Sem override manual — somente tradução de estados do backend.
 */
import type { ClientProcuracaoStatus } from '~/types/api'

export type ProcuracaoTone = 'success' | 'warning' | 'error' | 'neutral' | 'info'

export function normalizeProcuracaoStatus(
  status?: string | null
): ClientProcuracaoStatus | null {
  if (!status) return null
  const s = String(status).toLowerCase()
  if (s === 'authorized' || s === 'autorizada') return 'authorized'
  if (s === 'expiring' || s === 'a_vencer') return 'expiring'
  if (s === 'missing' || s === 'sem_procuracao' || s === 'absent') return 'missing'
  if (s === 'expired' || s === 'vencida') return 'expired'
  if (s === 'unverified' || s === 'nao_verificada' || s === 'não_verificada') return 'unverified'
  return null
}

export function procuracaoLabel(status?: string | null): string {
  switch (normalizeProcuracaoStatus(status)) {
    case 'authorized':
      return 'Ativa'
    case 'expiring':
      return 'A vencer'
    case 'missing':
      return 'Sem procuração'
    case 'expired':
      return 'Vencida'
    case 'unverified':
      return 'Não verificada'
    default:
      return status ? String(status) : '—'
  }
}

export function procuracaoTone(status?: string | null): ProcuracaoTone {
  switch (normalizeProcuracaoStatus(status)) {
    case 'authorized':
      return 'success'
    case 'expiring':
      return 'warning'
    case 'missing':
      return 'warning'
    case 'expired':
      return 'error'
    case 'unverified':
      return 'neutral'
    default:
      return 'neutral'
  }
}

/** Texto acionável para o escritório (regularizar no e-CAC). */
export function procuracaoActionHint(status?: string | null): string | null {
  switch (normalizeProcuracaoStatus(status)) {
    case 'missing':
      return 'Regularize a procuração no e-CAC para liberar operações que exigem poder.'
    case 'expiring':
      return 'Renove a procuração no e-CAC antes do vencimento para não interromper consultas.'
    case 'expired':
      return 'Renove a procuração no e-CAC; a vigência expirou.'
    case 'unverified':
      return 'Aguarde a sincronização oficial ou verifique a concessão no e-CAC.'
    default:
      return null
  }
}

/** Texto de validade da projeção oficial, sem consultar ou inferir dados externos. */
export function procuracaoValidityLabel(status?: string | null, validTo?: string | null): string | null {
  if (!validTo) return null
  const parsed = new Date(validTo)
  if (Number.isNaN(parsed.getTime())) return null
  const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(parsed)

  switch (normalizeProcuracaoStatus(status)) {
    case 'expired':
      return `Venceu ${date}`
    case 'authorized':
    case 'expiring':
      return `Vence ${date}`
    default:
      return null
  }
}

/** Rótulo único para tabelas compactas, equivalente ao resumo de certificado. */
export function procuracaoChipLabel(status?: string | null, validTo?: string | null): string {
  const validity = procuracaoValidityLabel(status, validTo)
  if (validity) {
    const normalized = normalizeProcuracaoStatus(status)
    if (normalized === 'authorized') return validity.replace('Vence ', 'Válida até ')
    if (normalized === 'expiring') return validity.replace('Vence ', 'A vencer ')
    if (normalized === 'expired') return validity.replace('Venceu ', 'Vencida ')
    return validity
  }

  return procuracaoLabel(status)
}

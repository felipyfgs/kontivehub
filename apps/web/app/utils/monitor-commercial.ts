/**
 * Franquia comercial e metadados de execução dos monitores SERPRO.
 */
import type { MonitorCommercialBalance } from '~/types/api'
import { formatDateTime } from '~/utils/format'

export function commercialBalanceLabel(
  quota?: MonitorCommercialBalance | {
    remaining?: number | null
    limit?: number | null
    used?: number | null
    block_reason?: string | null
  } | null
): string {
  if (!quota) return '—'
  if (quota.remaining != null && quota.limit != null) {
    return `${quota.remaining}/${quota.limit}`
  }
  if (quota.remaining != null) return String(quota.remaining)
  if (quota.used != null && quota.limit != null) {
    return `${Math.max(0, Number(quota.limit) - Number(quota.used))}/${quota.limit}`
  }
  return '—'
}

export function commercialBlockLabel(reason?: string | null): string | null {
  if (!reason) return null
  switch (String(reason).toUpperCase()) {
    case 'QUOTA_EXHAUSTED':
    case 'FRANCHISE_EXHAUSTED':
      return 'Franquia esgotada neste período'
    case 'PROCURACAO_MISSING':
    case 'PROXY_MISSING':
      return 'Procuração ausente'
    case 'PROCURACAO_EXPIRED':
      return 'Procuração vencida'
    case 'FEATURE_DISABLED':
    case 'FLAG_OFF':
      return 'Monitor indisponível (plataforma)'
    case 'INTERVAL_MIN':
    case 'WITHIN_TTL':
      return 'Intervalo mínimo ainda não passou'
    default:
      return reason
  }
}

export function lastSnapshotLabel(iso?: string | null): string {
  if (!iso) return '—'
  return formatDateTime(iso)
}

export function nextRunLabel(iso?: string | null): string {
  if (!iso) return '—'
  return formatDateTime(iso)
}

/**
 * Texto de confirmação quando o snapshot é recente e o refresh consumirá franquia.
 */
export function recentRefreshConfirmDescription(input: {
  lastAt?: string | null
  remaining?: number | null
}): string {
  const when = input.lastAt ? formatDateTime(input.lastAt) : 'há pouco'
  const saldo = input.remaining != null
    ? ` Restam ${input.remaining} consulta(s) no período.`
    : ''
  return `Já existe um snapshot recente (${when}). Confirmar uma nova consulta consumirá 1 unidade da franquia do monitor.${saldo}`
}

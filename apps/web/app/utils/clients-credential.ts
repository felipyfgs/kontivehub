/**
 * Chip de certificado digital da lista de clientes (puro — sem dependência de #components).
 * Fonte: `credential_summary` (tabela `client_credentials`).
 */
import type { Client } from '~/types/api'
import { statusLabel } from '~/utils/format'

type ChipTone = 'success' | 'warning' | 'error' | 'neutral' | 'info'

export function formatClientDateOnly(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(date)
}

/** Chip certificado: válido / ausente / a vencer / vencido */
export function clientCredentialInfo(client: Client): {
  chipLabel: string
  color: ChipTone
  hasCredential: boolean
} {
  const summary = client.credential_summary
  if (!summary) {
    return {
      chipLabel: 'Sem certificado',
      color: 'neutral',
      hasCredential: false
    }
  }

  const expired = summary.status === 'EXPIRED'
    || !!(summary.valid_to && new Date(summary.valid_to) < new Date())
  const validToLabel = formatClientDateOnly(summary.valid_to)

  if (expired) {
    return {
      chipLabel: validToLabel !== '—' ? `Vencido ${validToLabel}` : 'Vencido',
      color: 'error',
      hasCredential: true
    }
  }
  if (summary.expires_alert_1 || summary.expires_alert_7 || summary.expires_alert_30) {
    return {
      chipLabel: validToLabel !== '—' ? `A vencer ${validToLabel}` : 'A vencer',
      color: summary.expires_alert_1 ? 'error' : 'warning',
      hasCredential: true
    }
  }
  if (summary.status === 'ACTIVE' || summary.valid_to) {
    return {
      chipLabel: validToLabel !== '—' ? `Válido até ${validToLabel}` : 'Válido',
      color: 'success',
      hasCredential: true
    }
  }
  return {
    chipLabel: statusLabel(summary.status),
    color: 'neutral',
    hasCredential: true
  }
}

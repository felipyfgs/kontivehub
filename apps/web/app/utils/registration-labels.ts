import type { RegistrationSource, RegistrationStatus } from '~/types/api'

export function registrationStatusLabel(status?: string | null): string {
  switch (status) {
    case 'ACTIVE':
      return 'Ativa'
    case 'VOID':
      return 'Nula'
    case 'SUSPENDED':
      return 'Suspensa'
    case 'UNFIT':
      return 'Inapta'
    case 'CLOSED':
      return 'Baixada'
    case 'UNKNOWN':
    case null:
    case undefined:
    case '':
      return 'Não consultada'
    default:
      return String(status)
  }
}

export function registrationStatusColor(status?: string | null): 'success' | 'warning' | 'error' | 'neutral' | 'info' {
  switch (status as RegistrationStatus | undefined) {
    case 'ACTIVE':
      return 'success'
    case 'SUSPENDED':
    case 'UNFIT':
      return 'warning'
    case 'VOID':
    case 'CLOSED':
      return 'error'
    default:
      return 'neutral'
  }
}

export function registrationStatusIcon(status?: string | null): string {
  switch (status as RegistrationStatus | undefined) {
    case 'ACTIVE':
      return 'i-lucide-circle-check'
    case 'SUSPENDED':
      return 'i-lucide-pause-circle'
    case 'UNFIT':
      return 'i-lucide-triangle-alert'
    case 'VOID':
    case 'CLOSED':
      return 'i-lucide-circle-x'
    default:
      return 'i-lucide-circle-help'
  }
}

export function registrationSourceLabel(source?: string | null): string {
  switch (source as RegistrationSource | undefined) {
    case 'CNPJ_WS':
      return 'CNPJ.ws'
    case 'MANUAL':
      return 'Manual'
    case 'LEGACY':
      return 'Legado'
    default:
      return source ? String(source) : '—'
  }
}

export function formatSourceDate(iso?: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('pt-BR', {
      dateStyle: 'short',
      timeStyle: 'short'
    })
  } catch {
    return iso
  }
}

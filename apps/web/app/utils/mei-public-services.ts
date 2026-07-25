import type {
  MeiAutomationAttempt,
  MeiAutomationStatus,
  MeiCoverage,
  MeiProvider
} from '~/types/mei-public-services'

export interface MeiPresentationMeta {
  label: string
  color: 'error' | 'info' | 'neutral' | 'primary' | 'success' | 'warning'
  icon: string
}

const STATUS_META: Record<MeiAutomationStatus, MeiPresentationMeta> = {
  QUEUED: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock-3' },
  RUNNING: { label: 'Processando', color: 'info', icon: 'i-lucide-loader-circle' },
  WAITING_USER_ACTION: { label: 'Ação necessária', color: 'warning', icon: 'i-lucide-user-round-check' },
  SUCCEEDED: { label: 'Concluído', color: 'success', icon: 'i-lucide-circle-check' },
  FAILED: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-x' },
  CANCELLED: { label: 'Cancelado', color: 'neutral', icon: 'i-lucide-ban' },
  UNCERTAIN: { label: 'Resultado incerto', color: 'warning', icon: 'i-lucide-circle-help' },
  SYNC_LOST: { label: 'Sincronização perdida', color: 'error', icon: 'i-lucide-unplug' }
}

const COVERAGE_META: Record<MeiCoverage, MeiPresentationMeta> = {
  SUMMARY: { label: 'Resumo', color: 'info', icon: 'i-lucide-list' },
  FULL: { label: 'Integral', color: 'success', icon: 'i-lucide-file-check-2' },
  UNKNOWN: { label: 'Cobertura desconhecida', color: 'neutral', icon: 'i-lucide-circle-help' }
}

export function meiAttemptStatusMeta(status: MeiAutomationStatus): MeiPresentationMeta {
  return STATUS_META[status]
}

export function meiCoverageMeta(coverage?: string | null): MeiPresentationMeta {
  return COVERAGE_META[coverage === 'SUMMARY' || coverage === 'FULL' ? coverage : 'UNKNOWN']
}

export function meiProviderMeta(
  provider?: MeiProvider | string | null,
  fallbackReason?: string | null
): MeiPresentationMeta {
  if (fallbackReason) {
    return { label: 'Contingência', color: 'warning', icon: 'i-lucide-route' }
  }
  if (provider === 'RECEITA_PORTAL') {
    return { label: 'Portal Receita', color: 'primary', icon: 'i-lucide-landmark' }
  }

  if (provider === 'SERPRO') {
    return { label: 'SERPRO', color: 'info', icon: 'i-lucide-shield-check' }
  }

  return { label: 'Provider não verificado', color: 'neutral', icon: 'i-lucide-circle-help' }
}

export function shouldPollMeiAttempt(attempt: MeiAutomationAttempt): boolean {
  return attempt.status === 'QUEUED' || attempt.status === 'RUNNING'
}

export function hasIntegralDasnReceipt(
  coverage?: string | null,
  artifactAvailable = false
): boolean {
  return coverage === 'FULL' && artifactAvailable
}

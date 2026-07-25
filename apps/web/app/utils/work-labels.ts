/**
 * Labels, cores e ícones semânticos do módulo Work.
 * Nunca depender somente de cor — ícone + texto sempre presentes.
 */
import type { ProcessStatus, QueueBucket, TaskStatus, WorkRisk } from '~/types/work'

export type SemanticColor = 'error' | 'warning' | 'success' | 'info' | 'neutral' | 'primary'

export function taskStatusLabel(status: TaskStatus | string): string {
  const map: Record<string, string> = {
    A_FAZER: 'A fazer',
    EM_PROGRESSO: 'Em progresso',
    IMPEDIDA: 'Impedida',
    CONCLUIDA: 'Concluída',
    DISPENSADA: 'Dispensada'
  }
  return map[status] || status
}

export function taskStatusColor(status: TaskStatus | string): SemanticColor {
  switch (status) {
    case 'CONCLUIDA': return 'success'
    case 'IMPEDIDA': return 'warning'
    case 'DISPENSADA': return 'neutral'
    case 'EM_PROGRESSO': return 'info'
    default: return 'primary'
  }
}

export function taskStatusIcon(status: TaskStatus | string): string {
  switch (status) {
    case 'CONCLUIDA': return 'i-lucide-check-circle'
    case 'IMPEDIDA': return 'i-lucide-octagon-pause'
    case 'DISPENSADA': return 'i-lucide-circle-minus'
    case 'EM_PROGRESSO': return 'i-lucide-loader'
    default: return 'i-lucide-circle'
  }
}

export function processStatusLabel(status: ProcessStatus | string): string {
  const map: Record<string, string> = {
    A_FAZER: 'A fazer',
    EM_PROGRESSO: 'Em progresso',
    IMPEDIDO: 'Impedido',
    CONCLUIDO: 'Concluído',
    ARQUIVADO: 'Arquivado'
  }
  return map[status] || status
}

export function processStatusColor(status: ProcessStatus | string): SemanticColor {
  switch (status) {
    case 'CONCLUIDO': return 'success'
    case 'IMPEDIDO': return 'warning'
    case 'ARQUIVADO': return 'neutral'
    case 'EM_PROGRESSO': return 'info'
    default: return 'primary'
  }
}

export function workRiskLabel(risk: WorkRisk | string): string {
  const map: Record<string, string> = {
    ATRASADA: 'Atrasada',
    EM_MULTA: 'Em multa',
    SEM_PRAZO: 'Sem prazo',
    SEM_RESPONSAVEL: 'Sem responsável'
  }
  return map[risk] || risk
}

export function workRiskColor(risk: WorkRisk | string): SemanticColor {
  switch (risk) {
    case 'EM_MULTA': return 'error'
    case 'ATRASADA': return 'warning'
    case 'SEM_RESPONSAVEL': return 'info'
    default: return 'neutral'
  }
}

export function workRiskIcon(risk: WorkRisk | string): string {
  switch (risk) {
    case 'EM_MULTA': return 'i-lucide-siren'
    case 'ATRASADA': return 'i-lucide-clock-alert'
    case 'SEM_RESPONSAVEL': return 'i-lucide-user-x'
    default: return 'i-lucide-calendar-off'
  }
}

export function highestRiskColor(risks?: string[] | null): SemanticColor {
  if (!risks?.length) return 'neutral'
  if (risks.includes('EM_MULTA')) return 'error'
  if (risks.includes('ATRASADA')) return 'warning'
  if (risks.includes('SEM_RESPONSAVEL')) return 'info'
  return 'neutral'
}

export function queueBucketLabel(bucket: QueueBucket | string): string {
  const map: Record<string, string> = {
    EM_MULTA: 'Em multa',
    ATRASADA: 'Atrasada',
    VENCE_HOJE: 'Vence hoje',
    VENCE_EM_TRES_DIAS: 'Vence em 3 dias',
    IMPEDIDA: 'Impedida',
    SEM_RESPONSAVEL: 'Sem responsável',
    DEMAIS_ABERTAS: 'Abertas',
    CONCLUIDAS: 'Concluídas'
  }
  return map[bucket] || bucket
}

export function formatCompetence(value?: string | null): string {
  if (!value) return '—'
  const [y, m] = value.split('-')
  if (!y || !m) return value
  return `${m}/${y}`
}

export function formatDueDate(value?: string | null): string {
  if (!value) return 'Sem prazo'
  const [y, m, d] = value.split('-')
  if (!y || !m || !d) return value
  return `${d}/${m}/${y}`
}

import type { TaskStatus } from '~/types/work'

/**
 * Ações rápidas de transição/claim no select inline.
 * Bloquear / evidência / comentário ficam no detalhe `/work/tasks/:id`.
 */
export type WorkTaskInlineAction = 'start' | 'complete' | 'resume' | 'claim'

export interface WorkTaskStatusOption {
  label: string
  value: WorkTaskInlineAction
}

export interface WorkTaskStatusOptionsInput {
  canClaim?: boolean
  /** Quando true, "Concluir" não aparece (exige evidência no detalhe). */
  requiresEvidence?: boolean
}

/**
 * Opções de status/transição a partir do estado atual da tarefa.
 * Terminal (CONCLUIDA/DISPENSADA) → sem opções; reabertura só no detalhe autorizado.
 * Ações rápidas: iniciar, retomar, assumir, concluir (se não exige evidência).
 */
export function workTaskStatusOptions(
  status: TaskStatus,
  opts?: WorkTaskStatusOptionsInput
): WorkTaskStatusOption[] {
  const options: WorkTaskStatusOption[] = []
  const allowComplete = opts?.requiresEvidence !== true

  if (status === 'A_FAZER') {
    options.push({ label: 'Iniciar', value: 'start' })
    if (allowComplete) {
      options.push({ label: 'Concluir', value: 'complete' })
    }
    if (opts?.canClaim) {
      options.push({ label: 'Assumir', value: 'claim' })
    }
    return options
  }

  if (status === 'EM_PROGRESSO') {
    if (allowComplete) {
      options.push({ label: 'Concluir', value: 'complete' })
    }
    return options
  }

  if (status === 'IMPEDIDA') {
    options.push({ label: 'Retomar', value: 'resume' })
    if (allowComplete) {
      options.push({ label: 'Concluir', value: 'complete' })
    }
    return options
  }

  return []
}

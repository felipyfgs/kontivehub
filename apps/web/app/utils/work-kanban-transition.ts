/**
 * Mapeamento puro coluna Kanban ↔ transição HTTP da fila de tarefas.
 * Sem persistência de reorder; DISPENSADA fora do board.
 */
import type { OperationalTaskSummary, TaskStatus } from '~/types/work'

export type WorkKanbanColumnStatus = 'A_FAZER' | 'EM_PROGRESSO' | 'IMPEDIDA' | 'CONCLUIDA'

export type WorkKanbanTransitionAction = 'start' | 'resume' | 'block' | 'complete' | 'reopen'

export const WORK_KANBAN_COLUMNS: readonly WorkKanbanColumnStatus[] = [
  'A_FAZER',
  'EM_PROGRESSO',
  'IMPEDIDA',
  'CONCLUIDA'
] as const

export type WorkKanbanDropResult
  = | { kind: 'noop' }
    | { kind: 'invalid', message: string }
    | {
      kind: 'action'
      action: WorkKanbanTransitionAction
      /** block e reopen exigem texto antes de persistir. */
      requiresReason: boolean
    }

const INVALID_DROP_MESSAGE = 'Essa mudança de status não é permitida no quadro.'

export function isWorkKanbanColumnStatus(status: string): status is WorkKanbanColumnStatus {
  return (WORK_KANBAN_COLUMNS as readonly string[]).includes(status)
}

/** Drop na mesma coluna não persiste (nem reorder). */
export function canDropOnKanbanColumn(
  from: TaskStatus | string,
  to: TaskStatus | string
): boolean {
  if (from === to) return false
  if (!isWorkKanbanColumnStatus(from) || !isWorkKanbanColumnStatus(to)) return false
  return actionForKanbanDrop(from, to).kind === 'action'
}

export function actionForKanbanDrop(
  from: TaskStatus | string,
  to: TaskStatus | string
): WorkKanbanDropResult {
  if (from === to) return { kind: 'noop' }

  if (!isWorkKanbanColumnStatus(from) || !isWorkKanbanColumnStatus(to)) {
    return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
  }

  if (to === 'EM_PROGRESSO') {
    if (from === 'A_FAZER') {
      return { kind: 'action', action: 'start', requiresReason: false }
    }
    if (from === 'IMPEDIDA') {
      return { kind: 'action', action: 'resume', requiresReason: false }
    }
    return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
  }

  if (to === 'IMPEDIDA') {
    if (from === 'A_FAZER' || from === 'EM_PROGRESSO') {
      return { kind: 'action', action: 'block', requiresReason: true }
    }
    return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
  }

  if (to === 'CONCLUIDA') {
    if (from === 'A_FAZER' || from === 'EM_PROGRESSO' || from === 'IMPEDIDA') {
      return { kind: 'action', action: 'complete', requiresReason: false }
    }
    return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
  }

  if (to === 'A_FAZER') {
    if (from === 'CONCLUIDA') {
      return { kind: 'action', action: 'reopen', requiresReason: true }
    }
    return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
  }

  return { kind: 'invalid', message: INVALID_DROP_MESSAGE }
}

export type WorkKanbanBoardColumns = Record<WorkKanbanColumnStatus, OperationalTaskSummary[]>

/** Agrupa elegíveis; omite DISPENSADA e status desconhecidos. */
export function groupTasksForKanbanBoard(
  items: OperationalTaskSummary[]
): WorkKanbanBoardColumns {
  const columns: WorkKanbanBoardColumns = {
    A_FAZER: [],
    EM_PROGRESSO: [],
    IMPEDIDA: [],
    CONCLUIDA: []
  }

  for (const item of items) {
    if (!isWorkKanbanColumnStatus(item.status)) continue
    columns[item.status].push(item)
  }

  return columns
}

export function isKanbanBoardTruncated(total: number, loadedCount: number): boolean {
  return total > loadedCount
}

export function kanbanTruncationMessage(total: number, loadedCount: number): string {
  return `O quadro mostra ${loadedCount} de ${total} tarefas filtradas. Ajuste os filtros para ver o restante.`
}

/**
 * Valor de retorno de `onDrop` no @vue-dnd-kit: `false` = "decline" e o drag permanece ativo.
 * Após toast/rollback/cancel local, sempre encerrar a sessão com `true`.
 */
export function completeKanbanDnDDrop(): true {
  return true
}

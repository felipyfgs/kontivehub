import type { WorkProcess, WorkProcessTask } from '~/types/work'

export interface WorkSelectionBulkItem {
  id: number
  lock_version: number
  label: string
}

export interface WorkProcessSelectionContext {
  group: string
  q: string
  competence: string
  status: string
  client_id: number | null
  department_id: number | null
}

export interface WorkProcessListRequestContext extends WorkProcessSelectionContext {
  page: number
  per_page: number
  sort: string | null
  direction: string | null
}

/**
 * Identifica o conjunto de recursos elegíveis à seleção.
 * Paginação, tamanho da página e ordenação não alteram o conjunto e ficam fora.
 */
export function workProcessSelectionContextKey(
  context: WorkProcessSelectionContext
): string {
  return JSON.stringify([
    context.group,
    context.q.trim(),
    context.competence.trim(),
    context.status || 'all',
    context.client_id,
    context.department_id
  ])
}

export function workProcessListRequestKey(
  context: WorkProcessListRequestContext
): string {
  return JSON.stringify([
    workProcessSelectionContextKey(context),
    context.page,
    context.per_page,
    context.sort,
    context.direction
  ])
}

/**
 * Se o reset de sessão não alterou a URL/filtros, o watcher da lista não roda;
 * nesse caso a página precisa disparar uma carga explícita.
 */
export function shouldReloadWorkProcessesAfterSessionReset(
  beforeRequestKey: string,
  afterRequestKey: string
): boolean {
  return beforeRequestKey === afterRequestKey
}

/** Tarefas ordenadas de um processo (mesma regra da lista). */
export function sortedProcessTasks(process: WorkProcess): WorkProcessTask[] {
  return [...(process.tasks || [])].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
}

/**
 * Aplica seleção em cascata: marcar/desmarcar processos também marca/desmarca
 * todas as tarefas embutidas desses processos.
 */
export function cascadeProcessTaskSelection(input: {
  processes: WorkProcess[]
  processSelection: Record<string, boolean>
  taskSelection: Record<string, boolean>
  /** IDs de processo cujo estado acabou de mudar (para sincronizar só o necessário). */
  changedProcessIds: number[]
  selected: boolean
}): { processSelection: Record<string, boolean>, taskSelection: Record<string, boolean> } {
  const processSelection = { ...input.processSelection }
  const taskSelection = { ...input.taskSelection }
  const changed = new Set(input.changedProcessIds.map(String))

  for (const process of input.processes) {
    const key = String(process.id)
    if (!changed.has(key)) continue

    if (input.selected) {
      processSelection[key] = true
      for (const task of sortedProcessTasks(process)) {
        taskSelection[String(task.id)] = true
      }
    } else {
      Reflect.deleteProperty(processSelection, key)
      for (const task of sortedProcessTasks(process)) {
        Reflect.deleteProperty(taskSelection, String(task.id))
      }
    }
  }

  return { processSelection, taskSelection }
}

/** Seleciona ou limpa todos os processos da página e todas as tarefas deles. */
export function cascadeSelectAllProcessesOnPage(input: {
  processes: WorkProcess[]
  selected: boolean
}): { processSelection: Record<string, boolean>, taskSelection: Record<string, boolean> } {
  if (!input.selected) {
    return { processSelection: {}, taskSelection: {} }
  }

  const processSelection: Record<string, boolean> = {}
  const taskSelection: Record<string, boolean> = {}
  for (const process of input.processes) {
    processSelection[String(process.id)] = true
    for (const task of sortedProcessTasks(process)) {
      taskSelection[String(task.id)] = true
    }
  }
  return { processSelection, taskSelection }
}

export function selectedMaterialisedBulkItems(input: {
  processes: readonly WorkProcess[]
  processSelection: Record<string, boolean>
  taskSelection: Record<string, boolean>
}): { processes: WorkSelectionBulkItem[], tasks: WorkSelectionBulkItem[] } {
  const processes: WorkSelectionBulkItem[] = []
  const tasks: WorkSelectionBulkItem[] = []

  for (const process of input.processes) {
    if (input.processSelection[String(process.id)] === true) {
      processes.push({
        id: process.id,
        lock_version: process.lock_version,
        label: process.title
      })
    }
    for (const task of sortedProcessTasks(process)) {
      if (input.taskSelection[String(task.id)] !== true) continue
      tasks.push({
        id: task.id,
        lock_version: task.lock_version,
        label: `${task.title} · ${process.title}`
      })
    }
  }

  return { processes, tasks }
}

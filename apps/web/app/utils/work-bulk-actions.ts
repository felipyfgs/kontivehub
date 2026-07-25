export type WorkBulkInitialScope = 'tasks' | 'processes'

export function workProcessBulkCapabilities(input: {
  canExecuteTasks: boolean
  canAdminister: boolean
  canUpdateProcesses: boolean
}): { tasks: boolean, processes: boolean, any: boolean } {
  const tasks = input.canExecuteTasks || input.canAdminister
  const processes = input.canAdminister || input.canUpdateProcesses
  return {
    tasks,
    processes,
    any: tasks || processes
  }
}

export function workProcessSelectionAriaLabel(
  processLabel: string,
  includesTasks: boolean
): string {
  return includesTasks
    ? `Selecionar ${processLabel} e suas tarefas`
    : `Selecionar ${processLabel}`
}

export function initialWorkBulkScope(input: {
  taskCount: number
  processCount: number
  canExecuteTasks: boolean
  taskActionCount: number
  processActionCount: number
}): WorkBulkInitialScope {
  if (input.taskCount > 0 && input.canExecuteTasks && input.taskActionCount > 0) {
    return 'tasks'
  }
  if (input.processCount > 0 && input.processActionCount > 0) {
    return 'processes'
  }
  if (input.taskCount > 0 && input.taskActionCount > 0) {
    return 'tasks'
  }
  return 'processes'
}

import { describe, expect, it } from 'vitest'
import {
  initialWorkBulkScope,
  workProcessBulkCapabilities,
  workProcessSelectionAriaLabel
} from '../../app/utils/work-bulk-actions'

describe('work process bulk capabilities', () => {
  it('permite bulk de processos ao usuário create-only sem liberar tarefas', () => {
    expect(workProcessBulkCapabilities({
      canExecuteTasks: false,
      canAdminister: false,
      canUpdateProcesses: true
    })).toEqual({
      tasks: false,
      processes: true,
      any: true
    })
  })

  it('descreve no checkbox somente os recursos realmente selecionados', () => {
    expect(workProcessSelectionAriaLabel('Empresa Exemplo', true))
      .toBe('Selecionar Empresa Exemplo e suas tarefas')
    expect(workProcessSelectionAriaLabel('Empresa Exemplo', false))
      .toBe('Selecionar Empresa Exemplo')
  })
})

describe('work bulk actions — escopo inicial', () => {
  it('abre em tarefas somente quando há tarefas executáveis', () => {
    expect(initialWorkBulkScope({
      taskCount: 2,
      processCount: 1,
      canExecuteTasks: true,
      taskActionCount: 5,
      processActionCount: 2
    })).toBe('tasks')
  })

  it('prefere processos permitidos quando tarefas não são executáveis', () => {
    expect(initialWorkBulkScope({
      taskCount: 2,
      processCount: 1,
      canExecuteTasks: false,
      taskActionCount: 3,
      processActionCount: 2
    })).toBe('processes')
  })
})

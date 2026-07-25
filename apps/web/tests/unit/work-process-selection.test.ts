import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { OperationalProcess } from '../../app/types/work'
import {
  cascadeProcessTaskSelection,
  cascadeSelectAllProcessesOnPage,
  selectedMaterialisedBulkItems,
  shouldReloadWorkProcessesAfterSessionReset,
  sortedProcessTasks,
  workProcessListRequestKey,
  workProcessSelectionContextKey
} from '../../app/utils/work-process-selection'
import { mergeMaterialisedProcesses } from '../../app/composables/useWorkProcessGroupTree'

function processFixture(id: number, taskIds: number[]): OperationalProcess {
  return {
    id,
    title: `Processo ${id}`,
    competence: '2026-07',
    origin: 'MANUAL',
    status: 'EM_PROGRESSO',
    subject_to_fine: false,
    client_id: 1,
    lock_version: 1,
    tasks: taskIds.map((taskId, index) => ({
      id: taskId,
      title: `Tarefa ${taskId}`,
      status: 'A_FAZER',
      is_critical: false,
      is_required: true,
      requires_evidence: false,
      lock_version: 1,
      sort_order: index + 1
    }))
  }
}

describe('work-process-selection cascade', () => {
  it('reseta somente quando muda o conjunto filtrado, não paginação ou ordenação', () => {
    const base = {
      group: 'process',
      q: ' apuração ',
      competence: '2026-07',
      status: 'all',
      client_id: 10,
      department_id: 20,
      page: 1,
      per_page: 20,
      sort: 'label',
      direction: 'asc'
    }
    const key = workProcessSelectionContextKey(base)

    expect(workProcessSelectionContextKey({
      ...base,
      page: 3,
      per_page: 50,
      sort: 'next_due_date',
      direction: 'desc'
    })).toBe(key)

    for (const change of [
      { q: 'outra' },
      { competence: '2026-08' },
      { status: 'EM_PROGRESSO' },
      { client_id: 11 },
      { department_id: 21 },
      { group: 'client' }
    ]) {
      expect(workProcessSelectionContextKey({ ...base, ...change })).not.toBe(key)
    }
  })

  it('recarrega explicitamente a sessão só quando replaceFilters não muda a requisição', () => {
    const defaults = {
      group: 'process',
      q: '',
      competence: '',
      status: 'all',
      client_id: null,
      department_id: null,
      page: 1,
      per_page: 20,
      sort: null,
      direction: null
    }
    const defaultKey = workProcessListRequestKey(defaults)
    const filteredKey = workProcessListRequestKey({ ...defaults, q: 'PGDAS' })

    expect(shouldReloadWorkProcessesAfterSessionReset(defaultKey, defaultKey)).toBe(true)
    expect(shouldReloadWorkProcessesAfterSessionReset(filteredKey, defaultKey)).toBe(false)
  })

  it('marca processo e todas as tarefas embutidas', () => {
    const processes = [processFixture(10, [1, 2]), processFixture(20, [3])]
    const next = cascadeProcessTaskSelection({
      processes,
      processSelection: {},
      taskSelection: {},
      changedProcessIds: [10],
      selected: true
    })
    expect(next.processSelection).toEqual({ 10: true })
    expect(next.taskSelection).toEqual({ 1: true, 2: true })
  })

  it('desmarca processo e limpa só as tarefas dele', () => {
    const processes = [processFixture(10, [1, 2]), processFixture(20, [3])]
    const next = cascadeProcessTaskSelection({
      processes,
      processSelection: { 10: true, 20: true },
      taskSelection: { 1: true, 2: true, 3: true },
      changedProcessIds: [10],
      selected: false
    })
    expect(next.processSelection).toEqual({ 20: true })
    expect(next.taskSelection).toEqual({ 3: true })
  })

  it('header seleciona todos os processos e tarefas da página', () => {
    const processes = [processFixture(10, [1, 2]), processFixture(20, [3])]
    expect(cascadeSelectAllProcessesOnPage({ processes, selected: true })).toEqual({
      processSelection: { 10: true, 20: true },
      taskSelection: { 1: true, 2: true, 3: true }
    })
    expect(cascadeSelectAllProcessesOnPage({ processes, selected: false })).toEqual({
      processSelection: {},
      taskSelection: {}
    })
  })

  it('ordena tarefas por sort_order', () => {
    const process = processFixture(1, [9, 8])
    process.tasks![0]!.sort_order = 2
    process.tasks![1]!.sort_order = 1
    expect(sortedProcessTasks(process).map(t => t.id)).toEqual([8, 9])
  })

  it('preserva seleção materializada entre páginas e remove somente a desmarcada', () => {
    const pageOne = processFixture(10, [1])
    const pageTwo = processFixture(20, [2])
    const cache = mergeMaterialisedProcesses(
      mergeMaterialisedProcesses({}, [pageOne]),
      [pageTwo]
    )
    const selected = selectedMaterialisedBulkItems({
      processes: Object.values(cache),
      processSelection: { 10: true, 20: true },
      taskSelection: { 1: true, 2: true, 999: true }
    })

    expect(selected.processes.map(item => item.id)).toEqual([10, 20])
    expect(selected.tasks.map(item => item.id)).toEqual([1, 2])

    const deselected = cascadeProcessTaskSelection({
      processes: Object.values(cache),
      processSelection: { 10: true, 20: true },
      taskSelection: { 1: true, 2: true },
      changedProcessIds: [10],
      selected: false
    })
    const afterDeselect = selectedMaterialisedBulkItems({
      processes: Object.values(cache),
      processSelection: deselected.processSelection,
      taskSelection: deselected.taskSelection
    })

    expect(afterDeselect.processes.map(item => item.id)).toEqual([20])
    expect(afterDeselect.tasks.map(item => item.id)).toEqual([2])
  })

  it('página de processos usa cascata processo→tarefa nos filhos do grupo', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/work/processes/index.vue'), 'utf8')
    expect(page).toContain('cascadeProcessTaskSelection')
    expect(page).toContain('cascadeSelectAllProcessesOnPage')
    expect(page).toContain('work-process-group-select')
    expect(page).toContain('work-process-task-select')
    expect(page).toContain('work-process-child-select')
    expect(page).toContain(':get-row-id="(row) => row.key"')
    expect(page).not.toContain(':get-row-id="(row) => String(row.id)"')
    expect(page).toContain('selectedTaskBulkItems')
    expect(page).toContain('materialisedProcessCache')
    expect(page).toContain('selectedMaterialisedBulkItems')
    expect(page).toContain('workProcessSelectionContextKey(filters.value)')
    expect(page).toContain('if (nextContext !== previousContext) {\n      resetClientExpansion()\n    }\n    void load()')
    expect(page).toContain('requestEpoch !== groupsLoadEpoch.value')
    expect(page).toContain('shouldReloadWorkProcessesAfterSessionReset')
    expect(page).toContain('const beforeRequestKey = workProcessListRequestKey(filters.value)')
    expect(page).toContain('const afterRequestKey = workProcessListRequestKey(filters.value)')
    expect(page).toContain('function onBulkDone() {\n  resetClientExpansion()\n  void load()\n}')
    const onBulkDone = page.split('function onBulkDone()')[1]?.split('\n}\n\n')[0] ?? ''
    expect(onBulkDone).not.toContain('loadGroupChildren')
  })
})

import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createWorkApi } from '../../app/composables/api/createWorkApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'
import {
  buildGroupChildrenListParams,
  buildProcessGroupsListParams,
  entityLevelForProcesses,
  entityLevelForTasksSurface,
  groupByForMode,
  hasActiveWorkProcessGroupingFilters,
  parseWorkProcessGroupMode,
  parseWorkProcessGroupingQuery,
  processesPathForEntityLevel,
  serializeWorkProcessGroupingQuery,
  tasksListaPathForEntityLevel,
  type WorkProcessGroupingFilters
} from '../../app/composables/useWorkProcessGrouping'
import {
  expandAllGroupKeys,
  toggleGroupKeyExpanded
} from '../../app/composables/useWorkProcessGroupTree'
import {
  workProcessesFiltersToPayload,
  workProcessesPayloadToFilters
} from '../../app/utils/saved-list-filters'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

const base: WorkProcessGroupingFilters = {
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

describe('work-process-grouping URL e modos', () => {
  it('parse: group=client → cliente; omitido/outro → processo', () => {
    expect(parseWorkProcessGroupMode('client')).toBe('client')
    expect(parseWorkProcessGroupMode(['client'])).toBe('client')
    expect(parseWorkProcessGroupMode(undefined)).toBe('process')
    expect(parseWorkProcessGroupMode('routine')).toBe('process')
    expect(parseWorkProcessGroupMode('')).toBe('process')
  })

  it('serializa group só no modo cliente', () => {
    expect(serializeWorkProcessGroupingQuery({ ...base, group: 'process' }).group).toBeUndefined()
    expect(serializeWorkProcessGroupingQuery({ ...base, group: 'client' }).group).toBe('client')
  })

  it('mapeia modo UI → group_by da API', () => {
    expect(groupByForMode('client')).toBe('client')
    expect(groupByForMode('process')).toBe('routine')
  })

  it('round-trip preserva filtros e deep link group=client', () => {
    const query = serializeWorkProcessGroupingQuery({
      ...base,
      group: 'client',
      q: 'pgdas',
      competence: '2026-07',
      status: 'EM_PROGRESSO',
      client_id: 9,
      department_id: 3,
      page: 2,
      per_page: 50,
      sort: 'progress_percent',
      direction: 'desc'
    })

    expect(query).toMatchObject({
      group: 'client',
      q: 'pgdas',
      competence: '2026-07',
      status: 'EM_PROGRESSO',
      client_id: '9',
      department_id: '3',
      page: '2',
      per_page: '50',
      sort: 'progress_percent',
      direction: 'desc'
    })

    expect(parseWorkProcessGroupingQuery(query as Record<string, unknown>)).toEqual({
      group: 'client',
      q: 'pgdas',
      competence: '2026-07',
      status: 'EM_PROGRESSO',
      client_id: 9,
      department_id: 3,
      page: 2,
      per_page: 50,
      sort: 'progress_percent',
      direction: 'desc'
    })
  })

  it('default Processo omite group e monta process-groups por rotina', () => {
    const parsed = parseWorkProcessGroupingQuery({})
    expect(parsed.group).toBe('process')
    expect(buildProcessGroupsListParams(parsed)).toMatchObject({
      group_by: 'routine',
      page: 1,
      per_page: 20
    })
    expect(buildProcessGroupsListParams({ ...parsed, group: 'client' }).group_by).toBe('client')
  })

  it('sort de grupos em ambos os modos (não flat)', () => {
    expect(parseWorkProcessGroupingQuery({ sort: 'title' }).sort).toBeNull()
    expect(parseWorkProcessGroupingQuery({ sort: 'label' }).sort).toBe('label')
    expect(parseWorkProcessGroupingQuery({ group: 'client', sort: 'label' }).sort).toBe('label')
    expect(parseWorkProcessGroupingQuery({ group: 'client', sort: 'title' }).sort).toBeNull()
  })
})

describe('work-process-grouping toggle e navegação', () => {
  it('níveis por superfície', () => {
    expect(entityLevelForProcesses('client')).toBe('client')
    expect(entityLevelForProcesses('process')).toBe('process')
    expect(entityLevelForTasksSurface()).toBe('task')
  })

  it('Cliente/Processo → /work/processes com group adequado', () => {
    const filters = { q: 'mei', client_id: 4, department_id: 2 }
    expect(processesPathForEntityLevel('client', filters)).toEqual({
      path: '/work/processes',
      query: { q: 'mei', client_id: '4', department_id: '2', group: 'client' }
    })
    expect(processesPathForEntityLevel('process', filters)).toEqual({
      path: '/work/processes',
      query: { q: 'mei', client_id: '4', department_id: '2', group: undefined }
    })
  })

  it('Tarefa → /work/tasks?view=lista preservando filtros compatíveis', () => {
    expect(tasksListaPathForEntityLevel({
      q: 'xml',
      client_id: 7,
      department_id: null
    })).toEqual({
      path: '/work/tasks',
      query: {
        view: 'lista',
        q: 'xml',
        client_id: '7',
        department_id: undefined
      }
    })
  })

  it('componente WorkEntityLevelToggle e wiring nas superfícies', () => {
    const toggle = source('app/components/work/WorkEntityLevelToggle.vue')
    const processes = source('app/pages/work/processes/index.vue')
    const grouping = source('app/composables/useWorkProcessGrouping.ts')
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')

    expect(toggle).toContain('UFieldGroup')
    expect(toggle).toContain('Cliente')
    expect(toggle).toContain('Processo')
    expect(toggle).toContain('Tarefa')
    expect(toggle).toContain('work-entity-level-toggle')
    expect(toggle).toContain('/work/processes')
    expect(toggle).toContain('/work/tasks')
    expect(toggle).not.toMatch(/navigateTo\(['"`]\/work\/clients/)
    expect(toggle).not.toMatch(/navigateTo\(['"`]\/work\/hub/)
    expect(toggle).not.toMatch(/to=['"`]\/work\/clients/)
    expect(toggle).not.toMatch(/to=['"`]\/work\/hub/)

    expect(processes).toContain('WorkEntityLevelToggle')
    expect(processes).toContain('api.work.processGroups.list')
    expect(processes).toContain('buildProcessGroupsListParams')
    expect(processes).not.toContain('buildFlatProcessesListParams')
    expect(processes).toContain('api.work.processes.list')
    expect(processes).toContain('group_by')
    expect(grouping).toContain('group_by')
    expect(grouping).toContain('group_by: groupByForMode(filters.group)')
    const chrome = source('app/components/work/WorkQueueChrome.vue')
    expect(workspace).toContain('<WorkQueueChrome')
    expect(chrome).toContain('WorkEntityLevelToggle')
    expect(chrome).toContain('model-value="task"')
  })
})

describe('work-process-grouping lazy-load e expansão', () => {
  it('expansão multi-open de grupos', () => {
    expect(expandAllGroupKeys(['10', '20'])).toEqual({ 10: true, 20: true })
    expect(toggleGroupKeyExpanded({ 10: true }, '20')).toEqual({ 10: true, 20: true })
    expect(toggleGroupKeyExpanded({ 10: true, 20: true }, '10')).toEqual({ 20: true })
  })

  it('lazy-load cliente usa include_tasks=1 e client_id do grupo', () => {
    expect(buildGroupChildrenListParams(
      { key: '42' },
      'client',
      { ...base, q: 'a', department_id: 3 },
      { page: 1, per_page: 50 }
    )).toEqual({
      include_tasks: 1,
      page: 1,
      per_page: 50,
      q: 'a',
      department_id: 3,
      client_id: 42
    })
  })

  it('lazy-load rotina e Sem rotina (without_template) com tarefas', () => {
    expect(buildGroupChildrenListParams(
      { key: '15' },
      'routine',
      { ...base, client_id: 9 },
      { page: 2, per_page: 50 }
    )).toMatchObject({
      include_tasks: 1,
      work_process_template_id: 15,
      client_id: 9,
      page: 2
    })

    expect(buildGroupChildrenListParams(
      { key: 'manual' },
      'routine',
      base
    )).toMatchObject({
      include_tasks: 1,
      without_template: 1
    })
    expect(buildGroupChildrenListParams(
      { key: 'manual' },
      'routine',
      base
    )).not.toHaveProperty('work_process_template_id')
  })

  it('página agrupa por rotina no modo Processo e por cliente no modo Cliente', () => {
    const page = source('app/pages/work/processes/index.vue')
    const grouping = source('app/composables/useWorkProcessGrouping.ts')

    expect(page).toContain('WorkTaskStatusSelect')
    expect(page).toContain('cascadeProcessTaskSelection')
    expect(page).toContain('work-process-group-expand')
    expect(page).toContain('work-process-group-select')
    expect(page).toContain('cascadeSelectAllProcessesOnPage')
    expect(page).toContain('work-process-task-select')
    expect(page).toContain('Sem empresas neste grupo')
    expect(page).toContain('client_count')
    expect(page).not.toContain('buildFlatProcessesListParams')
    expect(page).not.toContain('flatItems')
    expect(page).not.toContain('Processos do cliente')
    expect(page).not.toContain('Selecionar empresas')
    expect(page).toContain(':mobile-cards="false"')
    expect(page).toContain('work-process-group-tree')
    expect(page).toContain('work-process-group-tree-table')
    expect(page).toContain('work-process-task-table')
    expect(page).toContain('work-process-group-mobile-summary')
    expect(page).not.toContain('work-process-group-tree-card')
    expect(page).not.toContain('<UCard')

    expect(page).toContain('api.work.processGroups.list')
    expect(page).toContain('buildProcessGroupsListParams')
    expect(page).not.toContain('USlideover')
    expect(page).toContain('buildGroupChildrenListParams')
    expect(page).toContain('work-process-child-select')
    expect(page).toContain('work-process-child-expand')
    expect(page).toContain('selectedTaskBulkItems')

    expect(grouping).toContain('include_tasks: 1')
    expect(grouping).toContain('group_by: groupByForMode(filters.group)')
    expect(page).not.toMatch(/navigateTo\(['"`]\/work\/clients/)
    expect(page).not.toMatch(/to=['"`]\/work\/hub/)
  })

  it('estados empty / filtered / error na listagem', () => {
    const page = source('app/pages/work/processes/index.vue')
    expect(page).toContain('empty-kind')
    expect(page).toContain('emptyKind')
    expect(page).toContain('filtered')
    expect(page).toContain('work-processes-clear-filters')
    expect(page).toContain('ShellListFilterToolbar')
    expect(page).toContain('ShellDataTable')
    expect(page).toContain('ShellPagePanel')
  })

  it('preserva métricas prioritárias na linha pai em viewport estreita', () => {
    const page = source('app/pages/work/processes/index.vue')
    const summary = page
      .split('data-testid="work-process-group-mobile-summary"')[0]
      ?.split('<dl')
      .at(-1) ?? ''

    expect(summary).toContain('md:hidden')
    expect(summary).toContain('grid-cols-2')
    expect(summary).not.toContain('overflow-x')
    expect(page).toContain('{{ isClientMode ? \'Processos\' : \'Instâncias\' }}')
    expect(page).toContain('Tarefas abertas')
    expect(page).toContain('Progresso')
    expect(page).toContain('Próximo prazo')
    expect(page).toContain('{{ row.original.process_count }}')
    expect(page).toContain('{{ row.original.open_task_count }}')
    expect(page).toContain('{{ row.original.progress_percent ?? 0 }}%')
    expect(page).toContain('formatDueDate(row.original.next_due_date)')
  })
})

describe('work-process-grouping presets e API client', () => {
  it('presets preservam group=client', () => {
    const payload = workProcessesFiltersToPayload({
      q: 'x',
      competence: '',
      status: 'all',
      client_id: null,
      department_id: null,
      group: 'client'
    })
    expect(payload.group).toBe('client')
    expect(workProcessesPayloadToFilters(payload).group).toBe('client')

    const without = workProcessesFiltersToPayload({
      q: '',
      competence: '',
      status: 'all',
      client_id: null,
      department_id: null,
      group: null
    })
    expect(without.group).toBeUndefined()
  })

  it('hasActive distingue filtros de toolbar', () => {
    expect(hasActiveWorkProcessGroupingFilters(base)).toBe(false)
    expect(hasActiveWorkProcessGroupingFilters({ ...base, q: 'a' })).toBe(true)
  })

  it('createWorkApi expõe processGroups.list e params de processes', async () => {
    const clientMock = vi.fn(async () => ({ data: [], meta: { total: 0 } }))
    const client = clientMock as unknown as ApiClient
    const apiUrl = vi.fn((path: string) => path) as ApiUrl
    const api = createWorkApi(client, apiUrl)

    await api.work.processGroups.list({
      group_by: 'routine',
      page: 1,
      per_page: 20,
      sort: 'label'
    })
    await api.work.processes.list({
      include_tasks: 1,
      work_process_template_id: 15,
      page: 1
    })

    expect(clientMock).toHaveBeenCalledWith('/api/v1/work/process-groups', {
      query: {
        group_by: 'routine',
        page: 1,
        per_page: 20,
        sort: 'label'
      }
    })
    expect(clientMock).toHaveBeenCalledWith('/api/v1/work/processes', {
      query: {
        include_tasks: 1,
        work_process_template_id: 15,
        page: 1
      }
    })
  })
})

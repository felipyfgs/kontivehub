/**
 * Estado de URL e helpers da visão de processos
 * (`/work/processes` + seletor Cliente|Processo|Tarefa).
 *
 * - `group=client` → grupos por cliente (`GET /work/process-groups?group_by=client`)
 * - `group` omitido → grupos por rotina (`GET /work/process-groups?group_by=routine`)
 * - Tarefa → navega para `/work/tasks?view=lista` (não renderiza Lista em Processos)
 *
 * `GET /work/processes` é usado somente para carregar os filhos dos grupos.
 */
import type {
  WorkProcessGroup,
  WorkEntityLevel,
  WorkProcessGroupBy,
  WorkProcessGroupMode,
  WorkProcessGroupSort
} from '~/types/work'
import { WORK_PROCESS_GROUP_MANUAL_KEY } from '~/types/work'
import type {
  WorkProcessGroupsListParams,
  WorkProcessesListParams
} from '~/composables/api/createWorkApi'

export type WorkProcessListSort = WorkProcessGroupSort

export interface WorkProcessGroupingFilters {
  /** Persistido na URL só quando `client`. */
  group: WorkProcessGroupMode
  q: string
  competence: string
  status: string
  client_id: number | null
  department_id: number | null
  page: number
  per_page: number
  sort: WorkProcessListSort | null
  direction: 'asc' | 'desc' | null
}

export const WORK_PROCESS_GROUP_SORT_WHITELIST: readonly WorkProcessGroupSort[] = [
  'label',
  'process_count',
  'open_task_count',
  'next_due_date',
  'progress_percent'
] as const

const EMPTY: WorkProcessGroupingFilters = {
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

function queryScalar(value: unknown): string {
  const raw = Array.isArray(value) ? value[0] : value
  if (raw === undefined || raw === null) return ''
  return String(raw)
}

function numOrNull(value: unknown): number | null {
  if (value === undefined || value === null || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : null
}

export function parseWorkProcessGroupMode(value: unknown): WorkProcessGroupMode {
  return queryScalar(value) === 'client' ? 'client' : 'process'
}

/** Modo UI → `group_by` da API (`client` | `routine`). */
export function groupByForMode(mode: WorkProcessGroupMode): WorkProcessGroupBy {
  return mode === 'client' ? 'client' : 'routine'
}

export function parseWorkProcessGroupSort(value: unknown): WorkProcessGroupSort | null {
  const sort = queryScalar(value).trim()
  return (WORK_PROCESS_GROUP_SORT_WHITELIST as readonly string[]).includes(sort)
    ? sort as WorkProcessGroupSort
    : null
}

export function parseWorkProcessGroupingQuery(
  query: Record<string, unknown>
): WorkProcessGroupingFilters {
  const directionRaw = queryScalar(query.direction).toLowerCase()
  const direction = directionRaw === 'asc' || directionRaw === 'desc'
    ? directionRaw
    : null
  const group = parseWorkProcessGroupMode(query.group)
  // Ambos os modos usam process-groups — whitelist de sort de grupos.
  const sort = parseWorkProcessGroupSort(query.sort)
  const status = queryScalar(query.status).trim() || 'all'
  return {
    group,
    q: queryScalar(query.q),
    competence: queryScalar(query.competence).trim(),
    status: status === 'all' ? 'all' : status,
    client_id: numOrNull(query.client_id),
    department_id: numOrNull(query.department_id),
    page: Math.max(1, Number(query.page) || 1),
    per_page: Math.min(100, Math.max(1, Number(query.per_page) || 20)),
    sort,
    direction: sort ? (direction || 'asc') : null
  }
}

/** Serializa filtros; omite `group` no modo Processo (grupos por rotina). */
export function serializeWorkProcessGroupingQuery(
  filters: WorkProcessGroupingFilters
): Record<string, string | undefined> {
  return {
    group: filters.group === 'client' ? 'client' : undefined,
    q: filters.q.trim() || undefined,
    competence: filters.competence.trim() || undefined,
    status: filters.status && filters.status !== 'all' ? filters.status : undefined,
    client_id: filters.client_id ? String(filters.client_id) : undefined,
    department_id: filters.department_id ? String(filters.department_id) : undefined,
    page: filters.page > 1 ? String(filters.page) : undefined,
    per_page: filters.per_page !== 20 ? String(filters.per_page) : undefined,
    sort: filters.sort || undefined,
    direction: filters.sort ? (filters.direction || 'asc') : undefined
  }
}

export function entityLevelForProcesses(mode: WorkProcessGroupMode): WorkEntityLevel {
  return mode === 'client' ? 'client' : 'process'
}

export function entityLevelForTasksSurface(): WorkEntityLevel {
  return 'task'
}

/** Filtros compartilhados ao trocar Cliente ↔ Processo ↔ Tarefa. */
export function entityNavigationFilters(
  filters: Pick<WorkProcessGroupingFilters, 'q' | 'client_id' | 'department_id'>
): { q?: string, client_id?: string, department_id?: string } {
  return {
    q: filters.q.trim() || undefined,
    client_id: filters.client_id ? String(filters.client_id) : undefined,
    department_id: filters.department_id ? String(filters.department_id) : undefined
  }
}

export function processesPathForEntityLevel(
  level: Exclude<WorkEntityLevel, 'task'>,
  filters: Pick<WorkProcessGroupingFilters, 'q' | 'client_id' | 'department_id'>
): { path: string, query: Record<string, string | undefined> } {
  const sharedFilters = entityNavigationFilters(filters)
  return {
    path: '/work/processes',
    query: {
      ...sharedFilters,
      group: level === 'client' ? 'client' : undefined
    }
  }
}

export function tasksListaPathForEntityLevel(
  filters: Pick<WorkProcessGroupingFilters, 'q' | 'client_id' | 'department_id'>
): { path: string, query: Record<string, string | undefined> } {
  return {
    path: '/work/tasks',
    query: {
      view: 'lista',
      ...entityNavigationFilters(filters)
    }
  }
}

/** Params de `GET /work/process-groups` conforme modo (`client` | `routine`). */
export function buildProcessGroupsListParams(
  filters: WorkProcessGroupingFilters
): WorkProcessGroupsListParams {
  const params: WorkProcessGroupsListParams = {
    group_by: groupByForMode(filters.group),
    page: filters.page,
    per_page: filters.per_page
  }
  if (filters.q.trim()) params.q = filters.q.trim()
  if (filters.competence.trim()) params.competence = filters.competence.trim()
  if (filters.status && filters.status !== 'all') params.status = filters.status
  if (filters.client_id) params.client_id = filters.client_id
  if (filters.department_id) params.department_id = filters.department_id
  if (filters.sort && (WORK_PROCESS_GROUP_SORT_WHITELIST as readonly string[]).includes(filters.sort)) {
    params.sort = filters.sort as WorkProcessGroupSort
    params.direction = filters.direction || 'asc'
  }
  return params
}

/**
 * Params de lazy-load dos processos filhos do grupo
 * (`include_tasks=1` + filtro do grupo + toolbar).
 */
export function buildGroupChildrenListParams(
  group: Pick<WorkProcessGroup, 'key'>,
  groupBy: WorkProcessGroupBy,
  filters: Pick<
    WorkProcessGroupingFilters,
    'q' | 'competence' | 'status' | 'client_id' | 'department_id'
  >,
  opts?: { page?: number, per_page?: number }
): WorkProcessesListParams {
  const params: WorkProcessesListParams = {
    include_tasks: 1,
    page: opts?.page ?? 1,
    per_page: opts?.per_page ?? 50
  }
  if (filters.q.trim()) params.q = filters.q.trim()
  if (filters.competence.trim()) params.competence = filters.competence.trim()
  if (filters.status && filters.status !== 'all') params.status = filters.status
  if (filters.department_id) params.department_id = filters.department_id

  if (groupBy === 'client') {
    const clientId = Number(group.key)
    if (Number.isInteger(clientId) && clientId > 0) {
      params.client_id = clientId
    }
  } else if (group.key === WORK_PROCESS_GROUP_MANUAL_KEY) {
    params.without_template = 1
    if (filters.client_id) params.client_id = filters.client_id
  } else {
    const templateId = Number(group.key)
    if (Number.isInteger(templateId) && templateId > 0) {
      params.work_process_template_id = templateId
    }
    if (filters.client_id) params.client_id = filters.client_id
  }

  return params
}

export function hasActiveWorkProcessGroupingFilters(
  filters: WorkProcessGroupingFilters
): boolean {
  if (filters.q.trim()) return true
  if (filters.competence.trim()) return true
  if (filters.status && filters.status !== 'all') return true
  if (filters.client_id) return true
  if (filters.department_id) return true
  return false
}

export function emptyWorkProcessGroupingFilters(): WorkProcessGroupingFilters {
  return { ...EMPTY }
}

export function useWorkProcessGrouping() {
  const route = useRoute()
  const router = useRouter()

  const filters = computed(() =>
    parseWorkProcessGroupingQuery(route.query as Record<string, unknown>)
  )

  const entityLevel = computed<WorkEntityLevel>(() =>
    entityLevelForProcesses(filters.value.group)
  )

  const groupBy = computed(() => groupByForMode(filters.value.group))

  async function replaceFilters(
    partial: Partial<WorkProcessGroupingFilters>,
    opts?: { resetPage?: boolean }
  ) {
    const next: WorkProcessGroupingFilters = { ...filters.value, ...partial }
    if (
      opts?.resetPage !== false
      && partial.page === undefined
      && (
        partial.group !== undefined
        || partial.q !== undefined
        || partial.competence !== undefined
        || partial.status !== undefined
        || partial.client_id !== undefined
        || partial.department_id !== undefined
        || partial.sort !== undefined
        || partial.direction !== undefined
        || partial.per_page !== undefined
      )
    ) {
      next.page = 1
    }
    // Ao trocar Cliente ↔ Processo, limpa sort fora da whitelist de grupos.
    if (partial.group !== undefined && partial.sort === undefined) {
      if (next.sort && !(WORK_PROCESS_GROUP_SORT_WHITELIST as readonly string[]).includes(next.sort)) {
        next.sort = null
        next.direction = null
      }
    }
    await router.replace({
      path: '/work/processes',
      query: serializeWorkProcessGroupingQuery(next)
    })
  }

  async function navigateEntityLevel(level: WorkEntityLevel) {
    const current = filters.value
    const sharedFilters = {
      q: current.q,
      client_id: current.client_id,
      department_id: current.department_id
    }
    if (level === 'task') {
      const target = tasksListaPathForEntityLevel(sharedFilters)
      await navigateTo(target)
      return
    }
    if (level === 'client' && current.group === 'client') return
    if (level === 'process' && current.group === 'process') return
    const target = processesPathForEntityLevel(level, sharedFilters)
    await navigateTo(target)
  }

  return {
    filters,
    entityLevel,
    groupBy,
    replaceFilters,
    navigateEntityLevel
  }
}

/**
 * Filtros da fila `/work/tasks` na query string.
 *
 * Padrão do painel:
 * - **Recurso (tarefa selecionada)** → path: `/work/tasks/{id}`
 * - **Filtros de lista** (tab, q, page, view, …) → query string
 *
 * Nunca colocar `task` / `office_id` na query.
 */

export type WorkQueueView = 'fila' | 'lista' | 'kanban'

export interface WorkQueueFilters {
  tab: string
  q: string
  department_id: number | null
  assignee_membership_id: number | null
  client_id: number | null
  scope: string
  page: number
  per_page: number
  /** Apresentação: Fila (mestre–detalhe), Lista (tabular) ou Kanban (board). */
  view: WorkQueueView
  sort: string | null
  direction: 'asc' | 'desc' | null
}

/** Tabs de status da Fila/Lista incompatíveis com o eixo de colunas do Kanban. */
const KANBAN_STATUS_TABS = new Set(['open', 'impedidas', 'concluidas'])

const EMPTY: WorkQueueFilters = {
  tab: 'open',
  q: '',
  department_id: null,
  assignee_membership_id: null,
  client_id: null,
  scope: 'default',
  page: 1,
  per_page: 10,
  view: 'fila',
  sort: null,
  direction: null
}

function numOrNull(v: unknown): number | null {
  if (v === undefined || v === null || v === '') return null
  const n = Number(v)
  return Number.isFinite(n) && n > 0 ? n : null
}

function queryScalar(value: unknown): string {
  const raw = Array.isArray(value) ? value[0] : value
  if (raw === undefined || raw === null) return ''
  return String(raw)
}

export function parseWorkQueueView(value: unknown): WorkQueueView {
  const view = queryScalar(value)
  if (view === 'lista' || view === 'kanban') return view
  return 'fila'
}

/** Default de `tab` por visão quando o parâmetro está ausente na URL. */
export function defaultWorkQueueTab(view: WorkQueueView): string {
  return view === 'kanban' ? 'todas' : 'open'
}

/**
 * Coerção de `tab` ao trocar (ou interpretar) visão:
 * - entrar em kanban com open|impedidas|concluidas → todas
 * - sair de kanban com todas → open
 * - hoje|atrasadas|semana preservados
 */
export function coerceWorkQueueTabForView(tab: string, view: WorkQueueView): string {
  if (view === 'kanban') {
    if (KANBAN_STATUS_TABS.has(tab)) return 'todas'
    return tab || 'todas'
  }
  if (tab === 'todas') return 'open'
  return tab || 'open'
}

function shouldOmitTabInQuery(tab: string, view: WorkQueueView): boolean {
  return tab === defaultWorkQueueTab(view)
}

export function parseWorkQueueQuery(query: Record<string, unknown>): WorkQueueFilters {
  const view = parseWorkQueueView(query.view)
  const tabRaw = queryScalar(query.tab).trim()
  const tab = coerceWorkQueueTabForView(tabRaw || defaultWorkQueueTab(view), view)
  const directionRaw = String(query.direction || '').toLowerCase()
  const direction = directionRaw === 'asc' || directionRaw === 'desc'
    ? directionRaw
    : null
  const sortRaw = String(query.sort || '').trim()
  return {
    tab,
    q: String(query.q || ''),
    department_id: numOrNull(query.department_id),
    assignee_membership_id: numOrNull(query.assignee_membership_id),
    client_id: numOrNull(query.client_id),
    scope: String(query.scope || 'default'),
    page: Math.max(1, Number(query.page) || 1),
    per_page: Math.min(100, Math.max(1, Number(query.per_page) || 10)),
    view,
    sort: sortRaw || null,
    direction
  }
}

export function serializeWorkQueueQuery(f: WorkQueueFilters): Record<string, string | undefined> {
  const tab = coerceWorkQueueTabForView(f.tab, f.view)
  return {
    tab: shouldOmitTabInQuery(tab, f.view) ? undefined : tab,
    q: f.q.trim() || undefined,
    department_id: f.department_id ? String(f.department_id) : undefined,
    assignee_membership_id: f.assignee_membership_id ? String(f.assignee_membership_id) : undefined,
    client_id: f.client_id ? String(f.client_id) : undefined,
    scope: f.scope === 'default' ? undefined : f.scope,
    page: f.page > 1 ? String(f.page) : undefined,
    per_page: f.per_page !== 10 ? String(f.per_page) : undefined,
    view: f.view === 'fila' ? undefined : f.view,
    sort: f.sort || undefined,
    direction: f.sort && f.direction ? f.direction : undefined
  }
}

/** Path canônico do recurso tarefa (deep-link / mestre–detalhe). */
export function workTaskPath(taskId: number): string {
  return `/work/tasks/${taskId}`
}

/** Path da fila sem seleção. */
export function workQueuePath(): string {
  return '/work/tasks'
}

export function useWorkQueueFilters() {
  const route = useRoute()
  const router = useRouter()

  const filters = computed(() => parseWorkQueueQuery(route.query as Record<string, unknown>))

  /** ID da tarefa no path (`/work/tasks/:id`), nunca na query. */
  const selectedTaskId = computed((): number | null => {
    const raw = route.params.id
    if (typeof raw === 'string' && raw !== '') {
      return numOrNull(raw)
    }
    // Compat legado: ?task=N → redirecionar (middleware na página)
    return numOrNull((route.query as Record<string, unknown>).task)
  })

  async function patch(partial: Partial<WorkQueueFilters>, opts?: { resetPage?: boolean }) {
    const next: WorkQueueFilters = { ...filters.value, ...partial }
    if (partial.view !== undefined) {
      next.tab = coerceWorkQueueTabForView(
        partial.tab !== undefined ? partial.tab : next.tab,
        partial.view
      )
    }
    if (opts?.resetPage !== false && (
      partial.tab !== undefined
      || partial.view !== undefined
      || partial.q !== undefined
      || partial.department_id !== undefined
      || partial.assignee_membership_id !== undefined
      || partial.client_id !== undefined
      || partial.scope !== undefined
      || partial.sort !== undefined
      || partial.direction !== undefined
    ) && partial.page === undefined) {
      next.page = 1
    }
    const query = serializeWorkQueueQuery(next)
    // Mantém o path atual (fila ou tarefa); só atualiza filtros.
    await router.replace({ path: route.path, query })
  }

  async function selectTask(taskId: number) {
    await router.replace({
      path: workTaskPath(taskId),
      query: serializeWorkQueueQuery(filters.value)
    })
  }

  async function clearTask() {
    await router.replace({
      path: workQueuePath(),
      query: serializeWorkQueueQuery(filters.value)
    })
  }

  function apiParams(): Record<string, string | number> {
    const f = filters.value
    const params: Record<string, string | number> = {
      tab: f.tab,
      page: f.page,
      per_page: f.per_page,
      scope: f.scope
    }
    if (f.q.trim()) params.q = f.q.trim()
    if (f.department_id) params.department_id = f.department_id
    if (f.assignee_membership_id) params.assignee_membership_id = f.assignee_membership_id
    if (f.client_id) params.client_id = f.client_id
    if (f.sort) {
      params.sort = f.sort
      params.direction = f.direction || 'asc'
    }
    return params
  }

  function reset() {
    return router.replace({ path: workQueuePath(), query: serializeWorkQueueQuery({ ...EMPTY }) })
  }

  return {
    filters,
    selectedTaskId,
    patch,
    selectTask,
    clearTask,
    apiParams,
    reset,
    parseWorkQueueQuery,
    serializeWorkQueueQuery,
    workTaskPath,
    workQueuePath
  }
}

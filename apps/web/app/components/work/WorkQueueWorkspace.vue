<script setup lang="ts">
/**
 * Workspace da fila de trabalho — Fila | Lista | Kanban.
 *
 * URL canônica:
 * - `/work/tasks` — sem seleção
 * - `/work/tasks/{id}` — tarefa no path
 * - estado de sessão: filtros + `view=fila|lista|kanban`; tarefa fica no path
 */
import { breakpointsTailwind } from '@vueuse/core'
import { h } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import UCheckbox from '@nuxt/ui/components/Checkbox.vue'
import type { WorkTaskDetail, WorkTaskSummary, WorkDepartment, WorkEntityLevel } from '~/types/work'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import { apiErrorMessage, apiErrorStatus } from '~/utils/api-error'
import {
  coerceWorkQueueTabForView,
  useWorkQueueFilters,
  type WorkQueueView
} from '~/composables/useWorkQueueFilters'
import {
  hasActiveWorkQueueFiltersForSave,
  workQueueFiltersToPayload,
  workQueuePayloadToFilters
} from '~/utils/saved-list-filters'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import { formatDueDate } from '~/utils/work-labels'
import { sortHeader } from '~/utils/table-sort'
import { WORK_TABLE_COL } from '~/utils/work-table-columns'
import { canAdministerWork, canExecuteWorkTasks } from '~/utils/permissions'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'
import ShellListEmpty from '~/components/shell/ListEmpty.vue'
import ShellScrollableTabs from '~/components/shell/ScrollableTabs.vue'
import ShellDataTable from '~/components/shell/DataTable.vue'
import WorkBulkActionsModal from '~/components/work/WorkBulkActionsModal.vue'
import type { WorkBulkItem } from '~/components/work/WorkBulkActionsModal.vue'
import WorkTaskStatusSelect from '~/components/work/WorkTaskStatusSelect.vue'
import WorkKanbanBoard from '~/components/work/WorkKanbanBoard.vue'
import WorkQueueChrome from '~/components/work/WorkQueueChrome.vue'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'
import { restoreWorkSelectionFocus } from '~/utils/work-focus'
import { createWorkAssigneeFilterModel } from '~/utils/work-queue-filter-models'

const api = useApi()
const toast = useToast()
const { me, sessionEpoch } = useDashboard()
const {
  filters,
  selectedTaskId,
  patch,
  selectTask,
  clearTask,
  apiParams
} = useWorkQueueFilters()

const canExecute = computed(() => canExecuteWorkTasks(me.value))
const canAdmin = computed(() => canAdministerWork(me.value))
const rowSelection = ref<Record<string, boolean>>({})
const bulkOpen = ref(false)
const selectionOrigin = ref<HTMLElement | null>(null)

const departments = ref<WorkDepartment[]>([])
const tenantMembers = ref<Array<{ id: number, name: string }>>([])

onMounted(async () => {
  try {
    const [deptRes, membersRes] = await Promise.all([
      api.work.departments.list({ per_page: 100, is_active: true }),
      api.tenant.members.list()
    ])
    departments.value = Array.isArray(deptRes?.data) ? deptRes.data : []
    tenantMembers.value = Array.isArray(membersRes?.data)
      ? membersRes.data
          .filter(m => m.is_active !== false)
          .map(m => ({
            id: m.id,
            name: m.name || m.email || `Membro #${m.id}`
          }))
      : []
  } catch {
    departments.value = []
    tenantMembers.value = []
  }
})

const queueDefinitions = computed((): DataTableFilterDefinition[] => [
  {
    key: 'department_id',
    kind: 'option',
    label: 'Departamento',
    emptyValue: '',
    items: departments.value.map(d => ({ label: d.name, value: String(d.id) }))
  },
  {
    key: 'assignee_membership_id',
    kind: 'option',
    label: 'Responsável',
    emptyValue: '',
    items: tenantMembers.value.map(m => ({ label: m.name, value: String(m.id) }))
  },
  {
    key: 'client_id',
    kind: 'client',
    label: 'Cliente',
    emptyValue: null
  },
  {
    key: 'scope',
    kind: 'option',
    label: 'Escopo',
    emptyValue: 'default',
    items: [
      { label: 'Minhas', value: 'mine' },
      { label: 'Departamento', value: 'department' },
      { label: 'Escritório', value: 'tenant' }
    ]
  }
])

function queueModelsFromFilters(): DataTableFilterModel[] {
  const models: DataTableFilterModel[] = []
  const f = filters.value
  const defs = queueDefinitions.value

  if (f.department_id) {
    const def = findDefinition(defs, 'department_id')
    if (def) {
      const model = createFilterModel(def, String(f.department_id))
      if (model) models.push(model)
    }
  }
  if (f.assignee_membership_id) {
    const def = findDefinition(defs, 'assignee_membership_id')
    const known = tenantMembers.value.find(m => m.id === f.assignee_membership_id)
    // Chip visível mesmo se as opções ainda não carregaram ou o factory rejeitar o valor.
    models.push(createWorkAssigneeFilterModel(
      def,
      f.assignee_membership_id,
      known?.name || `Responsável #${f.assignee_membership_id}`
    ))
  }
  if (f.client_id) {
    const def = findDefinition(defs, 'client_id')
    if (def) {
      const model = createFilterModel(def, f.client_id)
      if (model) models.push(model)
    }
  }
  if (f.scope && f.scope !== 'default') {
    const def = findDefinition(defs, 'scope')
    if (def) {
      const model = createFilterModel(def, f.scope)
      if (model) models.push(model)
    }
  }
  return models
}

const queueChipModels = computed(() => queueModelsFromFilters())

function onQueueModelsUpdate(models: DataTableFilterModel[]) {
  const dept = models.find(m => m.key === 'department_id')
  const assignee = models.find(m => m.key === 'assignee_membership_id')
  const client = models.find(m => m.key === 'client_id')
  const scope = models.find(m => m.key === 'scope')
  void patch({
    department_id: dept ? Number(dept.value) || null : null,
    assignee_membership_id: assignee ? Number(assignee.value) || null : null,
    client_id: client && typeof client.value === 'number' ? client.value : null,
    scope: scope ? String(scope.value) : 'default'
  })
}

function onQueueClear() {
  void patch({
    tab: isKanban.value ? 'todas' : 'open',
    q: '',
    department_id: null,
    assignee_membership_id: null,
    client_id: null,
    scope: 'default',
    page: 1
  })
}

function onQueuePreset(payload: SavedListFilterPayload) {
  const next = workQueuePayloadToFilters(payload)
  void patch({
    tab: next.tab,
    q: next.q,
    department_id: next.department_id,
    assignee_membership_id: next.assignee_membership_id,
    client_id: next.client_id,
    scope: next.scope,
    page: 1,
    per_page: next.per_page
  }, { resetPage: false })
}

const items = ref<WorkTaskSummary[]>([])
const detail = ref<WorkTaskDetail | null>(null)
const loading = ref(false)
const detailLoading = ref(false)
const loadError = ref<string | null>(null)
const total = ref(0)
type QueueItemRef = {
  el: HTMLElement | null
  focus: () => boolean
}

const itemRefs = ref<Record<number, QueueItemRef | null>>({})
const lastFocusedTaskId = ref<number | null>(null)
/**
 * Desktop Fila: mestre–detalhe estilo inbox/chat.
 * Detalhe abre só com seleção explícita ou deep-link (sem auto-seleção).
 */
const detailOpen = ref(false)
const detailError = ref<string | null>(null)

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')

const queueView = computed(() => filters.value.view)
const isLista = computed(() => queueView.value === 'lista')
const isKanban = computed(() => queueView.value === 'kanban')
const isFila = computed(() => queueView.value === 'fila')
const detailPaneVisible = computed(
  () => isFila.value && !isMobile.value && detailOpen.value
)

function setQueueView(next: WorkQueueView) {
  if (filters.value.view === next) return
  if (next === 'fila') {
    detailOpen.value = Boolean(selectedTaskId.value)
  }
  const tab = coerceWorkQueueTabForView(filters.value.tab, next)
  void patch({ view: next, tab }, { resetPage: false })
}

async function onEntityLevel(level: WorkEntityLevel) {
  if (level === 'task') return
  const sharedFilters = {
    q: filters.value.q,
    client_id: filters.value.client_id,
    department_id: filters.value.department_id
  }
  publishSurfaceNavigationIntent('work-process-grouping', {
    ...sharedFilters,
    group: level === 'client' ? 'client' : 'process'
  })
  await navigateTo('/work/processes')
}

const filaListaTabs = [
  { label: 'Abertas', value: 'open' },
  { label: 'Hoje', value: 'hoje' },
  { label: 'Atrasadas', value: 'atrasadas' },
  { label: 'Semana', value: 'semana' },
  { label: 'Sem responsável', value: 'sem_responsavel' },
  { label: 'Impedidas', value: 'impedidas' },
  { label: 'Concluídas', value: 'concluidas' }
]

const kanbanTabs = [
  { label: 'Todas', value: 'todas' },
  { label: 'Hoje', value: 'hoje' },
  { label: 'Atrasadas', value: 'atrasadas' },
  { label: 'Semana', value: 'semana' },
  { label: 'Sem responsável', value: 'sem_responsavel' }
]

/** Fila/Lista: status e urgência; Kanban: recortes transversais ao board. */
const tabs = computed(() => (isKanban.value ? kanbanTabs : filaListaTabs))

const selectedTab = computed({
  get: () => filters.value.tab,
  set: (v: string) => {
    void patch({ tab: v, page: 1 }, { resetPage: false })
  }
})

const selectedId = selectedTaskId

const detailSlideoverOpen = computed({
  get: () => {
    if (!selectedId.value) return false
    return isLista.value || isKanban.value || isMobile.value
  },
  set: (open: boolean) => {
    if (!open) void clearSelection()
  }
})

async function loadQueue() {
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const params = apiParams()
    if (isKanban.value) {
      params.per_page = 100
      params.page = 1
    }
    const res = await api.work.queue(params)
    if (epoch !== sessionEpoch.value) return
    items.value = res.data
    total.value = res.meta.total
    rowSelection.value = {}

    // Deep-link fora da página filtrada: mantém path; detalhe via watcher de selectedTaskId.
    if (!selectedTaskId.value) {
      detail.value = null
    }
  } catch (e) {
    if (epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(e, 'Não foi possível carregar a fila.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

async function loadDetail(id: number) {
  const epoch = sessionEpoch.value
  detailLoading.value = true
  detailError.value = null
  try {
    const res = await api.work.tasks.get(id)
    if (epoch !== sessionEpoch.value) return
    if (selectedTaskId.value !== id) return
    detail.value = res.data
    detailError.value = null
  } catch (e) {
    if (epoch !== sessionEpoch.value) return
    if (selectedTaskId.value !== id) return
    if (apiErrorStatus(e) === 404) {
      await clearSelection()
      return
    }
    detailError.value = apiErrorMessage(e, 'Falha ao carregar tarefa.')
    toast.add({ title: detailError.value, color: 'error' })
    detail.value = null
  } finally {
    if (epoch === sessionEpoch.value) detailLoading.value = false
  }
}

async function select(id: number) {
  const activeElement = document.activeElement
  selectionOrigin.value = activeElement instanceof HTMLElement && activeElement !== document.body
    ? activeElement
    : null
  lastFocusedTaskId.value = id
  await selectTask(id)
  if (isFila.value && !isMobile.value) detailOpen.value = true
  // loadDetail via watcher de selectedTaskId (evita fetch duplicado)
  if (isFila.value) {
    nextTick(() => {
      const ref = itemRefs.value[id]
      const el = ref?.el
      el?.scrollIntoView({ block: 'nearest' })
    })
  }
}

async function focusQueueItem(id: number | null): Promise<void> {
  if (!id || !isFila.value) return
  await nextTick()
  itemRefs.value[id]?.focus()
}

function toggleDetail() {
  if (!isFila.value) return
  detailOpen.value = !detailOpen.value
  if (!detailOpen.value) void focusQueueItem(selectedTaskId.value)
}

async function clearSelection() {
  const focusId = selectedTaskId.value ?? lastFocusedTaskId.value
  const origin = selectionOrigin.value
  detailOpen.value = false
  detail.value = null
  detailError.value = null
  await clearTask()
  await restoreWorkSelectionFocus(origin, () => focusQueueItem(focusId))
  selectionOrigin.value = null
}

function retryDetail() {
  const id = selectedTaskId.value
  if (id) void loadDetail(id)
}

const search = computed({
  get: () => filters.value.q,
  set: (v: string) => { void patch({ q: v }) }
})

function onQueueSearch(value: string) {
  search.value = value
}

function onListPage(page: number) {
  void patch({ page }, { resetPage: false })
}

function onListPerPage(perPage: number) {
  void patch({ per_page: perPage, page: 1 }, { resetPage: false })
}

const taskListColumns = computed<TableColumn<WorkTaskSummary>[]>(() => {
  const selectColumn: TableColumn<WorkTaskSummary> = {
    id: 'select',
    enableHiding: false,
    enableSorting: false,
    meta: { class: WORK_TABLE_COL.select },
    header: ({ table: current }) => h(UCheckbox, {
      'modelValue': current.getIsSomePageRowsSelected()
        ? 'indeterminate'
        : current.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: unknown) =>
        current.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Selecionar todas as tarefas desta página'
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: unknown) => row.toggleSelected(!!value),
      'ariaLabel': `Selecionar ${row.original.title}`
    })
  }

  const columns: TableColumn<WorkTaskSummary>[] = [
    {
      accessorKey: 'title',
      header: ({ column }) => sortHeader('Tarefa', column),
      meta: { class: WORK_TABLE_COL.primary }
    },
    {
      accessorKey: 'status',
      header: ({ column }) => sortHeader('Status', column),
      meta: { class: WORK_TABLE_COL.status }
    },
    {
      id: 'effective_due_date',
      accessorKey: 'effective_due_date',
      header: ({ column }) => sortHeader('Prazo', column),
      meta: { class: WORK_TABLE_COL.due }
    },
    {
      id: 'client_name',
      accessorKey: 'client_name',
      header: ({ column }) => sortHeader('Cliente / Processo', column),
      enableSorting: true,
      meta: { class: WORK_TABLE_COL.secondary }
    },
    {
      id: 'assignee_name',
      accessorKey: 'assignee_name',
      header: ({ column }) => sortHeader('Responsável', column),
      meta: { class: WORK_TABLE_COL.assignee }
    },
    {
      accessorKey: 'actions',
      header: '',
      enableSorting: false,
      meta: { class: WORK_TABLE_COL.actions }
    }
  ]

  return (canExecute.value || canAdmin.value) ? [selectColumn, ...columns] : columns
})

const sortingState = computed(() => {
  if (!filters.value.sort) return []
  return [{ id: filters.value.sort, desc: filters.value.direction === 'desc' }]
})

function onListSortingUpdate(next: Array<{ id: string, desc: boolean }>) {
  const first = next[0]
  const allowed = new Set(['title', 'status', 'effective_due_date', 'client_name', 'assignee_name'])
  if (!first || !allowed.has(first.id)) {
    void patch({ sort: null, direction: null, page: 1 }, { resetPage: false })
    return
  }
  void patch({
    sort: first.id,
    direction: first.desc ? 'desc' : 'asc',
    page: 1
  }, { resetPage: false })
}

const selectedTaskBulkItems = computed<WorkBulkItem[]>(() =>
  items.value
    .filter(task => rowSelection.value[String(task.id)] === true)
    .map(task => ({
      id: task.id,
      lock_version: task.lock_version,
      label: task.title
    }))
)

const selectedCount = computed(() => selectedTaskBulkItems.value.length)

const canBulk = computed(() => canExecute.value || canAdmin.value)

const hasQueueResultFilters = computed(() => {
  const f = filters.value
  return f.tab !== (isKanban.value ? 'todas' : 'open')
    || Boolean(f.q.trim())
    || Boolean(f.department_id)
    || Boolean(f.assignee_membership_id)
    || Boolean(f.client_id)
    || f.scope !== 'default'
})

const queueEmptyKind = computed<'empty' | 'filtered' | 'error'>(() => {
  if (loadError.value) return 'error'
  return hasQueueResultFilters.value ? 'filtered' : 'empty'
})

const queueEmptyTitle = computed(() => {
  if (loadError.value) return 'Falha ao carregar tarefas'
  return hasQueueResultFilters.value
    ? 'Nenhuma tarefa encontrada'
    : 'Nenhuma tarefa nesta aba'
})

const queueEmptyDescription = computed(() => {
  if (loadError.value) return loadError.value
  return hasQueueResultFilters.value
    ? 'Ajuste ou limpe os filtros para ver outras tarefas.'
    : 'Gere processos a partir de uma rotina para criar tarefas neste escopo.'
})

function openBulkActions() {
  if (!selectedCount.value || !canBulk.value) return
  bulkOpen.value = true
}

function clearListSelection() {
  rowSelection.value = {}
}

function taskOriginLabel(item: WorkTaskSummary): string {
  const client = item.process?.client?.name
  const process = item.process?.title
  if (client && process) return `${client} · ${process}`
  if (client) return client
  if (process) return process
  return 'Sem cliente'
}

defineShortcuts({
  arrowdown: () => {
    if (!isFila.value || isInputFocused()) return
    const list = items.value
    if (!list.length) return
    const idx = list.findIndex(i => i.id === selectedId.value)
    const next = idx === -1 ? list[0] : list[Math.min(list.length - 1, idx + 1)]
    if (next) void select(next.id)
  },
  arrowup: () => {
    if (!isFila.value || isInputFocused()) return
    const list = items.value
    if (!list.length) return
    const idx = list.findIndex(i => i.id === selectedId.value)
    const next = idx === -1 ? list[list.length - 1] : list[Math.max(0, idx - 1)]
    if (next) void select(next.id)
  },
  escape: () => {
    if (isInputFocused()) return
    if (isLista.value || isKanban.value) {
      if (selectedId.value) void clearSelection()
      return
    }
    if (detailOpen.value && isFila.value && !isMobile.value) {
      detailOpen.value = false
      void focusQueueItem(selectedId.value)
      return
    }
    if (selectedId.value) void clearSelection()
  }
})

watch(
  selectedTaskId,
  (id) => {
    if (id) {
      if (isFila.value && !isMobile.value) detailOpen.value = true
      void loadDetail(id)
    } else {
      detail.value = null
      detailError.value = null
    }
  },
  { immediate: true }
)

function isInputFocused() {
  if (!import.meta.client) return false
  const el = document.activeElement as HTMLElement | null
  if (!el) return false
  const tag = el.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable
}

watch(
  () => [
    filters.value.tab,
    filters.value.q,
    filters.value.department_id,
    filters.value.assignee_membership_id,
    filters.value.client_id,
    filters.value.scope,
    filters.value.page,
    filters.value.per_page,
    filters.value.view,
    filters.value.sort,
    filters.value.direction,
    sessionEpoch.value
  ],
  () => { void loadQueue() },
  { immediate: true }
)

watch(sessionEpoch, () => {
  items.value = []
  detail.value = null
  detailError.value = null
  detailOpen.value = false
  loadError.value = null
  clearListSelection()
  void clearTask()
  void patch({
    tab: 'open',
    page: 1,
    per_page: 10,
    view: 'fila',
    department_id: null,
    client_id: null,
    assignee_membership_id: null,
    q: '',
    scope: 'default',
    sort: null,
    direction: null
  })
})
</script>

<template>
  <div class="flex min-h-0 min-w-0 w-full flex-1 flex-col">
    <WorkQueueChrome
      :total="total"
      :view="queueView"
      :q="filters.q"
      :client-id="filters.client_id"
      :department-id="filters.department_id"
      :detail-open="detailOpen"
      :show-detail-toggle="isFila && Boolean(selectedId)"
      @update:entity-level="onEntityLevel"
      @update:view="setQueueView"
      @toggle-detail="toggleDetail"
    >
      <ShellScrollableTabs
        v-model="selectedTab"
        :items="tabs"
        size="sm"
        class="w-full min-w-0"
        aria-label="Filtrar fila por prazo"
        test-id="work-queue-tabs"
      />
      <ShellListFilterToolbar
        :q="search"
        search-placeholder="Buscar tarefa ou processo…"
        search-aria-label="Buscar na fila"
        :definitions="queueDefinitions"
        :models="queueChipModels"
        :loading="loading"
        :reset-key="sessionEpoch"
        surface="work.queue"
        :get-payload="() => workQueueFiltersToPayload(filters)"
        :can-save="() => hasActiveWorkQueueFiltersForSave(filters)"
        test-id-prefix="work-queue"
        @update:q="onQueueSearch"
        @update:models="onQueueModelsUpdate"
        @clear="onQueueClear"
        @refresh="loadQueue"
        @apply-preset="onQueuePreset"
      >
        <template #actions>
          <div
            v-if="isLista && canBulk && selectedCount > 0"
            data-testid="work-queue-bulk-actions"
          >
            <UButton
              color="neutral"
              variant="subtle"
              icon="i-lucide-list-checks"
              label="Ações"
              aria-label="Ações em massa"
              :ui="COMPACT_BUTTON_LABEL_UI"
              data-testid="work-queue-bulk-actions-menu"
              @click="openBulkActions"
            >
              <template #trailing>
                <UKbd>{{ selectedCount }}</UKbd>
              </template>
            </UButton>
          </div>
        </template>
        <template #client="{ modelValue, update, select: selectClient }">
          <FiscalClientPicker
            :model-value="modelValue"
            search-mode="select"
            placeholder="Cliente"
            class="w-full min-w-0"
            @update:model-value="(value) => update?.(value as number | null)"
            @select="(client) => selectClient?.(client)"
          />
        </template>
      </ShellListFilterToolbar>
    </WorkQueueChrome>

    <UDashboardPanel
      v-if="isLista"
      id="work-queue-list-view"
      data-testid="work-queue-list-panel"
      class="min-h-0 min-w-0 flex-1"
    >
      <template #body>
        <h1 data-testid="page-title" class="sr-only">
          Tarefas
        </h1>

        <ShellDataTable
          v-model:row-selection="rowSelection"
          :sorting="sortingState"
          test-id="work-queue-table"
          mobile-cards-test-id="work-queue-mobile-cards"
          :get-row-id="task => String(task.id)"
          :column-labels="{
            title: 'Tarefa',
            status: 'Status',
            effective_due_date: 'Prazo',
            client_name: 'Cliente / Processo',
            assignee_name: 'Responsável',
            actions: 'Ações'
          }"
          primary-column-id="title"
          status-column-id="status"
          :summary-column-ids="['effective_due_date', 'client_name', 'assignee_name']"
          :columns="taskListColumns"
          :data="items"
          :loading="loading"
          :error="loadError"
          :page="filters.page"
          :total="total"
          :items-per-page="filters.per_page"
          :selected-count="selectedCount"
          :selection-enabled="canExecute || canAdmin"
          :manual-sorting="true"
          :empty-kind="queueEmptyKind"
          :empty-title="queueEmptyTitle"
          :empty-description="queueEmptyDescription"
          per-page-aria-label="Tarefas por página"
          footer-test-id="work-queue-list-footer"
          @update:page="onListPage"
          @update:items-per-page="onListPerPage"
          @update:sorting="onListSortingUpdate"
          @retry="loadQueue"
        >
          <template #title-cell="{ row }">
            <div class="min-w-0">
              <p class="truncate font-medium text-highlighted">
                {{ row.original.title }}
              </p>
              <p v-if="row.original.is_critical" class="text-xs text-warning">
                Crítica
              </p>
            </div>
          </template>
          <template #status-cell="{ row }">
            <WorkTaskStatusSelect
              :task-id="row.original.id"
              :status="row.original.status"
              :lock-version="row.original.lock_version"
              :can-claim="!row.original.assignee?.membership_id"
              :requires-evidence="row.original.requires_evidence"
              :disabled="!canExecute"
              @updated="loadQueue"
            />
          </template>
          <template #effective_due_date-cell="{ row }">
            <span class="tabular-nums text-sm">
              {{ formatDueDate(row.original.effective_due_date || row.original.due_date) }}
            </span>
          </template>
          <template #client_name-cell="{ row }">
            <span class="block truncate text-sm text-toned">
              {{ taskOriginLabel(row.original) }}
            </span>
          </template>
          <template #assignee_name-cell="{ row }">
            <span
              class="block truncate text-sm"
              :class="row.original.assignee?.name ? '' : 'text-warning'"
            >
              {{ row.original.assignee?.name || 'Sem responsável' }}
            </span>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex justify-end">
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-arrow-up-right"
                aria-label="Abrir tarefa"
                @click.stop="select(row.original.id)"
              />
            </div>
          </template>
        </ShellDataTable>

        <WorkBulkActionsModal
          v-model:open="bulkOpen"
          :tasks="selectedTaskBulkItems"
          :can-execute-tasks="canExecute"
          :can-administer="canAdmin"
          @done="() => { clearListSelection(); void loadQueue() }"
        />
      </template>
    </UDashboardPanel>

    <UDashboardPanel
      v-else-if="isKanban"
      id="work-queue-kanban-view"
      data-testid="work-queue-kanban-panel"
      class="min-h-0 min-w-0 flex-1"
    >
      <template #body>
        <h1 data-testid="page-title" class="sr-only">
          Tarefas
        </h1>

        <WorkKanbanBoard
          class="min-h-[28rem]"
          :items="items"
          :total="total"
          :loading="loading"
          :error="loadError"
          :selected-task-id="selectedId"
          :disabled="!canExecute"
          @select="select"
          @refreshed="loadQueue"
          @retry="loadQueue"
        />
      </template>
    </UDashboardPanel>

    <div v-else class="flex min-h-0 w-full flex-1">
      <UDashboardPanel
        id="work-queue-list"
        data-testid="work-queue-panel"
        :resizable="detailPaneVisible"
        :default-size="detailPaneVisible ? 28 : undefined"
        :min-size="detailPaneVisible ? 22 : undefined"
        :max-size="detailPaneVisible ? 36 : undefined"
        :class="detailPaneVisible ? 'min-h-0 min-w-0' : 'min-h-0 min-w-0 flex-1'"
      >
        <template #body>
          <h1 data-testid="page-title" class="sr-only">
            Tarefas
          </h1>

          <div v-if="loadError && !items.length" class="p-4">
            <UAlert color="error" :title="loadError">
              <template #actions>
                <UButton
                  size="xs"
                  variant="soft"
                  label="Tentar de novo"
                  @click="loadQueue"
                />
              </template>
            </UAlert>
          </div>

          <div v-else-if="loading && !items.length" class="space-y-3 p-4">
            <USkeleton v-for="i in 6" :key="i" class="h-16 w-full" />
          </div>

          <div
            v-else-if="!items.length"
            data-testid="work-queue-empty"
          >
            <ShellListEmpty
              :kind="queueEmptyKind"
              :title="queueEmptyTitle"
              :description="queueEmptyDescription"
              test-id="work-queue-empty-state"
            >
              <template
                v-if="queueEmptyKind === 'filtered'"
                #actions
              >
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-filter-x"
                  label="Limpar filtros"
                  data-testid="work-queue-empty-clear"
                  @click="onQueueClear"
                />
              </template>
            </ShellListEmpty>
          </div>

          <div
            v-else
            class="flex min-h-0 flex-1 flex-col"
          >
            <UAlert
              v-if="loadError"
              color="warning"
              variant="subtle"
              title="Não foi possível atualizar a fila"
              :description="loadError"
              class="m-3 mb-0 shrink-0"
              data-testid="work-queue-stale-error"
            >
              <template #actions>
                <UButton
                  size="xs"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-refresh-cw"
                  label="Tentar de novo"
                  data-testid="work-queue-stale-retry"
                  @click="loadQueue"
                />
              </template>
            </UAlert>

            <div
              v-else-if="loading"
              class="flex shrink-0 items-center gap-2 px-4 py-2 text-sm text-muted"
              role="status"
              aria-live="polite"
              data-testid="work-queue-refreshing"
            >
              <UIcon
                name="i-lucide-loader-circle"
                class="size-4 animate-spin"
                aria-hidden="true"
              />
              <span>Atualizando tarefas…</span>
            </div>

            <div
              role="listbox"
              aria-label="Fila de tarefas"
              class="min-h-0 flex-1 overflow-y-auto divide-y divide-default"
            >
              <WorkQueueListItem
                v-for="item in items"
                :key="item.id"
                :ref="(el: unknown) => { itemRefs[item.id] = el as QueueItemRef | null }"
                :item="item"
                :selected="selectedId === item.id"
                @select="select"
              />
            </div>
          </div>
        </template>
      </UDashboardPanel>

      <WorkTaskDetailPanel
        v-if="detailPaneVisible"
        class="hidden min-w-0 flex-1 overflow-hidden lg:flex"
        :detail="detail"
        :loading="detailLoading"
        :error="detailError"
        :has-selection="Boolean(selectedId)"
        @close="clearSelection"
        @refreshed="loadQueue"
        @retry="retryDetail"
      />
    </div>
  </div>

  <USlideover
    v-if="isLista || isKanban || isMobile"
    v-model:open="detailSlideoverOpen"
    title="Tarefa"
    :close="false"
    :class="isLista || isKanban ? undefined : 'lg:hidden'"
  >
    <template #content>
      <WorkTaskDetailPanel
        :detail="detail"
        :loading="detailLoading"
        :error="detailError"
        :has-selection="Boolean(selectedId)"
        @close="clearSelection"
        @refreshed="loadQueue"
        @retry="retryDetail"
      />
    </template>
  </USlideover>
</template>

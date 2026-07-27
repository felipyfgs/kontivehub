<script setup lang="ts">
/**
 * Processos — Cliente | Processo | Tarefa.
 * - Processo (default): grupos por rotina (`group_by=routine`); empresas na expansão.
 * - Cliente: árvore multi-expandida cliente → processos → tasks (prefetch + cache).
 * Cascas: ShellPagePanel + ShellListFilterToolbar + ShellDataTable.
 */
import { h } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import UCheckbox from '@nuxt/ui/components/Checkbox.vue'
import type {
  WorkProcess,
  WorkProcessGroup,
  WorkProcessTask,
  WorkDepartment,
  WorkEntityLevel,
  WorkProcessGroupSort
} from '~/types/work'
import { canAdministerWork, canCreateWorkProcesses, canExecuteWorkTasks } from '~/utils/permissions'
import { apiErrorMessage } from '~/utils/api-error'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import {
  createFilterModel,
  findDefinition
} from '~/utils/data-table-filters'
import {
  hasActiveWorkProcessesFiltersForSave,
  workProcessesFiltersToPayload,
  workProcessesPayloadToFilters
} from '~/utils/saved-list-filters'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'
import ShellDataTable from '~/components/shell/DataTable.vue'
import WorkBulkActionsModal from '~/components/work/WorkBulkActionsModal.vue'
import type { WorkBulkItem } from '~/components/work/WorkBulkActionsModal.vue'
import WorkEntityLevelToggle from '~/components/work/WorkEntityLevelToggle.vue'
import WorkTaskStatusSelect from '~/components/work/WorkTaskStatusSelect.vue'
import { sortHeader } from '~/utils/table-sort'
import {
  formatDueDate,
  processStatusColor,
  processStatusLabel
} from '~/utils/work-labels'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'
import {
  WORK_TABLE_COL
} from '~/utils/work-table-columns'
import {
  cascadeProcessTaskSelection,
  cascadeSelectAllProcessesOnPage,
  selectedMaterialisedBulkItems,
  shouldReloadWorkProcessesAfterSessionReset,
  sortedProcessTasks,
  workProcessListRequestKey,
  workProcessSelectionContextKey
} from '~/utils/work-process-selection'
import {
  buildGroupChildrenListParams,
  buildProcessGroupsListParams,
  hasActiveWorkProcessGroupingFilters,
  useWorkProcessGrouping,
  WORK_PROCESS_GROUP_SORT_WHITELIST
} from '~/composables/useWorkProcessGrouping'
import {
  emptyGroupChildrenEntry,
  expandAllGroupKeys,
  GROUP_CHILDREN_PREFETCH_CONCURRENCY,
  groupChildrenSelectionState,
  isProcessIdExpanded,
  mapWithConcurrency,
  mergeMaterialisedProcesses,
  toggleGroupKeyExpanded,
  toggleProcessIdExpanded,
  type GroupChildrenCacheEntry
} from '~/composables/useWorkProcessGroupTree'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'
import {
  workProcessBulkCapabilities,
  workProcessSelectionAriaLabel
} from '~/utils/work-bulk-actions'

const api = useApi()
const route = useRoute()
const toast = useToast()
const { me, sessionEpoch } = useDashboard()
const {
  filters,
  entityLevel,
  groupBy,
  replaceFilters,
  navigateEntityLevel
} = useWorkProcessGrouping()

const isClientMode = computed(() => filters.value.group === 'client')
const hasFilters = computed(() => hasActiveWorkProcessGroupingFilters(filters.value))

const canExecute = computed(() => canExecuteWorkTasks(me.value))
const canAdmin = computed(() => canAdministerWork(me.value))
const canUpdateProcesses = computed(() => canCreateWorkProcesses(me.value))
const bulkCapabilities = computed(() => workProcessBulkCapabilities({
  canExecuteTasks: canExecute.value,
  canAdminister: canAdmin.value,
  canUpdateProcesses: canUpdateProcesses.value
}))
const canBulkTasks = computed(() => bulkCapabilities.value.tasks)
const canBulk = computed(() => bulkCapabilities.value.any)

const departments = ref<WorkDepartment[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const total = ref(0)

const rowSelection = ref<Record<string, boolean>>({})
const selectedTaskIds = ref<Record<string, boolean>>({})
const materialisedProcessCache = ref<Record<string, WorkProcess>>({})

/** Ambos os modos — árvore multi-expandida (Cliente ou Rotina). */
const groups = ref<WorkProcessGroup[]>([])
const groupExpanded = ref<Record<string, boolean>>({})
const groupChildrenCache = ref<Record<string, GroupChildrenCacheEntry>>({})
const expandedChildProcessIds = ref<Record<string, boolean>>({})
const childPerPage = 50
const prefetchEpoch = ref(0)
const groupsLoadEpoch = ref(0)

const bulkOpen = ref(false)

const selectionContextKey = computed(() =>
  workProcessSelectionContextKey(filters.value)
)

const emptyKind = computed<'empty' | 'filtered' | 'error'>(() => {
  if (loadError.value) return 'error'
  if (hasFilters.value) return 'filtered'
  return 'empty'
})

const emptyTitle = computed(() => {
  if (emptyKind.value === 'error') {
    return isClientMode.value ? 'Falha ao carregar grupos de clientes' : 'Falha ao carregar grupos de processos'
  }
  if (emptyKind.value === 'filtered') {
    return isClientMode.value ? 'Nenhum cliente encontrado' : 'Nenhuma rotina encontrada'
  }
  return isClientMode.value ? 'Nenhum cliente com processos' : 'Nenhuma rotina com processos'
})

const emptyDescription = computed(() => {
  if (emptyKind.value === 'error') {
    return loadError.value || 'Tente novamente.'
  }
  if (emptyKind.value === 'filtered') {
    return 'Ajuste ou limpe os filtros para ver outros grupos.'
  }
  return isClientMode.value
    ? 'Quando houver processos no escritório, eles aparecerão agrupados por cliente.'
    : 'Quando houver processos no escritório, eles aparecerão agrupados por rotina (uma linha por processo).'
})

function positiveId(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  const parsed = Number(raw)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

const processFilterDefinitions = computed<DataTableFilterDefinition[]>(() => [
  {
    key: 'competence',
    kind: 'month',
    label: 'Competência',
    emptyValue: ''
  },
  {
    key: 'status',
    kind: 'option',
    label: 'Status',
    emptyValue: 'all',
    items: [
      { label: 'A fazer', value: 'A_FAZER' },
      { label: 'Em progresso', value: 'EM_PROGRESSO' },
      { label: 'Impedido', value: 'IMPEDIDO' },
      { label: 'Concluído', value: 'CONCLUIDO' }
    ]
  },
  {
    key: 'client_id',
    kind: 'client',
    label: 'Cliente',
    emptyValue: null
  },
  {
    key: 'department_id',
    kind: 'option',
    label: 'Departamento',
    emptyValue: '',
    items: departments.value.map(department => ({
      label: department.name,
      value: String(department.id)
    }))
  }
])

function modelsFromState(): DataTableFilterModel[] {
  const models: DataTableFilterModel[] = []
  const definitions = processFilterDefinitions.value
  const f = filters.value
  const competenceDef = findDefinition(definitions, 'competence')
  const statusDef = findDefinition(definitions, 'status')
  const clientDef = findDefinition(definitions, 'client_id')
  const departmentDef = findDefinition(definitions, 'department_id')
  if (competenceDef && f.competence) {
    const model = createFilterModel(competenceDef, f.competence)
    if (model) models.push(model)
  }
  if (statusDef && f.status && f.status !== 'all') {
    const model = createFilterModel(statusDef, f.status)
    if (model) models.push(model)
  }
  if (clientDef && f.client_id) {
    const model = createFilterModel(clientDef, f.client_id)
    if (model) models.push(model)
  }
  if (departmentDef && f.department_id) {
    const model = createFilterModel(departmentDef, String(f.department_id))
    if (model) models.push(model)
  }
  return models
}

const chipModels = computed(() => modelsFromState())

function onStructuredFilters(models: DataTableFilterModel[]) {
  const competenceModel = models.find(m => m.key === 'competence')
  const statusModel = models.find(m => m.key === 'status')
  const clientModel = models.find(m => m.key === 'client_id')
  const departmentModel = models.find(m => m.key === 'department_id')
  void replaceFilters({
    competence: competenceModel ? String(competenceModel.value) : '',
    status: statusModel ? String(statusModel.value) : 'all',
    client_id: clientModel ? positiveId(clientModel.value) : null,
    department_id: departmentModel ? positiveId(departmentModel.value) : null
  })
}

function onClearStructuredFilters() {
  void replaceFilters({
    competence: '',
    status: 'all',
    client_id: null,
    department_id: null,
    q: ''
  })
}

function onProcessSearch(value: string) {
  void replaceFilters({ q: value })
}

function onProcessPreset(payload: SavedListFilterPayload) {
  const next = workProcessesPayloadToFilters(payload)
  void replaceFilters({
    q: next.q,
    competence: next.competence,
    status: next.status,
    client_id: next.client_id,
    department_id: next.department_id,
    group: next.group === 'client' ? 'client' : 'process'
  })
}

function onEntityLevel(level: WorkEntityLevel) {
  void navigateEntityLevel(level)
}

function clearSelection() {
  rowSelection.value = {}
  selectedTaskIds.value = {}
}

function resetClientExpansion() {
  clearSelection()
  materialisedProcessCache.value = {}
  groupExpanded.value = {}
  groupChildrenCache.value = {}
  expandedChildProcessIds.value = {}
  prefetchEpoch.value += 1
}

function groupCacheEntry(groupKey: string): GroupChildrenCacheEntry {
  return groupChildrenCache.value[groupKey] ?? emptyGroupChildrenEntry()
}

function patchGroupCache(groupKey: string, patch: Partial<GroupChildrenCacheEntry>) {
  const prev = groupCacheEntry(groupKey)
  groupChildrenCache.value = {
    ...groupChildrenCache.value,
    [groupKey]: { ...prev, ...patch }
  }
}

async function loadGroupChildren(
  group: WorkProcessGroup,
  page = 1,
  opts?: { force?: boolean, silentToast?: boolean }
) {
  const key = group.key
  const existing = groupCacheEntry(key)
  if (!opts?.force) {
    if (existing.status === 'loading') return
    if (existing.status === 'ready' && existing.page === page) return
  }

  const session = sessionEpoch.value
  const localPrefetch = prefetchEpoch.value
  const hasLastGood = existing.processes.length > 0
  patchGroupCache(key, {
    status: 'loading',
    error: null,
    ...(hasLastGood ? {} : { page })
  })

  try {
    const res = await api.work.processes.list(
      buildGroupChildrenListParams(
        group,
        groupBy.value,
        filters.value,
        { page, per_page: childPerPage }
      )
    )
    if (session !== sessionEpoch.value || localPrefetch !== prefetchEpoch.value) return
    materialisedProcessCache.value = mergeMaterialisedProcesses(
      materialisedProcessCache.value,
      res.data
    )
    patchGroupCache(key, {
      status: 'ready',
      processes: res.data,
      error: null,
      page,
      total: res.meta.total
    })
  } catch (e) {
    if (session !== sessionEpoch.value || localPrefetch !== prefetchEpoch.value) return
    const message = apiErrorMessage(e, 'Falha ao carregar processos do grupo.')
    patchGroupCache(key, {
      status: 'error',
      error: message
    })
    if (!opts?.silentToast) {
      toast.add({ title: message, color: 'error' })
    }
  }
}

async function prefetchGroupChildren(pageGroups: WorkProcessGroup[]) {
  const session = sessionEpoch.value
  const localPrefetch = prefetchEpoch.value
  await mapWithConcurrency(
    pageGroups,
    GROUP_CHILDREN_PREFETCH_CONCURRENCY,
    async (group) => {
      if (session !== sessionEpoch.value || localPrefetch !== prefetchEpoch.value) return
      const existing = groupCacheEntry(group.key)
      if (existing.status === 'ready' || existing.status === 'loading') return
      await loadGroupChildren(group, 1, { silentToast: true })
    }
  )
}

async function loadGroups() {
  const epoch = sessionEpoch.value
  const requestEpoch = ++groupsLoadEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.work.processGroups.list(
      buildProcessGroupsListParams(filters.value)
    )
    if (epoch !== sessionEpoch.value || requestEpoch !== groupsLoadEpoch.value) return
    groups.value = res.data
    total.value = res.meta.total
    expandedChildProcessIds.value = {}
    groupChildrenCache.value = {}
    prefetchEpoch.value += 1
    groupExpanded.value = expandAllGroupKeys(groups.value.map(group => group.key))
    void prefetchGroupChildren(groups.value)
  } catch (e) {
    if (epoch !== sessionEpoch.value || requestEpoch !== groupsLoadEpoch.value) return
    loadError.value = apiErrorMessage(e, 'Falha ao listar grupos de processos.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value && requestEpoch === groupsLoadEpoch.value) {
      loading.value = false
    }
  }
}

async function load() {
  await loadGroups()
}

async function loadDepartments() {
  try {
    const response = await api.work.departments.list({ per_page: 100, is_active: true })
    departments.value = response.data
  } catch {
    departments.value = []
  }
}

function toggleGroupExpanded(group: WorkProcessGroup) {
  const wasOpen = groupExpanded.value[group.key] === true
  groupExpanded.value = toggleGroupKeyExpanded(groupExpanded.value, group.key)
  if (wasOpen) {
    // Colapso não invalida cache; só esconde linhas.
    return
  }
  void loadGroupChildren(group, groupCacheEntry(group.key).page || 1)
}

function toggleChildProcessTasks(processId: number) {
  expandedChildProcessIds.value = toggleProcessIdExpanded(
    expandedChildProcessIds.value,
    processId
  )
}

function retryGroupChildren(group: WorkProcessGroup) {
  void loadGroupChildren(group, groupCacheEntry(group.key).page || 1, { force: true })
}

function setPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (filters.value.per_page === target) return
  void replaceFilters({ per_page: target })
}

const sortingState = computed(() => {
  const sort = filters.value.sort
  if (!sort) return []
  return [{ id: sort, desc: filters.value.direction === 'desc' }]
})

function onSortingUpdate(next: Array<{ id: string, desc: boolean }>) {
  const first = next[0]
  if (!first) {
    void replaceFilters({ sort: null, direction: null })
    return
  }
  if (!(WORK_PROCESS_GROUP_SORT_WHITELIST as readonly string[]).includes(first.id)) return
  void replaceFilters({
    sort: first.id as WorkProcessGroupSort,
    direction: first.desc ? 'desc' : 'asc'
  })
}

const selectionProcesses = computed(() =>
  Object.values(materialisedProcessCache.value)
)

function setProcessSelected(process: WorkProcess, selected: boolean) {
  const next = cascadeProcessTaskSelection({
    processes: selectionProcesses.value,
    processSelection: rowSelection.value,
    taskSelection: selectedTaskIds.value,
    changedProcessIds: [process.id],
    selected
  })
  rowSelection.value = next.processSelection
  selectedTaskIds.value = canBulkTasks.value ? next.taskSelection : {}
}

function toggleTaskSelected(taskId: number, value: boolean | 'indeterminate') {
  const key = String(taskId)
  const nextTasks = { ...selectedTaskIds.value }
  if (value) {
    nextTasks[key] = true
  } else {
    Reflect.deleteProperty(nextTasks, key)
  }
  selectedTaskIds.value = nextTasks

  const parent = selectionProcesses.value.find(process =>
    sortedProcessTasks(process).some(task => task.id === taskId)
  )
  if (!parent) return

  const parentKey = String(parent.id)
  const allSelected = sortedProcessTasks(parent).every(task => nextTasks[String(task.id)])
  const nextRows = { ...rowSelection.value }
  if (allSelected) {
    nextRows[parentKey] = true
  } else {
    Reflect.deleteProperty(nextRows, parentKey)
  }
  rowSelection.value = nextRows
}

const selectedBulkItems = computed(() => selectedMaterialisedBulkItems({
  processes: selectionProcesses.value,
  processSelection: rowSelection.value,
  taskSelection: selectedTaskIds.value
}))

const selectedProcessBulkItems = computed<WorkBulkItem[]>(
  () => selectedBulkItems.value.processes
)

const selectedTaskBulkItems = computed<WorkBulkItem[]>(
  () => selectedBulkItems.value.tasks
)

const selectedCount = computed(
  () => selectedProcessBulkItems.value.length + selectedTaskBulkItems.value.length
)

function openBulkActions() {
  if (!selectedCount.value || !canBulk.value) return
  bulkOpen.value = true
}

function openProcess(process: WorkProcess) {
  const query = { ...route.query }
  void navigateTo({
    path: `/work/processes/${process.id}`,
    query: {
      ...query,
      from: route.fullPath
    }
  })
}

function processTasks(process: WorkProcess): WorkProcessTask[] {
  return sortedProcessTasks(process)
}

const groupColumns = computed<TableColumn<WorkProcessGroup>[]>(() => {
  const columns: TableColumn<WorkProcessGroup>[] = [
    {
      id: 'expand',
      header: '',
      enableSorting: false,
      meta: {
        class: canBulk.value ? WORK_TABLE_COL.expandWithSelect : WORK_TABLE_COL.expand
      }
    },
    {
      accessorKey: 'label',
      header: ({ column }) => sortHeader(isClientMode.value ? 'Cliente' : 'Processo', column),
      meta: { class: WORK_TABLE_COL.primary }
    }
  ]

  if (!isClientMode.value) {
    columns.push({
      accessorKey: 'client_count',
      header: ({ column }) => sortHeader('Clientes', column),
      enableSorting: false,
      meta: {
        class: {
          th: `${WORK_TABLE_COL.count.th} hidden md:table-cell`,
          td: `${WORK_TABLE_COL.count.td} hidden md:table-cell`
        }
      }
    })
  }

  columns.push(
    {
      accessorKey: 'process_count',
      header: ({ column }) => sortHeader(isClientMode.value ? 'Processos' : 'Instâncias', column),
      meta: {
        class: {
          th: `${WORK_TABLE_COL.count.th} hidden md:table-cell`,
          td: `${WORK_TABLE_COL.count.td} hidden md:table-cell`
        }
      }
    },
    {
      accessorKey: 'open_task_count',
      header: ({ column }) => sortHeader('Tarefas abertas', column),
      meta: {
        class: {
          th: `${WORK_TABLE_COL.countWide.th} hidden md:table-cell`,
          td: `${WORK_TABLE_COL.countWide.td} hidden md:table-cell`
        }
      }
    },
    {
      accessorKey: 'progress_percent',
      header: ({ column }) => sortHeader('Progresso', column),
      meta: {
        class: {
          th: `${WORK_TABLE_COL.progress.th} hidden lg:table-cell`,
          td: `${WORK_TABLE_COL.progress.td} hidden lg:table-cell`
        }
      }
    },
    {
      accessorKey: 'next_due_date',
      header: ({ column }) => sortHeader('Próximo prazo', column),
      meta: {
        class: {
          th: `${WORK_TABLE_COL.due.th} hidden xl:table-cell`,
          td: `${WORK_TABLE_COL.due.td} hidden xl:table-cell`
        }
      }
    }
  )

  return columns
})

function clearSelectionForProcesses(processes: readonly WorkProcess[]) {
  if (!processes.length) return
  const nextRows = { ...rowSelection.value }
  const nextTasks = { ...selectedTaskIds.value }
  for (const process of processes) {
    Reflect.deleteProperty(nextRows, String(process.id))
    for (const task of sortedProcessTasks(process)) {
      Reflect.deleteProperty(nextTasks, String(task.id))
    }
  }
  rowSelection.value = nextRows
  selectedTaskIds.value = nextTasks
}

function groupSelectState(groupKey: string): boolean | 'indeterminate' {
  const state = groupChildrenSelectionState(
    groupCacheEntry(groupKey).processes,
    rowSelection.value
  )
  if (state === 'some') return 'indeterminate'
  return state === 'all'
}

async function setGroupChildrenSelected(group: WorkProcessGroup, selected: boolean) {
  let entry = groupCacheEntry(group.key)
  if (selected && entry.status !== 'ready') {
    await loadGroupChildren(group, entry.page || 1, { force: entry.status === 'error' })
    entry = groupCacheEntry(group.key)
  }
  if (!entry.processes.length) return
  if (selected) {
    const next = cascadeSelectAllProcessesOnPage({
      processes: entry.processes,
      selected: true
    })
    rowSelection.value = { ...rowSelection.value, ...next.processSelection }
    if (canBulkTasks.value) {
      selectedTaskIds.value = { ...selectedTaskIds.value, ...next.taskSelection }
    }
    return
  }
  clearSelectionForProcesses(entry.processes)
}

function childRowCheckbox(child: WorkProcess) {
  const processId = String(child.id)
  const processLabel = isClientMode.value
    ? child.title
    : child.client?.name || child.title
  return h(UCheckbox, {
    'modelValue': rowSelection.value[processId] === true,
    'onUpdate:modelValue': (value: unknown) => {
      setProcessSelected(child, !!value)
    },
    'ariaLabel': workProcessSelectionAriaLabel(processLabel, canBulkTasks.value)
  })
}

function onBulkDone() {
  resetClientExpansion()
  void load()
}

watch(
  [
    selectionContextKey,
    () => filters.value.page,
    () => filters.value.per_page,
    () => filters.value.sort,
    () => filters.value.direction
  ],
  ([nextContext], [previousContext]) => {
    if (nextContext !== previousContext) {
      resetClientExpansion()
    }
    void load()
  }
)

watch(sessionEpoch, async () => {
  const epoch = sessionEpoch.value
  const beforeRequestKey = workProcessListRequestKey(filters.value)
  groups.value = []
  total.value = 0
  resetClientExpansion()
  await replaceFilters({
    q: '',
    competence: '',
    status: 'all',
    client_id: null,
    department_id: null,
    page: 1,
    sort: null,
    direction: null,
    group: 'process'
  }, { resetPage: false })
  if (epoch !== sessionEpoch.value) return
  const afterRequestKey = workProcessListRequestKey(filters.value)
  if (shouldReloadWorkProcessesAfterSessionReset(beforeRequestKey, afterRequestKey)) {
    void load()
  }
})

onMounted(() => {
  void loadDepartments()
  void load()
})
</script>

<template>
  <ShellPagePanel
    id="work-processes"
    data-testid="work-processes-panel"
  >
    <template #header>
      <ShellPageNavbar title="Processos">
        <template #trailing>
          <WorkEntityLevelToggle
            :model-value="entityLevel"
            :q="filters.q"
            :client-id="filters.client_id"
            :department-id="filters.department_id"
            @update:model-value="onEntityLevel"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar data-testid="work-processes-toolbar">
        <ShellListFilterToolbar
          :q="filters.q"
          search-placeholder="Buscar…"
          :search-aria-label="isClientMode ? 'Buscar grupos por cliente' : 'Buscar grupos por processo'"
          :definitions="processFilterDefinitions"
          :models="chipModels"
          :loading="loading"
          :reset-key="sessionEpoch"
          surface="work.processes"
          :get-payload="() => workProcessesFiltersToPayload({
            q: filters.q,
            competence: filters.competence,
            status: filters.status,
            client_id: filters.client_id,
            department_id: filters.department_id,
            group: filters.group === 'client' ? 'client' : null
          })"
          :can-save="() => hasActiveWorkProcessesFiltersForSave({
            q: filters.q,
            competence: filters.competence,
            status: filters.status,
            client_id: filters.client_id,
            department_id: filters.department_id,
            group: filters.group === 'client' ? 'client' : null
          })"
          test-id-prefix="work-processes"
          @update:q="onProcessSearch"
          @update:models="onStructuredFilters"
          @clear="onClearStructuredFilters"
          @refresh="load"
          @apply-preset="onProcessPreset"
        >
          <template #actions>
            <div
              v-if="canBulk && selectedCount > 0"
              data-testid="work-processes-bulk-actions"
            >
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-list-checks"
                label="Ações"
                aria-label="Ações em massa"
                :ui="COMPACT_BUTTON_LABEL_UI"
                data-testid="work-processes-bulk-actions-menu"
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
      </UDashboardToolbar>
    </template>

    <template #body>
      <h1 data-testid="page-title" class="sr-only">
        Processos
      </h1>

      <!-- Grupos: Cliente (client) ou Processo/rotina (routine) -->
      <ShellDataTable
        v-model:expanded="groupExpanded"
        :sorting="sortingState"
        :get-row-id="(row) => row.key"
        test-id="work-processes-table"
        ui-preset="dashboard"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4 py-3 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'px-4 py-3.5 border-b border-default',
          separator: 'h-0'
        }"
        :mobile-cards="false"
        primary-column-id="label"
        :summary-column-ids="isClientMode
          ? ['process_count', 'open_task_count', 'progress_percent', 'next_due_date']
          : ['client_count', 'process_count', 'open_task_count', 'progress_percent', 'next_due_date']"
        :columns="groupColumns"
        :data="groups"
        :loading="loading"
        :error="loadError"
        :empty-kind="emptyKind"
        :empty-title="emptyTitle"
        :empty-description="emptyDescription"
        :page="filters.page"
        :total="total"
        :items-per-page="filters.per_page"
        :selected-count="selectedCount"
        :selection-enabled="false"
        :manual-sorting="true"
        per-page-aria-label="Grupos por página"
        footer-test-id="work-processes-footer"
        @update:page="(page) => replaceFilters({ page }, { resetPage: false })"
        @update:items-per-page="setPerPage"
        @update:sorting="onSortingUpdate"
        @retry="load"
      >
        <template #expand-cell="{ row }">
          <div class="flex items-center gap-0.5">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-chevron-down"
              square
              size="xs"
              :aria-label="groupExpanded[row.original.key]
                ? (isClientMode ? 'Recolher processos' : 'Recolher empresas')
                : (isClientMode ? 'Expandir processos' : 'Expandir empresas')"
              :aria-expanded="!!groupExpanded[row.original.key]"
              :ui="{
                leadingIcon: [
                  'transition-transform duration-200',
                  groupExpanded[row.original.key] ? 'rotate-180' : ''
                ].join(' ')
              }"
              data-testid="work-process-group-expand"
              @click.stop="toggleGroupExpanded(row.original)"
            />
            <UCheckbox
              v-if="canBulk"
              class="shrink-0"
              :model-value="groupSelectState(row.original.key)"
              :aria-label="isClientMode
                ? 'Selecionar todos os processos deste cliente'
                : 'Selecionar todas as empresas deste processo'"
              data-testid="work-process-group-select"
              @click.stop
              @update:model-value="(value) => setGroupChildrenSelected(row.original, !!value)"
            />
          </div>
        </template>
        <template #label-cell="{ row }">
          <div class="min-w-0 w-full py-0.5">
            <button
              type="button"
              class="block min-w-0 w-full text-left"
              data-testid="work-process-group-open"
              @click.stop="toggleGroupExpanded(row.original)"
            >
              <span class="block truncate text-sm font-medium text-highlighted">
                <template v-if="isClientMode && row.original.client?.cnpj_masked">
                  <span class="text-muted">{{ row.original.client.cnpj_masked }} · </span>
                </template>
                {{ row.original.label }}
              </span>
              <span
                v-if="!isClientMode"
                class="block truncate text-xs text-muted"
              >
                {{ row.original.client_count }}
                {{ row.original.client_count === 1 ? 'cliente' : 'clientes' }}
              </span>
            </button>

            <dl
              class="mt-2 grid min-w-0 grid-cols-2 gap-x-3 gap-y-1.5 border-t border-default pt-2 md:hidden"
              data-testid="work-process-group-mobile-summary"
            >
              <div class="min-w-0">
                <dt class="text-[11px] text-muted">
                  {{ isClientMode ? 'Processos' : 'Instâncias' }}
                </dt>
                <dd class="truncate text-xs font-medium text-highlighted tabular-nums">
                  {{ row.original.process_count }}
                </dd>
              </div>
              <div class="min-w-0">
                <dt class="text-[11px] text-muted">
                  Tarefas abertas
                </dt>
                <dd class="truncate text-xs font-medium text-highlighted tabular-nums">
                  {{ row.original.open_task_count }}
                </dd>
              </div>
              <div class="min-w-0">
                <dt class="text-[11px] text-muted">
                  Progresso
                </dt>
                <dd class="truncate text-xs font-medium text-highlighted tabular-nums">
                  {{ row.original.progress_percent ?? 0 }}%
                </dd>
              </div>
              <div class="min-w-0">
                <dt class="text-[11px] text-muted">
                  Próximo prazo
                </dt>
                <dd class="truncate text-xs font-medium text-highlighted tabular-nums">
                  {{ formatDueDate(row.original.next_due_date) }}
                </dd>
              </div>
            </dl>
          </div>
        </template>
        <template #client_count-cell="{ row }">
          <span class="text-sm tabular-nums">{{ row.original.client_count }}</span>
        </template>
        <template #process_count-cell="{ row }">
          <span class="text-sm tabular-nums">{{ row.original.process_count }}</span>
        </template>
        <template #open_task_count-cell="{ row }">
          <span class="text-sm tabular-nums">{{ row.original.open_task_count }}</span>
        </template>
        <template #progress_percent-cell="{ row }">
          <div class="flex min-w-0 items-center gap-2">
            <UProgress
              class="min-w-16 flex-1"
              size="sm"
              :model-value="row.original.progress_percent ?? 0"
              :aria-label="`Progresso ${row.original.progress_percent ?? 0}%`"
            />
            <span class="shrink-0 text-xs tabular-nums text-muted">
              {{ row.original.progress_percent ?? 0 }}%
            </span>
          </div>
        </template>
        <template #next_due_date-cell="{ row }">
          <span class="inline-flex items-center gap-1.5 text-sm tabular-nums whitespace-nowrap">
            <UIcon name="i-lucide-calendar-clock" class="size-4 shrink-0 text-muted" />
            {{ formatDueDate(row.original.next_due_date) }}
          </span>
        </template>
        <template #expanded="{ row }">
          <div
            class="min-w-0 bg-default px-2 py-2 sm:px-3"
            :data-testid="`work-process-group-children-${row.original.key}`"
          >
            <div
              v-if="groupCacheEntry(row.original.key).status === 'loading'
                && !groupCacheEntry(row.original.key).processes.length"
              class="space-y-1.5 py-2"
              data-testid="work-process-group-children-loading"
            >
              <USkeleton class="h-10 w-full rounded-md" />
              <USkeleton class="h-10 w-full rounded-md" />
            </div>
            <div
              v-else-if="groupCacheEntry(row.original.key).status === 'error'
                && !groupCacheEntry(row.original.key).processes.length"
              class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default px-3 py-2"
              data-testid="work-process-group-children-error"
            >
              <p class="text-sm text-highlighted">
                {{ groupCacheEntry(row.original.key).error }}
              </p>
              <UButton
                size="xs"
                color="neutral"
                variant="outline"
                label="Tentar novamente"
                data-testid="work-process-group-children-retry"
                @click="retryGroupChildren(row.original)"
              />
            </div>
            <div
              v-else-if="groupCacheEntry(row.original.key).processes.length"
              class="min-w-0 overflow-hidden rounded-md border border-default"
              data-testid="work-process-group-tree"
            >
              <div
                v-if="groupCacheEntry(row.original.key).status === 'loading'"
                class="flex items-center gap-2 border-b border-default bg-elevated/30 px-3 py-2 text-xs text-muted"
                role="status"
                aria-live="polite"
                data-testid="work-process-group-children-refreshing"
              >
                <UIcon
                  name="i-lucide-loader-circle"
                  class="size-3.5 shrink-0 animate-spin"
                  aria-hidden="true"
                />
                Atualizando processos do grupo…
              </div>
              <div
                v-else-if="groupCacheEntry(row.original.key).status === 'error'"
                class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-error/5 px-3 py-2"
                role="alert"
                data-testid="work-process-group-children-stale-error"
              >
                <p class="text-xs text-error">
                  Dados anteriores mantidos. {{ groupCacheEntry(row.original.key).error }}
                </p>
                <UButton
                  size="xs"
                  color="error"
                  variant="soft"
                  label="Tentar novamente"
                  data-testid="work-process-group-children-stale-retry"
                  @click="retryGroupChildren(row.original)"
                />
              </div>
              <!-- Coleção interna já materializada: tabela sem paginação própria;
                   no mobile, as mesmas linhas viram blocos rotulados sem scroll horizontal. -->
              <table
                class="w-full table-fixed border-collapse"
                :aria-label="`Processos do grupo ${row.original.label}`"
                data-testid="work-process-group-tree-table"
              >
                <caption class="sr-only">
                  Processos e empresas materializados neste grupo
                </caption>
                <thead class="hidden bg-elevated/50 text-left text-xs text-muted md:table-header-group">
                  <tr>
                    <th scope="col" class="w-[34%] px-3 py-2 font-medium">
                      {{ isClientMode ? 'Processo' : 'Empresa' }}
                    </th>
                    <th scope="col" class="w-32 px-3 py-2 font-medium">
                      Status
                    </th>
                    <th scope="col" class="w-28 px-3 py-2 font-medium">
                      Prazo
                    </th>
                    <th scope="col" class="w-28 px-3 py-2 font-medium">
                      Meta
                    </th>
                    <th scope="col" class="w-32 px-3 py-2 font-medium">
                      Departamento
                    </th>
                    <th scope="col" class="w-32 px-3 py-2 font-medium">
                      Responsável
                    </th>
                  </tr>
                </thead>
                <tbody class="block divide-y divide-default md:table-row-group md:divide-y-0">
                  <template
                    v-for="process in groupCacheEntry(row.original.key).processes"
                    :key="process.id"
                  >
                    <tr
                      class="grid min-w-0 grid-cols-2 gap-x-3 gap-y-2 px-3 py-3 md:table-row md:px-0 md:py-0"
                      data-testid="work-process-child-row"
                    >
                      <th
                        scope="row"
                        class="col-span-2 min-w-0 text-left font-normal md:table-cell md:border-t md:border-default md:px-3 md:py-2"
                      >
                        <div class="flex min-w-0 items-center gap-1.5">
                          <component
                            :is="childRowCheckbox(process)"
                            v-if="canBulk"
                            data-testid="work-process-child-select"
                          />
                          <UButton
                            color="neutral"
                            variant="ghost"
                            icon="i-lucide-chevron-right"
                            square
                            size="xs"
                            :aria-label="isProcessIdExpanded(expandedChildProcessIds, process.id) ? 'Recolher tarefas' : 'Expandir tarefas'"
                            :aria-expanded="isProcessIdExpanded(expandedChildProcessIds, process.id)"
                            :ui="{
                              leadingIcon: [
                                'transition-transform duration-150',
                                isProcessIdExpanded(expandedChildProcessIds, process.id) ? 'rotate-90' : ''
                              ].join(' ')
                            }"
                            data-testid="work-process-child-expand"
                            @click.stop="toggleChildProcessTasks(process.id)"
                          />
                          <UIcon
                            name="i-lucide-workflow"
                            class="size-4 shrink-0 text-muted"
                            aria-hidden="true"
                          />
                          <button
                            type="button"
                            class="min-w-0 truncate text-left text-sm font-medium text-highlighted hover:underline"
                            data-testid="work-process-child-open"
                            @click.stop="openProcess(process)"
                          >
                            <template v-if="isClientMode">
                              {{ process.title }}
                            </template>
                            <template v-else>
                              {{ process.client?.name || `Cliente #${process.client_id}` }}
                            </template>
                          </button>
                        </div>
                      </th>
                      <td class="min-w-0 md:table-cell md:border-t md:border-default md:px-3 md:py-2">
                        <span class="mb-1 block text-xs text-muted md:hidden">Status</span>
                        <UBadge
                          size="sm"
                          variant="subtle"
                          :color="processStatusColor(process.status)"
                          :label="processStatusLabel(process.status)"
                          :class="TABLE_CELL_BADGE_CLASS"
                          :ui="TABLE_CELL_BADGE_UI"
                        />
                      </td>
                      <td class="min-w-0 text-sm tabular-nums text-toned md:table-cell md:border-t md:border-default md:px-3 md:py-2">
                        <span class="mb-1 block text-xs text-muted md:hidden">Prazo</span>
                        {{ formatDueDate(process.due_date) }}
                      </td>
                      <td class="min-w-0 text-sm tabular-nums text-toned md:table-cell md:border-t md:border-default md:px-3 md:py-2">
                        <span class="mb-1 block text-xs text-muted md:hidden">Meta</span>
                        {{ formatDueDate(process.target_due_date) }}
                      </td>
                      <td class="min-w-0 text-sm text-toned md:table-cell md:border-t md:border-default md:px-3 md:py-2">
                        <span class="mb-1 block text-xs text-muted md:hidden">Departamento</span>
                        <span class="line-clamp-2">{{ process.department?.name || 'Sem departamento' }}</span>
                      </td>
                      <td class="min-w-0 text-sm text-toned md:table-cell md:border-t md:border-default md:px-3 md:py-2">
                        <span class="mb-1 block text-xs text-muted md:hidden">Responsável</span>
                        <span class="line-clamp-2">{{ process.assignee?.name || 'Sem responsável' }}</span>
                      </td>
                    </tr>
                    <tr
                      v-if="isProcessIdExpanded(expandedChildProcessIds, process.id)"
                      class="block md:table-row"
                      :data-testid="`work-process-tasks-${process.id}`"
                    >
                      <td
                        colspan="6"
                        class="block border-t border-default bg-elevated/20 p-0 md:table-cell"
                      >
                        <table
                          v-if="processTasks(process).length"
                          class="w-full table-fixed border-collapse"
                          :aria-label="`Tarefas de ${process.title}`"
                          data-testid="work-process-task-table"
                        >
                          <thead class="hidden text-left text-xs text-muted md:table-header-group">
                            <tr>
                              <th scope="col" class="w-[48%] py-1.5 pl-10 pr-3 font-medium">
                                Tarefa
                              </th>
                              <th scope="col" class="w-[30%] px-3 py-1.5 font-medium">
                                Prazo e responsável
                              </th>
                              <th scope="col" class="w-[22%] px-3 py-1.5 font-medium">
                                Status
                              </th>
                            </tr>
                          </thead>
                          <tbody class="block divide-y divide-default/70 md:table-row-group md:divide-y-0">
                            <tr
                              v-for="task in processTasks(process)"
                              :key="task.id"
                              class="grid min-w-0 gap-2 px-3 py-2.5 pl-7 md:table-row md:px-0 md:py-0"
                              data-testid="work-process-task-row"
                            >
                              <th
                                scope="row"
                                class="min-w-0 text-left font-normal md:table-cell md:border-t md:border-default/70 md:py-2 md:pl-10 md:pr-3"
                              >
                                <div class="flex min-w-0 items-center gap-1.5">
                                  <UCheckbox
                                    v-if="canBulkTasks"
                                    :model-value="!!selectedTaskIds[String(task.id)]"
                                    :aria-label="`Selecionar tarefa ${task.title}`"
                                    data-testid="work-process-task-select"
                                    @update:model-value="toggleTaskSelected(task.id, $event)"
                                  />
                                  <UIcon
                                    name="i-lucide-list-checks"
                                    class="size-3.5 shrink-0 text-muted"
                                    aria-hidden="true"
                                  />
                                  <span class="shrink-0 text-xs tabular-nums text-muted">
                                    {{ task.sort_order }}.
                                  </span>
                                  <NuxtLink
                                    :to="`/work/tasks/${task.id}`"
                                    class="min-w-0 truncate text-sm font-medium text-highlighted hover:underline"
                                    data-testid="work-process-task-detail"
                                  >
                                    {{ task.title }}
                                  </NuxtLink>
                                </div>
                              </th>
                              <td class="min-w-0 text-sm text-toned md:table-cell md:border-t md:border-default/70 md:px-3 md:py-2">
                                <span class="mb-1 block text-xs text-muted md:hidden">Prazo e responsável</span>
                                <span class="tabular-nums">{{ formatDueDate(task.effective_due_date || task.due_date) }}</span>
                                <span class="text-muted"> · {{ task.assignee?.name || 'Sem responsável' }}</span>
                              </td>
                              <td class="min-w-0 md:table-cell md:border-t md:border-default/70 md:px-3 md:py-2">
                                <span class="mb-1 block text-xs text-muted md:hidden">Status da tarefa</span>
                                <WorkTaskStatusSelect
                                  :task-id="task.id"
                                  :status="task.status"
                                  :lock-version="task.lock_version"
                                  :can-claim="!task.assignee?.membership_id && !task.assignee_membership_id"
                                  :requires-evidence="task.requires_evidence"
                                  :disabled="!canExecute"
                                  @updated="retryGroupChildren(row.original)"
                                />
                              </td>
                            </tr>
                          </tbody>
                        </table>
                        <p
                          v-else
                          class="px-7 py-2 text-xs text-muted"
                        >
                          Sem tarefas neste processo
                        </p>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
              <div
                v-if="groupCacheEntry(row.original.key).total > childPerPage"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-default px-3 py-2"
                data-testid="work-process-group-children-pagination"
              >
                <span class="text-xs text-muted tabular-nums">
                  Página {{ groupCacheEntry(row.original.key).page }}
                </span>
                <div class="flex gap-1">
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="ghost"
                    label="Anterior"
                    :disabled="groupCacheEntry(row.original.key).page <= 1 || groupCacheEntry(row.original.key).status === 'loading'"
                    @click="loadGroupChildren(row.original, groupCacheEntry(row.original.key).page - 1, { force: true })"
                  />
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="ghost"
                    label="Próxima"
                    :disabled="groupCacheEntry(row.original.key).page * childPerPage >= groupCacheEntry(row.original.key).total || groupCacheEntry(row.original.key).status === 'loading'"
                    @click="loadGroupChildren(row.original, groupCacheEntry(row.original.key).page + 1, { force: true })"
                  />
                </div>
              </div>
            </div>
            <p
              v-else-if="groupCacheEntry(row.original.key).status === 'ready'"
              class="rounded-md border border-default px-3 py-2 text-sm text-muted"
            >
              {{ isClientMode ? 'Sem processos neste grupo' : 'Sem empresas neste grupo' }}
            </p>
          </div>
        </template>
        <template #empty>
          <UButton
            v-if="emptyKind === 'filtered'"
            size="sm"
            color="neutral"
            variant="outline"
            label="Limpar filtros"
            data-testid="work-processes-clear-filters"
            @click="onClearStructuredFilters"
          />
        </template>
      </ShellDataTable>

      <WorkBulkActionsModal
        v-model:open="bulkOpen"
        :processes="selectedProcessBulkItems"
        :tasks="selectedTaskBulkItems"
        :can-execute-tasks="canExecute"
        :can-administer="canAdmin"
        :can-update-processes="canUpdateProcesses"
        @done="onBulkDone"
      />
    </template>
  </ShellPagePanel>
</template>

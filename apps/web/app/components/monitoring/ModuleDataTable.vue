<script setup lang="ts" generic="T">
/**
 * Grade de carteira fiscal — arquétipo customers.vue do template.
 *
 * Compõe ShellDataTable (desktop UTable + cards &lt; md via shell) com
 * columnVisibility «Exibir» e empty fiscal. Emite update:page / update:perPage.
 *
 * @see .local/reference/nuxt-dashboard-template/app/pages/customers.vue
 * @see components/shell/DataTable.vue
 * @see components/shell/MobileCards.vue
 */
import type { TableColumn } from '@nuxt/ui'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { upperFirst } from 'scule'
import type {
  FiscalModuleSortingState
} from '~/composables/useFiscalModulePortfolio'
import type {
  FiscalTableEmptyKind,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import { resolveFiscalEmptyKind } from '~/utils/fiscal-status'
import { hasActiveMonitoringFilters } from '~/utils/monitoring-filters'
import {
  pruneMonitoringSelection,
  selectedMonitoringRows
} from '~/utils/monitoring-selection'
import ShellDataTable from '~/components/shell/DataTable.vue'

/**
 * Classe neutra da grade desktop: preenche a viewport sem forçar barra horizontal.
 * Scroll (`horizontalScroll`) fica só como escape hatch se o conteúdo ainda
 * ultrapassar — não injeta largura mínima artificial na &lt;table&gt;.
 */
const FIT_VIEWPORT_TABLE_CLASS = 'w-full min-w-0'

/** Resumo padrão das carteiras fiscais nos cards shell. */
const DEFAULT_SUMMARY_IDS = [
  'last_declaration',
  'competence',
  'period',
  'coverage',
  'consulted',
  'last_search',
  'observed',
  'synced'
]

const UCheckbox = resolveComponent('UCheckbox')

const props = withDefaults(defineProps<{
  columns: TableColumn<T>[]
  rows: T[]
  loading?: boolean
  error?: string | null
  page: number
  lastPage: number
  total: number
  perPage?: number
  sorting: FiscalModuleSortingState
  filters: MonitoringFilterValue
  selectionScope: string
  selectionEnabled?: boolean
  getRowId: (row: T, index: number) => string
  getClientId?: (row: T) => number | null
  columnLabels?: Record<string, string>
  initialHiddenColumns?: string[]
  showColumnVisibility?: boolean
  /**
   * Escape hatch: wrapper `overflow-x-auto` se a grade ainda ultrapassar a viewport.
   * Preferir caber na tela (colunas `w-full` + ocultas default) a forçar `min-w-*`.
   * No mobile os cards substituem a grade — este flag não afeta o phone.
   */
  horizontalScroll?: boolean
  tableClass?: string
  /**
   * Ativa cards no viewport &lt; md (default true).
   * Passe false para forçar tabela + scroll também no mobile.
   */
  mobileCards?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyKind?: FiscalTableEmptyKind | null
  clientColumnId?: string
  situationColumnId?: string
  summaryColumnIds?: string[]
}>(), {
  loading: false,
  error: null,
  perPage: 20,
  selectionEnabled: false,
  getClientId: undefined,
  columnLabels: () => ({}),
  initialHiddenColumns: () => [],
  showColumnVisibility: true,
  horizontalScroll: false,
  tableClass: undefined,
  mobileCards: true,
  emptyTitle: undefined,
  emptyDescription: undefined,
  emptyKind: null,
  clientColumnId: 'client',
  situationColumnId: 'situation',
  summaryColumnIds: undefined
})

const emit = defineEmits<{
  'update:page': [value: number]
  'update:perPage': [value: number]
  'update:sorting': [value: FiscalModuleSortingState]
  'selection-change': [payload: { rows: T[], clientIds: number[], count: number }]
  'refresh': []
}>()

const table = useTemplateRef<{
  tableApi?: {
    getAllColumns: () => Array<{
      id: string
      getCanHide: () => boolean
      getIsVisible: () => boolean
      toggleVisibility: (value: boolean) => void
    }>
    resetRowSelection: () => void
  }
  usingMobileCards?: boolean
} | null>('shellTable')

const columnVisibility = ref<Record<string, boolean>>(
  Object.fromEntries(props.initialHiddenColumns.map(id => [id, false]))
)
const rowSelection = ref<Record<string, boolean>>({})

const pageModel = computed({
  get: () => props.page,
  set: (value: number) => emit('update:page', value)
})
const sortingModel = computed({
  get: () => props.sorting,
  set: (value: FiscalModuleSortingState) => emit('update:sorting', value)
})
const selectedRows = computed(() =>
  selectedMonitoringRows(props.rows, rowSelection.value, props.getRowId)
)
const selectedClientIds = computed(() => {
  if (!props.getClientId) return []
  return [...new Set(
    selectedRows.value
      .map(props.getClientId)
      .filter((id): id is number => id != null && id > 0)
  )]
})
const selectedCount = computed(() => selectedRows.value.length)

function clearSelection() {
  rowSelection.value = {}
  table.value?.tableApi?.resetRowSelection?.()
}

watch(() => props.selectionScope, clearSelection)
watch(() => props.selectionEnabled, (enabled) => {
  if (!enabled) clearSelection()
})
watch(() => props.rows, (rows) => {
  const pruned = pruneMonitoringSelection(rows, rowSelection.value, props.getRowId)
  if (JSON.stringify(pruned) !== JSON.stringify(rowSelection.value)) rowSelection.value = pruned
}, { deep: true })
let lastSelectionSignature = ''
watch([selectedRows, selectedClientIds], () => {
  const clientIds = selectedClientIds.value
  const count = selectedCount.value
  const signature = `${count}:${clientIds.join(',')}`
  if (signature === lastSelectionSignature) return
  lastSelectionSignature = signature
  emit('selection-change', {
    rows: selectedRows.value,
    clientIds,
    count
  })
}, { deep: true, immediate: true })

const selectColumn = computed<TableColumn<T>>(() => ({
  id: 'select',
  enableHiding: false,
  enableSorting: false,
  meta: { class: { th: 'w-10 min-w-10', td: 'w-10 min-w-10' } },
  header: ({ table: current }) => h(UCheckbox, {
    'modelValue': current.getIsSomePageRowsSelected()
      ? 'indeterminate'
      : current.getIsAllPageRowsSelected(),
    'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
      current.toggleAllPageRowsSelected(!!value),
    'ariaLabel': 'Selecionar todas as linhas da página'
  }),
  cell: ({ row }) => h(UCheckbox, {
    'modelValue': row.getIsSelected(),
    'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
    'ariaLabel': 'Selecionar linha'
  })
}))
const tableColumns = computed(() => {
  if (!props.selectionEnabled || props.columns.some(column => column.id === 'select')) {
    return props.columns
  }
  return [selectColumn.value, ...props.columns]
})

const labels = computed<Record<string, string>>(() => ({
  select: 'Seleção',
  client: 'Cliente',
  competence: 'Competência',
  situation: 'Situação',
  coverage: 'Cobertura',
  consulted: 'Consulta',
  observed: 'Observado',
  synced: 'Sincronizado',
  actions: 'Ações',
  ...props.columnLabels
}))
const displayColumnItems = computed(() => table.value?.tableApi
  ?.getAllColumns()
  .filter(column => column.getCanHide())
  .map(column => ({
    label: labels.value[column.id] || upperFirst(column.id),
    type: 'checkbox' as const,
    checked: column.getIsVisible(),
    onUpdateChecked: (checked: boolean) => column.toggleVisibility(checked),
    onSelect: (event?: Event) => event?.preventDefault()
  })) || [])

const filtered = computed(() => hasActiveMonitoringFilters(props.filters))
const resolvedEmptyKind = computed(() => props.emptyKind || resolveFiscalEmptyKind({
  loading: props.loading,
  error: props.error,
  hasRows: props.rows.length > 0,
  hasPrevious: props.rows.length > 0,
  situation: props.filters.situation,
  filtered: filtered.value
}))
const itemsPerPage = computed(() => props.perPage > 0
  ? props.perPage
  : props.lastPage > 0 && props.total > 0
    ? Math.max(1, Math.ceil(props.total / props.lastPage))
    : 20)

const breakpoints = useBreakpoints(breakpointsTailwind)
const isNarrow = breakpoints.smaller('sm')
const isCompact = breakpoints.smaller('md')
const useMobileCards = computed(() => props.mobileCards && isCompact.value)
const paginationSiblingCount = computed(() => (isNarrow.value ? 0 : 1))

/** «Exibir colunas» só faz sentido com a tabela desktop montada. */
const canShowColumnVisibility = computed(() =>
  props.showColumnVisibility && !useMobileCards.value
)

const resolvedTableClass = computed(() => {
  const custom = props.tableClass?.trim()
  if (custom) return custom
  return FIT_VIEWPORT_TABLE_CLASS
})

const resolvedSummaryIds = computed(() =>
  props.summaryColumnIds?.length
    ? props.summaryColumnIds
    : DEFAULT_SUMMARY_IDS
)

const emptyKindShell = computed(() => {
  const kind = resolvedEmptyKind.value
  if (kind === 'error' || kind === 'filtered' || kind === 'empty') return kind
  return props.error ? 'error' : 'empty'
})

defineExpose({ clearSelection })
</script>

<template>
  <!--
    Stack toolbar · tabela · footer — mesmo papel do bloco em /clients:
    um único flex-1 no #body; ShellDataTable empurra o footer com mt-auto.
  -->
  <div
    class="flex min-w-0 flex-1 flex-col gap-1.5"
    data-testid="fiscal-table-stack"
  >
    <slot
      name="toolbar"
      :display-column-items="displayColumnItems"
      :show-column-visibility="canShowColumnVisibility"
    />

    <ShellDataTable
      ref="shellTable"
      v-model:column-visibility="columnVisibility"
      v-model:row-selection="rowSelection"
      v-model:sorting="sortingModel"
      ui-preset="monitoring-compact"
      test-id="fiscal-table"
      footer-test-id="fiscal-pagination"
      mobile-cards-test-id="fiscal-mobile-cards"
      :mobile-cards="mobileCards"
      :selection-enabled="selectionEnabled"
      :columns="tableColumns"
      :data="rows"
      :loading="loading"
      :page="page"
      :total="total"
      :items-per-page="itemsPerPage"
      :get-row-id="getRowId"
      :table-class="resolvedTableClass"
      :horizontal-scroll="horizontalScroll"
      :manual-sorting="true"
      :selected-count="selectionEnabled ? selectedCount : 0"
      :sibling-count="paginationSiblingCount"
      :show-edges="!isNarrow"
      :column-labels="labels"
      :primary-column-id="clientColumnId"
      :status-column-id="situationColumnId"
      :summary-column-ids="resolvedSummaryIds"
      :empty-kind="emptyKindShell"
      :empty-title="emptyTitle"
      :empty-description="emptyDescription"
      :error="error"
      per-page-aria-label="Registros por página"
      @update:page="pageModel = $event"
      @update:items-per-page="emit('update:perPage', $event)"
      @retry="emit('refresh')"
    >
      <template #empty>
        <MonitoringTableEmptyState
          :kind="resolvedEmptyKind"
          :title="emptyTitle"
          :description="emptyDescription"
          :error="error"
          class="py-10"
          @retry="emit('refresh')"
        />
      </template>
      <template #footer>
        <template v-if="selectionEnabled">
          <span class="tabular-nums">{{ selectedCount }}</span>
          <span class="max-sm:hidden"> de {{ rows.length }}</span>
          selecionado(s)
          <span class="text-dimmed"> · </span>
        </template>
        <span class="tabular-nums">{{ total }}</span> registro(s)
        <template v-if="lastPage > 1">
          <span class="max-sm:hidden">
            · página {{ page }} de {{ Math.max(lastPage, 1) }}
          </span>
          <span class="sm:hidden tabular-nums">
            · {{ page }}/{{ Math.max(lastPage, 1) }}
          </span>
        </template>
      </template>
    </ShellDataTable>
  </div>
</template>

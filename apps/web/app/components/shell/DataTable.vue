<script setup lang="ts" generic="T">
/**
 * Grade de lista admin — UTable + presets table-ui + ShellTableFooter.
 * Em viewport &lt; md (mobileCards): ShellMobileCards com slots/campos primários.
 * Pages/domínio fornecem columns/data/paginação; não embute fetch.
 *
 * @see utils/table-ui.ts
 * @see components/shell/TableFooter.vue
 * @see components/shell/MobileCards.vue
 */
import type { TableColumn } from '@nuxt/ui'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import {
  DASHBOARD_TABLE_UI,
  LIST_TABLE_CLASS,
  MONITORING_COMPACT_TABLE_UI
} from '~/utils/table-ui'
import ShellTableFooter from '~/components/shell/TableFooter.vue'
import ShellListEmpty from '~/components/shell/ListEmpty.vue'
import ShellMobileCards from '~/components/shell/MobileCards.vue'

export type ShellDataTableUiPreset = 'dashboard' | 'monitoring-compact'

const props = withDefaults(defineProps<{
  columns: TableColumn<T>[]
  data: T[]
  loading?: boolean
  page: number
  total: number
  itemsPerPage?: number
  /** Preset `:ui` — dashboard (admin) ou monitoring-compact (carteiras/clientes). */
  uiPreset?: ShellDataTableUiPreset
  /** Override direto de `:ui` (ganha do preset). */
  ui?: Record<string, string>
  tableClass?: string | string[]
  getRowId?: (row: T, index: number) => string
  showFooter?: boolean
  showPerPage?: boolean
  showPagination?: boolean
  perPageAriaLabel?: string
  selectedCount?: number
  siblingCount?: number
  showEdges?: boolean
  emptyKind?: 'empty' | 'filtered' | 'error'
  emptyTitle?: string
  emptyDescription?: string
  error?: string | null
  footerTestId?: string
  testId?: string
  /** Scroll horizontal no wrapper da tabela (só desktop). */
  horizontalScroll?: boolean
  sorting?: Array<{ id: string, desc: boolean }>
  rowSelection?: Record<string, boolean>
  columnVisibility?: Record<string, boolean>
  /** Estado de linhas expansíveis (TanStack / UTable `v-model:expanded`). */
  expanded?: true | Record<string, boolean>
  manualSorting?: boolean
  /**
   * Cards no viewport &lt; md (default true).
   * false força tabela + eventual scroll também no telefone.
   */
  mobileCards?: boolean
  selectionEnabled?: boolean
  columnLabels?: Record<string, string>
  primaryColumnId?: string
  statusColumnId?: string
  summaryColumnIds?: string[]
  mobileCardsTestId?: string
}>(), {
  loading: false,
  itemsPerPage: 20,
  uiPreset: 'dashboard',
  ui: undefined,
  showFooter: true,
  showPerPage: true,
  showPagination: true,
  perPageAriaLabel: 'Linhas por página',
  selectedCount: 0,
  siblingCount: 1,
  showEdges: true,
  emptyKind: 'empty',
  emptyTitle: undefined,
  emptyDescription: undefined,
  error: null,
  footerTestId: 'list-table-footer',
  testId: 'shell-data-table',
  horizontalScroll: false,
  sorting: undefined,
  rowSelection: undefined,
  columnVisibility: undefined,
  expanded: undefined,
  manualSorting: false,
  mobileCards: true,
  selectionEnabled: false,
  columnLabels: () => ({}),
  primaryColumnId: undefined,
  statusColumnId: undefined,
  summaryColumnIds: undefined,
  mobileCardsTestId: undefined
})

const emit = defineEmits<{
  'update:page': [page: number]
  'update:itemsPerPage': [perPage: number]
  'update:sorting': [value: Array<{ id: string, desc: boolean }>]
  'update:rowSelection': [value: Record<string, boolean>]
  'update:columnVisibility': [value: Record<string, boolean>]
  'update:expanded': [value: true | Record<string, boolean>]
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- compat TableRow/TanStack das pages
  'select': [event: Event, row: any]
  'retry': []
}>()

const slots = useSlots()

const tableRef = useTemplateRef<{ tableApi?: unknown } | null>('table')

const breakpoints = useBreakpoints(breakpointsTailwind)
const isCompact = breakpoints.smaller('md')
const useMobileCards = computed(() => props.mobileCards && isCompact.value)

const tableUi = computed(() =>
  props.ui
  ?? (props.uiPreset === 'monitoring-compact'
    ? MONITORING_COMPACT_TABLE_UI
    : DASHBOARD_TABLE_UI)
)

const reservedSlotNames = new Set(['empty', 'footer', 'footer-trailing'])

const forwardedSlotNames = computed(() =>
  Object.keys(slots).filter(name => !reservedSlotNames.has(name))
)

const showDefaultEmpty = computed(() =>
  !props.data.length && !props.loading
)

const sortingModel = computed({
  get: () => props.sorting ?? [],
  set: (value: Array<{ id: string, desc: boolean }>) => emit('update:sorting', value)
})
const rowSelectionModel = computed({
  get: () => props.rowSelection ?? {},
  set: (value: Record<string, boolean>) => emit('update:rowSelection', value)
})
const columnVisibilityModel = computed({
  get: () => props.columnVisibility ?? {},
  set: (value: Record<string, boolean>) => emit('update:columnVisibility', value)
})
const expandedAttrs = computed(() => {
  if (props.expanded === undefined) return {}
  return {
    'expanded': props.expanded,
    'onUpdate:expanded': (value: true | Record<string, boolean> | undefined) => {
      if (value === undefined) return
      emit('update:expanded', value)
    }
  }
})

const resolvedGetRowId = computed(() =>
  props.getRowId ?? ((row: T, index: number) => {
    const record = row as { id?: string | number }
    return record.id != null ? String(record.id) : String(index)
  })
)

const resolvedMobileTestId = computed(() =>
  props.mobileCardsTestId || `${props.testId}-mobile-cards`
)

defineExpose({
  get tableApi() {
    return tableRef.value?.tableApi
  },
  get usingMobileCards() {
    return useMobileCards.value
  }
})
</script>

<template>
  <div
    class="flex min-w-0 flex-1 flex-col gap-1.5"
    :data-testid="testId"
  >
    <ShellMobileCards
      v-if="useMobileCards"
      :rows="data"
      :columns="columns"
      :get-row-id="resolvedGetRowId"
      :selection-enabled="selectionEnabled"
      :row-selection="rowSelectionModel"
      :column-labels="columnLabels"
      :loading="loading"
      :empty-title="emptyTitle"
      :empty-description="emptyDescription"
      :empty-kind="emptyKind"
      :error="error"
      :primary-column-id="primaryColumnId"
      :status-column-id="statusColumnId"
      :summary-column-ids="summaryColumnIds"
      :test-id="resolvedMobileTestId"
      @update:row-selection="rowSelectionModel = $event"
      @retry="emit('retry')"
    >
      <template
        v-for="name in forwardedSlotNames"
        :key="name"
        #[name]="slotData"
      >
        <slot
          :name="name"
          v-bind="slotData || {}"
        />
      </template>
      <template #empty>
        <slot name="empty">
          <ShellListEmpty
            v-if="showDefaultEmpty"
            :kind="error ? 'error' : emptyKind"
            :title="emptyTitle"
            :description="emptyDescription"
            :error="error"
            @retry="emit('retry')"
          />
        </slot>
      </template>
    </ShellMobileCards>

    <div
      v-else
      class="min-w-0 shrink-0"
      :class="horizontalScroll
        ? 'overflow-x-auto overscroll-x-contain [-webkit-overflow-scrolling:touch]'
        : undefined"
      data-testid="shell-data-table-desktop"
    >
      <UTable
        ref="table"
        v-model:column-visibility="columnVisibilityModel"
        v-model:row-selection="rowSelectionModel"
        v-model:sorting="sortingModel"
        v-bind="expandedAttrs"
        :data="data"
        :columns="columns"
        :loading="loading"
        :get-row-id="resolvedGetRowId"
        :sorting-options="manualSorting
          ? { manualSorting: true, enableMultiSort: false }
          : undefined"
        :ui="tableUi"
        :class="[LIST_TABLE_CLASS, tableClass]"
        :data-testid="`${testId}-table`"
        @select="(event, row) => emit('select', event, row)"
      >
        <template
          v-for="name in forwardedSlotNames"
          :key="name"
          #[name]="slotData"
        >
          <slot
            :name="name"
            v-bind="slotData || {}"
          />
        </template>
        <template #empty>
          <slot name="empty">
            <ShellListEmpty
              v-if="showDefaultEmpty"
              :kind="error ? 'error' : emptyKind"
              :title="emptyTitle"
              :description="emptyDescription"
              :error="error"
              @retry="emit('retry')"
            />
          </slot>
        </template>
      </UTable>
    </div>

    <ShellTableFooter
      v-if="showFooter"
      :total="total"
      :page="page"
      :items-per-page="itemsPerPage"
      :selected-count="selectedCount"
      :show-per-page="showPerPage"
      :show-pagination="showPagination"
      :per-page-aria-label="perPageAriaLabel"
      :sibling-count="siblingCount"
      :show-edges="showEdges"
      :test-id="footerTestId"
      @update:page="emit('update:page', $event)"
      @update:items-per-page="emit('update:itemsPerPage', $event)"
    >
      <slot name="footer">
        <template v-if="selectedCount">
          <span class="tabular-nums">{{ selectedCount }}</span> selecionado(s)
          <span class="text-dimmed"> · </span>
        </template>
        <span class="tabular-nums">{{ total }}</span> registro(s)
      </slot>
      <template
        v-if="$slots['footer-trailing']"
        #trailing
      >
        <slot name="footer-trailing" />
      </template>
    </ShellTableFooter>
  </div>
</template>

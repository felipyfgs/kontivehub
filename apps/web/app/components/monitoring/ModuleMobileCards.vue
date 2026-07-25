<script setup lang="ts" generic="T">
/**
 * Cards mobile da carteira fiscal — wrapper fino sobre ShellMobileCards
 * com defaults fiscais (client/situation/summary) e empty Monitoring*.
 *
 * @see components/shell/MobileCards.vue
 */
import type { TableColumn } from '@nuxt/ui'
import type { FiscalTableEmptyKind } from '~/types/fiscal-modules'
import ShellMobileCards from '~/components/shell/MobileCards.vue'

/** Resumo padrão das carteiras fiscais. */
const DEFAULT_SUMMARY_IDS = [
  'last_declaration',
  'competence',
  'period',
  'coverage',
  'consulted',
  'last_search',
  'observed',
  'synced'
] as const

const props = withDefaults(defineProps<{
  rows: T[]
  columns: TableColumn<T>[]
  getRowId: (row: T, index: number) => string
  getClientId?: (row: T) => number | null
  selectionEnabled?: boolean
  rowSelection: Record<string, boolean>
  columnLabels?: Record<string, string>
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyKind?: FiscalTableEmptyKind | null
  error?: string | null
  clientColumnId?: string
  situationColumnId?: string
  summaryColumnIds?: string[]
}>(), {
  selectionEnabled: false,
  getClientId: undefined,
  columnLabels: () => ({}),
  loading: false,
  emptyTitle: undefined,
  emptyDescription: undefined,
  emptyKind: null,
  error: null,
  clientColumnId: 'client',
  situationColumnId: 'situation',
  summaryColumnIds: undefined
})

const emit = defineEmits<{
  'update:rowSelection': [value: Record<string, boolean>]
  'refresh': []
}>()

const resolvedSummaryIds = computed(() =>
  props.summaryColumnIds?.length
    ? props.summaryColumnIds
    : [...DEFAULT_SUMMARY_IDS]
)

const emptyKindShell = computed(() => {
  const kind = props.emptyKind
  if (kind === 'error' || kind === 'filtered' || kind === 'empty') return kind
  return props.error ? 'error' : 'empty'
})
</script>

<template>
  <ShellMobileCards
    test-id="fiscal-mobile-cards"
    :rows="rows"
    :columns="columns"
    :get-row-id="getRowId"
    :selection-enabled="selectionEnabled"
    :row-selection="rowSelection"
    :column-labels="columnLabels"
    :loading="loading"
    :empty-title="emptyTitle"
    :empty-description="emptyDescription"
    :empty-kind="emptyKindShell"
    :error="error"
    :primary-column-id="clientColumnId"
    :status-column-id="situationColumnId"
    :summary-column-ids="resolvedSummaryIds"
    @update:row-selection="emit('update:rowSelection', $event)"
    @retry="emit('refresh')"
  >
    <template
      v-for="(_, name) in $slots"
      :key="name"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData || {}"
      />
    </template>
    <template #empty>
      <MonitoringTableEmptyState
        :kind="emptyKind || (error ? 'error' : 'empty')"
        :title="emptyTitle"
        :description="emptyDescription"
        :error="error"
        class="py-10"
        @retry="emit('refresh')"
      />
    </template>
  </ShellMobileCards>
</template>

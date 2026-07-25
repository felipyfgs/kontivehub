<script setup lang="ts" generic="T">
import type { TableColumn } from '@nuxt/ui'
import type { FiscalModuleSortingState } from '~/composables/useFiscalModulePortfolio'
import type {
  FiscalModuleCounters,
  FiscalMonitoringSurfaceSummary,
  FiscalPortfolioModuleKey,
  FiscalTableEmptyKind,
  MonitoringFilterConfig,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import {
  fiscalKpiSituationFilter,
  fiscalSituationToKpiKey,
  isSurfaceUnavailable
} from '~/types/fiscal-modules'
import { resolveMonitoringSurface } from '~/types/saved-list-filters'
import { monitoringFilterSignature } from '~/utils/monitoring-filters'
import { monitoringSelectionScope } from '~/utils/monitoring-selection'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = withDefaults(defineProps<{
  title: string
  description?: string
  panelId?: string
  columns: TableColumn<T>[]
  rows: T[]
  loading?: boolean
  refreshing?: boolean
  error?: string | null
  page: number
  lastPage: number
  total: number
  perPage?: number
  filters: MonitoringFilterValue
  filterConfig: MonitoringFilterConfig
  sorting: FiscalModuleSortingState
  getRowId: (row: T, index: number) => string
  getClientId?: (row: T) => number | null
  moduleKey?: FiscalPortfolioModuleKey | null
  submodule?: string
  columnLabels?: Record<string, string>
  initialHiddenColumns?: string[]
  /** Override opt-in da seleção. Omitido preserva a disponibilidade das ações genéricas. */
  selectionEnabled?: boolean
  /** Substitui as ações em massa genéricas pelo slot `bulk-actions`. */
  customBulkActions?: boolean
  /** Tabela densa: scroll horizontal sem comprimir as colunas. */
  horizontalScroll?: boolean
  tableClass?: string
  /**
   * Cards no mobile (&lt; md). Default true no DataTable.
   * Passe false para manter tabela + scroll no phone.
   */
  mobileCards?: boolean
  totalClients?: number
  counters?: FiscalModuleCounters | null
  lastGoodAt?: string | null
  /** Origem fiscal do overview (DEMO / SIMULATED / LIVE). */
  dataOrigin?: string | null
  dataOriginLabel?: string | null
  sourceLabel?: string | null
  /** Observação fiscal oficial (as_of) — não confundir com lastGoodAt. */
  asOf?: string | null
  showKpis?: boolean
  /** Exibe a ação de consulta em lote no navbar. */
  showPendingSearch?: boolean
  showColumnVisibility?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyKind?: FiscalTableEmptyKind | null
  /**
   * Surface de presets salvos. Se omitido, deriva de moduleKey
   * (ex. installments → monitoring.installments). Páginas sem moduleKey
   * (registrations, tax_processes) devem passar explicitamente.
   */
  surface?: string | null
  /**
   * Resumo público da superfície do overview (result_kind / allows_document).
   * Distinto de `surface` (preset de filtros salvos).
   */
  surfaceSummary?: FiscalMonitoringSurfaceSummary | null
}>(), {
  description: undefined,
  panelId: 'fiscal-module',
  loading: false,
  refreshing: false,
  error: null,
  perPage: 20,
  getClientId: undefined,
  moduleKey: null,
  submodule: '',
  columnLabels: () => ({}),
  initialHiddenColumns: () => [],
  selectionEnabled: undefined,
  customBulkActions: false,
  horizontalScroll: false,
  tableClass: undefined,
  mobileCards: true,
  counters: null,
  lastGoodAt: null,
  dataOrigin: null,
  dataOriginLabel: null,
  sourceLabel: null,
  asOf: null,
  showKpis: true,
  showPendingSearch: true,
  showColumnVisibility: true,
  emptyTitle: undefined,
  emptyDescription: undefined,
  emptyKind: null,
  surface: null,
  surfaceSummary: null
})

const emit = defineEmits<{
  'update:page': [value: number]
  'update:perPage': [value: number]
  'update:sorting': [value: FiscalModuleSortingState]
  'quick-filter-change': [filters: MonitoringFilterValue]
  'apply-filters': [filters: MonitoringFilterValue]
  'reset-filters': [filters: MonitoringFilterValue]
  'refresh': []
  'selection-change': [payload: { rows: T[], clientIds: number[], count: number }]
}>()

const route = useRoute()
const { sessionEpoch } = useDashboard()
const dataTable = useTemplateRef<{ clearSelection: () => void } | null>('dataTable')
const selectedClientIds = ref<number[]>([])
const selectedCount = ref(0)
const bulkAvailable = ref(false)

const activeKpi = computed(() => fiscalSituationToKpiKey(props.filters.situation))
const resolvedSurface = computed(() => {
  if (props.surface) return props.surface
  return resolveMonitoringSurface(props.moduleKey)
})
const resolvedSelectionEnabled = computed(() => {
  if (typeof props.selectionEnabled === 'boolean') {
    return props.selectionEnabled && Boolean(props.getClientId)
  }
  return Boolean(props.moduleKey && props.getClientId && bulkAvailable.value)
})
const selectionScope = computed(() => monitoringSelectionScope({
  officeEpoch: sessionEpoch.value,
  route: route.fullPath,
  page: props.page,
  filters: monitoringFilterSignature(props.filters),
  sorting: props.sorting,
  submodule: props.submodule || ''
}))

const surfaceIsUnavailable = computed(() => isSurfaceUnavailable(props.surfaceSummary))
const surfaceUnavailableTitle = computed(() => {
  const label = props.surfaceSummary?.official_state_label?.trim()
  if (label) {
    return `Operação ainda não produtiva no catálogo SERPRO (${label})`
  }
  return 'Operação ainda não produtiva no catálogo SERPRO'
})
const currentPageClientIds = computed(() => {
  if (!props.getClientId) return []
  return [...new Set(props.rows
    .map(row => props.getClientId?.(row))
    .filter((id): id is number => Number.isInteger(id) && Number(id) > 0)
    .map(Number))]
})

function onSelectionChange(payload: { rows: T[], clientIds: number[], count: number }) {
  const same = payload.count === selectedCount.value
    && payload.clientIds.length === selectedClientIds.value.length
    && payload.clientIds.every((id, i) => id === selectedClientIds.value[i])
  if (same) {
    emit('selection-change', payload)
    return
  }
  selectedClientIds.value = payload.clientIds
  selectedCount.value = payload.count
  emit('selection-change', payload)
}

function clearSelection() {
  dataTable.value?.clearSelection()
  selectedClientIds.value = []
  selectedCount.value = 0
}

defineExpose({ clearSelection })

function onKpiSelect(key: Parameters<typeof fiscalKpiSituationFilter>[0]) {
  emit('quick-filter-change', {
    ...props.filters,
    situation: fiscalKpiSituationFilter(key) || 'all'
  })
}
</script>

<template>
  <UDashboardPanel :id="panelId">
    <template #header>
      <UDashboardNavbar
        :title="title"
        data-testid="page-navbar"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template
          v-if="showPendingSearch && moduleKey && getClientId && !surfaceIsUnavailable"
          #right
        >
          <MonitoringPendingSearchButton
            :module-key="moduleKey"
            :submodule="submodule"
            :competence="filters.competence"
            :current-page-client-ids="currentPageClientIds"
            :selected-client-ids="selectedClientIds"
            @submitted="emit('refresh')"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!--
        Mesmo contrato de /clients (customers.vue): filhos diretos do #body
        com gap/padding do painel. `contents` evita wrapper flex-1 aninhado
        que colava a paginação na borda inferior (sem o respiro do p-4/p-6).
      -->
      <div
        class="contents"
        data-testid="fiscal-module-body"
      >
        <FiscalModuleAvailabilityBanner
          :module-key="moduleKey"
          :surface="resolvedSurface"
        />

        <div
          v-if="$slots.submodules"
          class="w-full min-w-0 shrink-0 overflow-x-auto overscroll-x-contain [-webkit-overflow-scrolling:touch]"
          data-testid="fiscal-submodules"
        >
          <slot name="submodules" />
        </div>

        <!-- Superfície UNAVAILABLE (ex. DASN-SIMEI) — antes dos KPIs; sem dados sintéticos. -->
        <UAlert
          v-if="surfaceIsUnavailable"
          color="warning"
          variant="subtle"
          icon="i-lucide-circle-off"
          :title="surfaceUnavailableTitle"
          data-testid="fiscal-surface-unavailable-alert"
        />

        <div
          v-if="showKpis || $slots.kpis"
          class="w-full min-w-0 max-w-full"
          data-testid="fiscal-kpi-block"
        >
          <slot name="kpis">
            <MonitoringKpiStrip
              :total="totalClients ?? total"
              :total-clients="totalClients ?? total"
              :counters="counters"
              :loading="loading || refreshing"
              :active-key="activeKpi"
              :active-situation="filters.situation"
              @select="onKpiSelect"
            />
          </slot>
        </div>

        <p
          v-if="description"
          class="text-sm text-muted"
        >
          {{ description }}
        </p>

        <div
          v-if="$slots.utilities"
          class="flex min-w-0 flex-wrap items-center gap-2"
          data-testid="fiscal-utilities"
        >
          <slot name="utilities" />
          <span
            v-if="lastGoodAt && (error || refreshing)"
            class="text-xs text-muted"
          >
            Última atualização válida: {{ formatDateTime(lastGoodAt) }}
          </span>
        </div>

        <UAlert
          v-if="error"
          color="error"
          icon="i-lucide-circle-x"
          :title="error"
          data-testid="fiscal-error-alert"
        >
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar de novo"
              @click="emit('refresh')"
            />
          </template>
        </UAlert>

        <!--
          Fill único como /clients: toolbar + ShellDataTable (flex-1 + footer mt-auto).
          Sem stack flex-1 extra — o padding do #body (p-4 sm:p-6) volta a respirar.
        -->
        <MonitoringModuleDataTable
          ref="dataTable"
          :columns="columns"
          :rows="rows"
          :loading="loading || refreshing"
          :error="error"
          :page="page"
          :last-page="lastPage"
          :total="total"
          :per-page="perPage"
          :sorting="sorting"
          :filters="filters"
          :selection-scope="selectionScope"
          :selection-enabled="resolvedSelectionEnabled"
          :horizontal-scroll="horizontalScroll"
          :table-class="tableClass"
          :mobile-cards="mobileCards"
          :get-row-id="getRowId"
          :get-client-id="getClientId"
          :column-labels="columnLabels"
          :initial-hidden-columns="initialHiddenColumns"
          :show-column-visibility="showColumnVisibility"
          :empty-title="emptyTitle"
          :empty-description="emptyDescription"
          :empty-kind="emptyKind"
          @update:page="emit('update:page', $event)"
          @update:per-page="emit('update:perPage', $event)"
          @update:sorting="emit('update:sorting', $event)"
          @selection-change="onSelectionChange"
          @refresh="emit('refresh')"
        >
          <template #toolbar="{ displayColumnItems, showColumnVisibility: canDisplayColumns }">
            <MonitoringModuleToolbar
              :filters="filters"
              :filter-config="filterConfig"
              :loading="loading || refreshing"
              :show-total="false"
              :reset-key="sessionEpoch"
              :surface="resolvedSurface"
              @quick-filter-change="emit('quick-filter-change', $event)"
              @apply-filters="emit('apply-filters', $event)"
              @reset-filters="emit('reset-filters', $event)"
              @refresh="emit('refresh')"
            >
              <template #actions>
                <slot
                  v-if="customBulkActions"
                  name="bulk-actions"
                  :selected-client-ids="selectedClientIds"
                  :selected-count="selectedCount"
                  :clear-selection="clearSelection"
                />
                <MonitoringModuleBulkActions
                  v-else-if="moduleKey"
                  :module-key="moduleKey"
                  :selected-client-ids="selectedClientIds"
                  :selected-count="selectedCount"
                  :filters="filters"
                  :submodule="submodule"
                  @availability-change="bulkAvailable = $event"
                  @clear="clearSelection"
                  @refresh="emit('refresh')"
                />
              </template>
              <template #trailing>
                <UDropdownMenu
                  v-if="canDisplayColumns"
                  :items="displayColumnItems"
                  :content="{ align: 'end' }"
                >
                  <UButton
                    label="Colunas"
                    color="neutral"
                    variant="outline"
                    trailing-icon="i-lucide-settings-2"
                    aria-label="Exibir colunas"
                    :ui="COMPACT_BUTTON_LABEL_UI"
                    class="shrink-0"
                    data-testid="fiscal-column-visibility"
                  />
                </UDropdownMenu>
              </template>
            </MonitoringModuleToolbar>
          </template>
        </MonitoringModuleDataTable>

        <slot name="detail" />
      </div>
    </template>
  </UDashboardPanel>
</template>

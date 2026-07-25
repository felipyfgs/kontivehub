<script setup lang="ts">
/**
 * Adapter monitoring → ListFilterToolbar (padrão ouro).
 * Situação e demais campos vêm como chips; busca com debounce/Enter.
 */
import type { DataTableFilterModel } from '~/types/data-table-filter'
import type {
  MonitoringFilterConfig,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import {
  modelsToMonitoringFilters,
  monitoringFieldsToDefinitions,
  monitoringFiltersToModels,
  normalizeMonitoringFilters,
  resetMonitoringFilters,
  resolveMonitoringFilterFields
} from '~/utils/monitoring-filters'
import {
  hasActiveMonitoringFiltersForSave,
  monitoringFiltersToPayload,
  monitoringPayloadToFilters
} from '~/utils/saved-list-filters'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'

const props = withDefaults(defineProps<{
  filters: MonitoringFilterValue
  filterConfig?: MonitoringFilterConfig
  total?: number
  loading?: boolean
  showExport?: boolean
  canExport?: boolean
  showTotal?: boolean
  resetKey?: string | number | null
  surface?: string | null
  canShareFilters?: boolean
}>(), {
  filterConfig: () => ({}),
  showExport: false,
  canExport: false,
  showTotal: true,
  total: 0,
  resetKey: 0,
  surface: null,
  canShareFilters: undefined
})

const emit = defineEmits<{
  'quick-filter-change': [filters: MonitoringFilterValue]
  'apply-filters': [filters: MonitoringFilterValue]
  'reset-filters': [filters: MonitoringFilterValue]
  'refresh': []
  'export': []
}>()

const config = computed<MonitoringFilterConfig>(() => props.filterConfig || {})
const showSearch = computed(() => config.value.search !== false)
const structuredFields = computed(() => resolveMonitoringFilterFields(config.value))
const definitions = computed(() => monitoringFieldsToDefinitions(structuredFields.value))

const searchPlaceholder = computed(() => {
  const search = config.value.search
  if (search && typeof search === 'object' && search.placeholder) return search.placeholder
  return 'Buscar nome ou CNPJ…'
})

const searchAriaLabel = computed(() => {
  const search = config.value.search
  if (search && typeof search === 'object' && search.ariaLabel) return search.ariaLabel
  return 'Buscar por razão social ou CNPJ'
})

const appliedFilters = computed(() => normalizeMonitoringFilters(props.filters))

const clientLabelCache = ref<string | null>(null)

watch(
  () => appliedFilters.value.clientIds,
  (ids) => {
    if (!ids?.length) clientLabelCache.value = null
  }
)

watch(
  () => props.resetKey,
  (next, prev) => {
    if (prev === undefined) return
    if (next === prev) return
    clientLabelCache.value = null
  }
)

const chipModels = computed(() =>
  monitoringFiltersToModels(
    appliedFilters.value,
    config.value,
    clientLabelCache.value
  )
)

function getPayload(): SavedListFilterPayload {
  return monitoringFiltersToPayload(
    appliedFilters.value,
    config.value,
    clientLabelCache.value
  )
}

function canSave(): boolean {
  return hasActiveMonitoringFiltersForSave(appliedFilters.value, config.value)
}

function onQUpdate(next: string) {
  emit('quick-filter-change', normalizeMonitoringFilters({
    ...appliedFilters.value,
    q: next
  }))
}

function emitStructured(models: DataTableFilterModel[]) {
  const next = modelsToMonitoringFilters(models, config.value, {
    ...appliedFilters.value,
    q: appliedFilters.value.q
  })
  const clientModel = models.find(model => model.key === 'clientId')
  if (clientModel?.label) {
    clientLabelCache.value = clientModel.label
  } else if (!clientModel) {
    clientLabelCache.value = null
  }
  emit('apply-filters', next)
}

function onChipsClear() {
  clientLabelCache.value = null
  emit('reset-filters', resetMonitoringFilters())
}

function onApplyPreset(payload: SavedListFilterPayload) {
  const next = monitoringPayloadToFilters(payload, config.value)
  const models = Array.isArray((payload as { filters?: DataTableFilterModel[] })?.filters)
    ? (payload as { filters: DataTableFilterModel[] }).filters
    : []
  const clientModel = models.find(model => model.key === 'clientId')
  clientLabelCache.value = clientModel?.label || null
  emit('apply-filters', next)
}

function onClientPicked(client: {
  display_name?: string | null
  legal_name?: string | null
  name?: string | null
} | null) {
  if (!client) {
    clientLabelCache.value = null
    return
  }
  clientLabelCache.value = client.display_name || client.legal_name || client.name || null
}

function onClientsPicked(clients: Array<{
  display_name?: string | null
  legal_name?: string | null
  name?: string | null
}>) {
  if (!clients.length) {
    clientLabelCache.value = null
    return
  }
  clientLabelCache.value = clients
    .map(c => c.display_name || c.legal_name || c.name || 'Cliente')
    .join(', ')
}
</script>

<template>
  <ShellListFilterToolbar
    :q="appliedFilters.q"
    :show-search="showSearch"
    :search-placeholder="searchPlaceholder"
    :search-aria-label="searchAriaLabel"
    :definitions="definitions"
    :models="chipModels"
    :loading="loading"
    :show-total="showTotal"
    :total="total"
    :show-export="showExport"
    :can-export="canExport"
    :reset-key="resetKey"
    :surface="surface"
    :can-share-filters="canShareFilters"
    :get-payload="getPayload"
    :can-save="canSave"
    test-id-prefix="fiscal-filter"
    @update:q="onQUpdate"
    @update:models="emitStructured"
    @clear="onChipsClear"
    @refresh="emit('refresh')"
    @export="emit('export')"
    @apply-preset="onApplyPreset"
  >
    <template #actions>
      <slot name="actions" />
    </template>
    <template #trailing>
      <slot name="trailing" />
    </template>
    <template #client="{ modelValue, update, select, multiple }">
      <FiscalClientPicker
        :model-value="modelValue"
        :multiple="Boolean(multiple)"
        search-mode="select"
        :placeholder="multiple ? 'Buscar…' : 'Cliente'"
        class="w-full min-w-0"
        data-testid="fiscal-filter-client"
        @update:model-value="(value) => update?.(value as number | null)"
        @select="(client) => { select?.(client); if (!multiple) onClientPicked(client) }"
        @select-many="(clients) => { onClientsPicked(clients) }"
      />
    </template>
  </ShellListFilterToolbar>
</template>

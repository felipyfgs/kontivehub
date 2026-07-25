<script setup lang="ts">
/**
 * Toolbar de documentos no padrão ouro (chips live, sem “Aplicar”).
 * Surface: docs.catalog
 */
import type { Client, Establishment } from '~/types/api'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import { documentKindFilterItems } from '~/utils/document-kinds'
import {
  FILTER_ALL,
  emptyDocsFilters,
  type NotesFilterState,
  type NotesViewMode
} from '~/utils/notes-filters'
import {
  docsFiltersToPayload,
  docsPayloadToFilters,
  hasActiveDocsFiltersForSave
} from '~/utils/saved-list-filters'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'

const filters = defineModel<NotesFilterState>('filters', { required: true })
const operationalFilter = defineModel<string>('operationalFilter', { default: 'total' })

const props = defineProps<{
  clients: Client[]
  establishments: Establishment[]
  loadingFilters?: boolean
  view?: NotesViewMode
  selectedCount?: number
  canExport?: boolean
  exporting?: boolean
  resetKey?: string | number | null
}>()

const emit = defineEmits<{
  apply: []
  reset: []
  clientChange: []
  exportSelection: []
}>()

const { sessionEpoch } = useDashboard()

const searchPlaceholder = computed(() =>
  props.view === 'client'
    ? 'Filtrar por nome ou CNPJ/CPF…'
    : 'Buscar número, emitente, destinatário, CNPJ ou chave…'
)

const clientItems = computed(() =>
  props.clients.map(client => ({
    label: client.display_name || client.legal_name || client.name,
    value: String(client.id)
  }))
)

const establishmentItems = computed(() =>
  props.establishments.map(establishment => ({
    label: establishment.trade_name
      ? `${establishment.trade_name} · ${establishment.cnpj}`
      : establishment.cnpj,
    value: String(establishment.id)
  }))
)

const kindItems = documentKindFilterItems('Todos os tipos').filter(i => i.value !== FILTER_ALL)

const catalogDefinitions = computed((): DataTableFilterDefinition[] => {
  if (props.view === 'client') {
    return [
      {
        key: 'operational',
        kind: 'option',
        label: 'Captura',
        emptyValue: 'total',
        items: [
          { label: 'Captura com problema', value: 'capture_problem' }
        ]
      }
    ]
  }

  const defs: DataTableFilterDefinition[] = [
    {
      key: 'kind',
      kind: 'option',
      label: 'Tipo',
      emptyValue: FILTER_ALL,
      items: kindItems
    },
    {
      key: 'client_id',
      kind: 'option',
      label: 'Cliente',
      emptyValue: FILTER_ALL,
      items: clientItems.value
    },
    {
      key: 'status',
      kind: 'option',
      label: 'Situação',
      emptyValue: FILTER_ALL,
      items: [
        { label: 'Autorizada', value: 'AUTHORIZED' },
        { label: 'Cancelada', value: 'CANCELLED' },
        { label: 'Em revisão', value: 'UNKNOWN' }
      ]
    },
    {
      key: 'direction',
      kind: 'option',
      label: 'Direção',
      emptyValue: FILTER_ALL,
      items: [
        { label: 'Entrada', value: 'IN' },
        { label: 'Saída', value: 'OUT' }
      ]
    },
    {
      key: 'fiscal_role',
      kind: 'option',
      label: 'Papel fiscal',
      emptyValue: FILTER_ALL,
      items: [
        { label: 'Emitente', value: 'ISSUER' },
        { label: 'Tomador', value: 'TAKER' },
        { label: 'Intermediário', value: 'INTERMEDIARY' },
        { label: 'Remetente', value: 'SENDER' },
        { label: 'Destinatário', value: 'RECIPIENT' },
        { label: 'Expedidor', value: 'EXPEDITOR' },
        { label: 'Recebedor', value: 'RECEIVER' }
      ]
    },
    {
      key: 'competence',
      kind: 'month',
      label: 'Competência',
      emptyValue: ''
    },
    {
      key: 'issued',
      kind: 'date_range',
      label: 'Emissão',
      emptyValue: ''
    }
  ]

  if (filters.value.client_id && filters.value.client_id !== FILTER_ALL) {
    defs.splice(2, 0, {
      key: 'establishment_id',
      kind: 'option',
      label: 'Estabelecimento',
      emptyValue: FILTER_ALL,
      items: establishmentItems.value
    })
  }

  if (filters.value.kind === 'CTE' || filters.value.kind === FILTER_ALL) {
    defs.push(
      {
        key: 'acquisition_source',
        kind: 'option',
        label: 'Origem CT-e',
        emptyValue: FILTER_ALL,
        items: [
          { label: 'DistDFe CT-e do cliente', value: 'CTE_DIST_NSU' },
          { label: 'DistDFe autXML do escritório', value: 'CTE_AUTXML_DIST_NSU' },
          { label: 'Entrega autenticada do emissor', value: 'EMITTER_PUSH' },
          { label: 'Importação XML', value: 'MANUAL_XML' },
          { label: 'Importação ZIP', value: 'MANUAL_ZIP' }
        ]
      },
      {
        key: 'artifact_quality',
        kind: 'option',
        label: 'Qualidade CT-e',
        emptyValue: FILTER_ALL,
        items: [
          { label: 'Original', value: 'ORIGINAL' },
          { label: 'Original via autXML', value: 'AUTXML_ORIGINAL' },
          { label: 'Oficial redigido', value: 'AUTXML_REDACTED' }
        ]
      },
      {
        key: 'coverage_status',
        kind: 'option',
        label: 'Cobertura CT-e',
        emptyValue: FILTER_ALL,
        items: [
          { label: 'Capturado (original)', value: 'CAPTURED_ORIGINAL' },
          { label: 'Capturado (autXML redigido)', value: 'CAPTURED_AUTXML_REDACTED' },
          { label: 'Pendente de importação', value: 'PENDING_IMPORT' },
          { label: 'Lacuna histórica', value: 'HISTORICAL_GAP' },
          { label: 'Bloqueado', value: 'BLOCKED' },
          { label: 'Sem atividade observada', value: 'NO_ACTIVITY' }
        ]
      }
    )
  }

  return defs
})

function modelsFromState(): DataTableFilterModel[] {
  const models: DataTableFilterModel[] = []
  const defs = catalogDefinitions.value

  if (props.view === 'client') {
    const def = findDefinition(defs, 'operational')
    if (def) {
      const model = createFilterModel(def, operationalFilter.value)
      if (model) models.push(model)
    }
    return models
  }

  const f = filters.value
  const optionKeys = [
    'kind',
    'client_id',
    'establishment_id',
    'status',
    'direction',
    'fiscal_role',
    'acquisition_source',
    'artifact_quality',
    'coverage_status'
  ] as const

  for (const key of optionKeys) {
    const def = findDefinition(defs, key)
    if (!def) continue
    const value = f[key]
    if (!value || value === FILTER_ALL) continue
    const model = createFilterModel(def, value)
    if (model) models.push(model)
  }

  if (f.competence) {
    const def = findDefinition(defs, 'competence')
    if (def) {
      const model = createFilterModel(def, f.competence)
      if (model) models.push(model)
    }
  }

  if (f.issued_from || f.issued_to) {
    const def = findDefinition(defs, 'issued')
    if (def) {
      const range = `${f.issued_from || ''}..${f.issued_to || ''}`
      const model = createFilterModel(def, range)
      if (model) models.push(model)
    }
  }

  return models
}

const chipModels = ref<DataTableFilterModel[]>(modelsFromState())

watch(
  () => [filters.value, operationalFilter.value, props.view, props.clients, props.establishments] as const,
  () => {
    chipModels.value = modelsFromState()
  },
  { deep: true }
)

function onModelsUpdate(models: DataTableFilterModel[]) {
  chipModels.value = models

  if (props.view === 'client') {
    const op = models.find(m => m.key === 'operational')
    operationalFilter.value = op ? String(op.value) : 'total'
    emit('apply')
    return
  }

  const next = emptyDocsFilters()
  next.q = filters.value.q
  next.issuer_cnpj = filters.value.issuer_cnpj
  next.taker_cnpj = filters.value.taker_cnpj
  next.missing_party_name = filters.value.missing_party_name

  const assignableKeys = new Set<keyof NotesFilterState>([
    'kind',
    'client_id',
    'establishment_id',
    'status',
    'direction',
    'fiscal_role',
    'acquisition_source',
    'artifact_quality',
    'coverage_status',
    'competence'
  ])

  for (const model of models) {
    if (model.key === 'issued' && typeof model.value === 'string') {
      const [from, to] = model.value.split('..')
      next.issued_from = from?.trim() || ''
      next.issued_to = to?.trim() || ''
      continue
    }
    if (assignableKeys.has(model.key as keyof NotesFilterState)) {
      next[model.key as keyof NotesFilterState] = String(model.value)
    }
  }

  const clientChanged = next.client_id !== filters.value.client_id
  Object.assign(filters.value, next)
  if (!next.client_id || next.client_id === FILTER_ALL) {
    filters.value.establishment_id = FILTER_ALL
  }
  if (clientChanged) emit('clientChange')
  emit('apply')
}

function onClear() {
  if (props.view === 'client') {
    operationalFilter.value = 'total'
    filters.value.q = ''
  } else {
    Object.assign(filters.value, emptyDocsFilters())
  }
  chipModels.value = []
  emit('reset')
}

function onQUpdate(value: string) {
  filters.value.q = value
  emit('apply')
}

function onApplyPreset(payload: SavedListFilterPayload) {
  const next = docsPayloadToFilters(payload)
  Object.assign(filters.value, next)
  if (!next.client_id || next.client_id === FILTER_ALL) {
    filters.value.establishment_id = FILTER_ALL
  }
  chipModels.value = modelsFromState()
  emit('clientChange')
  emit('apply')
}
</script>

<template>
  <ShellListFilterToolbar
    :q="filters.q"
    :search-placeholder="searchPlaceholder"
    :search-aria-label="view === 'client' ? 'Filtrar clientes por nome ou CNPJ/CPF' : 'Buscar no catálogo de documentos'"
    :definitions="catalogDefinitions"
    :models="chipModels"
    :loading="loadingFilters"
    :reset-key="resetKey ?? sessionEpoch"
    :surface="view === 'client' ? null : 'docs.catalog'"
    :get-payload="() => docsFiltersToPayload(filters)"
    :can-save="() => hasActiveDocsFiltersForSave(filters)"
    test-id-prefix="docs-filter"
    @update:q="onQUpdate"
    @update:models="onModelsUpdate"
    @clear="onClear"
    @refresh="emit('apply')"
    @apply-preset="onApplyPreset"
  >
    <template #actions>
      <UButton
        v-if="canExport && selectedCount"
        color="primary"
        variant="subtle"
        icon="i-lucide-package"
        label="Exportar seleção"
        :loading="exporting"
        @click="emit('exportSelection')"
      >
        <template #trailing>
          <UKbd>{{ selectedCount }}</UKbd>
        </template>
      </UButton>
    </template>
  </ShellListFilterToolbar>
</template>

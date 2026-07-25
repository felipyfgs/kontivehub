<script setup lang="ts">
/**
 * Declarações — hub por obrigação (abas locais; URL fixa /monitoring/declarations).
 * Default PGDAS; DIRF unsupported honesto; FGTS cobertura parcial.
 */
import type {
  DeclarationOperation,
  DeclarationsClientRow,
  MonitoringFilterConfig
} from '~/types/fiscal-modules'
import {
  DECLARATIONS_TABS,
  declarationsSurfaceTitle,
  normalizeDeclarationsSubmodule
} from '~/types/fiscal-modules'
import {
  buildDeclarationsFgtsColumns,
  buildDeclarationsObligationColumns,
  buildDeclarationsPgdasColumns,
  DECLARATIONS_PGDAS_COLUMN_LABELS
} from '~/utils/declarations-table'
import { MONITORING_SHARED_COLUMN_LABELS } from '~/utils/monitoring-table-columns'
import { apiErrorMessage } from '~/utils/api-error'

const submodule = ref(normalizeDeclarationsSubmodule('PGDAS'))
const api = useApi()

const declarationOperations = ref<DeclarationOperation[]>([])
const declarationCatalogLoading = ref(true)
const declarationCatalogError = ref<string | null>(null)

async function loadDeclarationCatalog() {
  declarationCatalogLoading.value = true
  declarationCatalogError.value = null
  try {
    const response = await api.fiscal.declarations.catalog()
    declarationOperations.value = response.data.operation_catalog?.operations || []
  } catch (caught) {
    declarationOperations.value = []
    declarationCatalogError.value = apiErrorMessage(
      caught,
      'Não foi possível carregar as operações oficiais. A carteira local continua disponível.'
    )
  } finally {
    declarationCatalogLoading.value = false
  }
}

onMounted(() => {
  void loadDeclarationCatalog()
})

const {
  page,
  perPage,
  total,
  lastPage,
  filters,
  loading,
  refreshing,
  loadError,
  overviewError,
  overviewLoading,
  overview,
  rows,
  counters,
  totalClients,
  lastValidAt,
  dataOrigin,
  dataOriginLabel,
  sourceLabel,
  asOf,
  surface,
  sorting,
  setPage,
  setPerPage,
  refresh,
  applyFilters,
  applyQuickFilters,
  resetFilters
} = useFiscalModulePortfolio('declarations', {
  submodule
})

const { canManageClients, canTriggerSync } = useDashboard()
const {
  formOpen: clientFormOpen,
  formClient,
  canManageCredentials,
  openEditClient,
  onFormSaved: onClientFormSaved
} = useMonitoringClientEdit(() => refresh())

const isPgdas = computed(() => submodule.value === 'PGDAS')
const isDefis = computed(() => submodule.value === 'DEFIS')
const isDasnSimei = computed(() => submodule.value === 'DASN_SIMEI')
const isDctfweb = computed(() => submodule.value === 'DCTFWEB')
const isMit = computed(() => submodule.value === 'MIT')
const isFgts = computed(() => submodule.value === 'FGTS')
const isDirf = computed(() => submodule.value === 'DIRF')

const activeOperations = computed(() => declarationOperations.value.filter(
  operation => operation.obligation === submodule.value
))

const surfaceTitle = computed(() => declarationsSurfaceTitle(submodule.value))

function tabBadge(key: string): number | string {
  const count = overview.value?.metrics?.tab_counts?.[key]
  if (typeof count === 'number' && Number.isFinite(count)) return count
  return overviewLoading.value || (!overview.value && !overviewError.value) ? '…' : '—'
}

const tabItems = computed(() => DECLARATIONS_TABS.map(t => ({
  label: t.label,
  value: t.value,
  badge: tabBadge(t.value)
})))

const filterConfig = computed<MonitoringFilterConfig>(() => {
  if (isDirf.value) {
    return { fields: [] }
  }
  if (isFgts.value) {
    return {
      fields: [
        { key: 'situation', kind: 'option', label: 'Situação' },
        { key: 'clientId', kind: 'client', label: 'Cliente' },
        { key: 'competence', kind: 'month', label: 'Competência' }
      ]
    }
  }
  return {
    fields: [
      { key: 'situation', kind: 'option', label: 'Situação' },
      { key: 'clientId', kind: 'client', label: 'Cliente' },
      { key: 'competence', kind: 'month', label: 'Competência' }
    ]
  }
})

function getRowId(row: DeclarationsClientRow) {
  return `c:${row.client_id}`
}

// —— Modais ——
const pgdasHistoryOpen = ref(false)
const dctfwebHistoryOpen = ref(false)
const defisHistoryOpen = ref(false)
const dasnHistoryOpen = ref(false)
const mitHistoryOpen = ref(false)
const operationModalOpen = ref(false)
const operationClientId = ref<number | null>(null)
const operationClientName = ref<string | null>(null)
const modalClientId = ref<number | null>(null)
const modalClientName = ref<string | null>(null)
const modalCnpjMasked = ref<string | null>(null)

function closeModals() {
  pgdasHistoryOpen.value = false
  dctfwebHistoryOpen.value = false
  defisHistoryOpen.value = false
  dasnHistoryOpen.value = false
  mitHistoryOpen.value = false
  operationModalOpen.value = false
  modalClientId.value = null
  modalClientName.value = null
  modalCnpjMasked.value = null
}

function openOperations(row?: DeclarationsClientRow) {
  operationClientId.value = row?.client_id || null
  operationClientName.value = row
    ? row.legal_name || row.name || row.display_name || null
    : null
  operationModalOpen.value = true
}

function openModalClient(row: DeclarationsClientRow) {
  modalClientId.value = row.client_id
  modalClientName.value = row.legal_name || row.name || null
  modalCnpjMasked.value = row.cnpj_masked || null
}

function openPgdasHistory(row: DeclarationsClientRow) {
  openModalClient(row)
  pgdasHistoryOpen.value = true
}

function openDctfwebHistory(row: DeclarationsClientRow) {
  openModalClient(row)
  dctfwebHistoryOpen.value = true
}

function openDefisHistory(row: DeclarationsClientRow) {
  openModalClient(row)
  defisHistoryOpen.value = true
}

function openDasnHistory(row: DeclarationsClientRow) {
  openModalClient(row)
  dasnHistoryOpen.value = true
}

function openMitHistory(row: DeclarationsClientRow) {
  openModalClient(row)
  mitHistoryOpen.value = true
}

const columns = computed(() => {
  const onEdit = canManageClients.value
    ? (row: DeclarationsClientRow) => { void openEditClient(row.client_id) }
    : undefined
  if (isPgdas.value) {
    return buildDeclarationsPgdasColumns({
      onHistory: openPgdasHistory,
      onOperations: openOperations,
      onEditClient: onEdit
    })
  }
  if (isDctfweb.value) {
    return buildDeclarationsObligationColumns({
      onHistory: openDctfwebHistory,
      onOperations: openOperations,
      onEditClient: onEdit,
      historyLabel: 'Histórico'
    })
  }
  if (isDefis.value) {
    return buildDeclarationsObligationColumns({
      onHistory: openDefisHistory,
      onOperations: openOperations,
      onEditClient: onEdit,
      historyLabel: 'Histórico DEFIS'
    })
  }
  if (isDasnSimei.value) {
    return buildDeclarationsObligationColumns({
      onHistory: openDasnHistory,
      onOperations: openOperations,
      onEditClient: onEdit,
      historyLabel: 'Histórico DASN-SIMEI'
    })
  }
  if (isMit.value) {
    return buildDeclarationsObligationColumns({
      onHistory: openMitHistory,
      onOperations: openOperations,
      onEditClient: onEdit,
      historyLabel: 'Apurações MIT'
    })
  }
  if (isFgts.value) {
    return buildDeclarationsFgtsColumns({ onEditClient: onEdit })
  }
  // DIRF: colunas mínimas (tabela vazia + empty unsupported)
  return buildDeclarationsObligationColumns({ onEditClient: onEdit })
})

const columnLabels = computed(() => {
  if (isPgdas.value) return { ...DECLARATIONS_PGDAS_COLUMN_LABELS }
  if (isFgts.value) {
    return {
      competence: 'Competência',
      closure: 'Fechamento',
      totalization: 'Totalização',
      coverage: 'Cobertura',
      ...MONITORING_SHARED_COLUMN_LABELS
    }
  }
  return {
    obligation: 'Obrigação',
    ...MONITORING_SHARED_COLUMN_LABELS
  }
})

const emptyTitle = computed(() => {
  if (isDirf.value) return 'DIRF não suportada'
  if (isFgts.value) return 'Nenhum status FGTS na carteira'
  if (isPgdas.value) return 'Nenhuma declaração PGDAS'
  if (isDctfweb.value) return 'Nenhuma declaração DCTFWeb'
  if (isDefis.value) return 'Nenhuma declaração DEFIS'
  if (isDasnSimei.value) return 'Nenhuma declaração DASN-SIMEI'
  if (isMit.value) return 'Nenhuma apuração MIT'
  return 'Nenhuma declaração'
})

const emptyKind = computed(() => (isDirf.value ? 'unsupported' as const : null))

async function refreshAll() {
  await Promise.all([refresh(), loadDeclarationCatalog()])
}

watch(submodule, (next, prev) => {
  if (next === prev) return
  closeModals()
  setPage(1)
  resetFilters()
})
</script>

<template>
  <MonitoringModuleTable
    :title="surfaceTitle"
    panel-id="monitoring-declarations"
    module-key="declarations"
    :columns="columns"
    :rows="rows"
    :loading="loading"
    :refreshing="refreshing"
    :error="loadError"
    :page="page"
    :last-page="lastPage"
    :total="total"
    :per-page="perPage"
    :filters="filters"
    :filter-config="filterConfig"
    :total-clients="totalClients"
    :counters="counters"
    :last-good-at="lastValidAt"
    :data-origin="dataOrigin"
    :data-origin-label="dataOriginLabel"
    :source-label="sourceLabel"
    :as-of="asOf"
    :surface-summary="surface"
    :sorting="sorting"
    :get-row-id="getRowId"
    :get-client-id="row => row.client_id"
    :submodule="submodule"
    :horizontal-scroll="false"
    :empty-title="emptyTitle"
    :empty-kind="emptyKind"
    :column-labels="columnLabels"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="sorting = $event"
    @quick-filter-change="applyQuickFilters"
    @apply-filters="applyFilters"
    @reset-filters="resetFilters"
    @refresh="refreshAll"
  >
    <template #submodules>
      <div
        class="flex w-full min-w-0 max-w-full flex-col gap-2 sm:flex-row sm:items-center"
        data-testid="declarations-obligation-control"
      >
        <div class="w-full min-w-0 flex-1">
          <ShellScrollableTabs
            v-model="submodule"
            :items="tabItems"
            size="md"
            class="w-full min-w-0 max-w-full"
            aria-label="Filtrar por declaração"
            test-id="declarations-submodule-tabs"
          />
        </div>
        <UButton
          v-if="!isFgts && !isDirf"
          color="primary"
          variant="soft"
          icon="i-lucide-list-filter"
          label="Operações"
          :loading="declarationCatalogLoading"
          :disabled="Boolean(declarationCatalogError) || activeOperations.length === 0"
          class="shrink-0 self-end sm:self-auto"
          data-testid="declarations-operations-open"
          @click="openOperations()"
        />
      </div>
    </template>

    <template #utilities>
      <UAlert
        v-if="declarationCatalogError && !isFgts && !isDirf"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="declarationCatalogError"
        class="w-full"
      />
      <UAlert
        v-if="overviewError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="overviewError"
        class="w-full"
      />
      <UAlert
        v-if="isDirf"
        color="neutral"
        variant="subtle"
        icon="i-lucide-ban"
        title="DIRF não suportada"
        description="Não há catálogo nem integração SERPRO para DIRF nesta superfície. A carteira permanece vazia sem dados inventados."
        class="w-full"
        data-testid="declarations-dirf-unsupported"
      />
      <UAlert
        v-else-if="isFgts"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Cobertura parcial FGTS"
        description="Esta aba lista status FGTS já observados. Guia e pagamento produtivos não são inventados aqui."
        class="w-full"
        data-testid="declarations-fgts-partial"
      />
    </template>
  </MonitoringModuleTable>

  <MonitoringPgdasdDasHistoryModal
    v-if="isPgdas"
    v-model:open="pgdasHistoryOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
  />
  <MonitoringDctfwebHistoryModal
    v-if="isDctfweb"
    v-model:open="dctfwebHistoryOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
  />
  <MonitoringDefisDeclarationsModal
    v-if="isDefis"
    v-model:open="defisHistoryOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
  />
  <MonitoringMeiPublicServicesModal
    v-if="isDasnSimei"
    v-model:open="dasnHistoryOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
    :can-refresh="canTriggerSync"
    initial-service="dasn"
  />
  <MonitoringMitListaApuracoesModal
    v-if="isMit"
    v-model:open="mitHistoryOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
    :can-consult="canTriggerSync"
  />

  <MonitoringDeclarationOperationModal
    v-model:open="operationModalOpen"
    :obligation="submodule"
    :operations="activeOperations"
    :initial-client-id="operationClientId"
    :initial-client-name="operationClientName"
    @executed="refreshAll"
  />

  <ClientsClientFormModal
    v-if="canManageClients"
    v-model:open="clientFormOpen"
    :client="formClient"
    :can-manage-credentials="canManageCredentials"
    :can-manage-clients="canManageClients"
    @saved="onClientFormSaved"
  />
</template>

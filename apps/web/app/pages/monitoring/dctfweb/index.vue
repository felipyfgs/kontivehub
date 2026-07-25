<script setup lang="ts">
/**
 * DCTFWeb / MIT — cápsulas independentes na mesma rota.
 * DCTFWeb: oito colunas fixas, comunicação template, histórico local.
 * MIT: renderer próprio; sem reutilizar colunas DCTFWeb.
 * Mutações fiscais (transmitir / encerrar / DARF) ficam fora da grade DCTFWeb.
 */
import type {
  DctfwebClientRow,
  MonitoringFilterConfig,
  PgdasdCommunicationPreference
} from '~/types/fiscal-modules'
import { DCTFWEB_TABS } from '~/types/fiscal-modules'
import { buildDctfwebColumns, buildMitColumns } from '~/utils/dctfweb-table'
import { dctfwebSummary, isDctfwebCapsule, isMitCapsule } from '~/utils/dctfweb'
import { MONITORING_SHARED_COLUMN_LABELS } from '~/utils/monitoring-table-columns'
import { apiErrorMessage } from '~/utils/api-error'
import { useDctfwebMonitoring } from '~/composables/useDctfwebMonitoring'

const { canManageClients, canTriggerSync } = useDashboard()
const api = useApi()
const toast = useToast()
const dctfwebMonitoring = useDctfwebMonitoring()

// Tab local (DCTFWEB default). URL permanece /monitoring/dctfweb.
const submodule = ref(normalizeMonitoringSubmodule('dctfweb', undefined))

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
} = useFiscalModulePortfolio('dctfweb', {
  submodule
})

const {
  formOpen: clientFormOpen,
  formClient,
  canManageCredentials,
  openEditClient,
  onFormSaved: onClientFormSaved
} = useMonitoringClientEdit(() => refresh())

const isDctfweb = computed(() => isDctfwebCapsule(submodule.value))
const isMit = computed(() => isMitCapsule(submodule.value))

const filterConfig = computed<MonitoringFilterConfig>(() => {
  if (isMit.value) {
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

function getRowId(row: DctfwebClientRow) {
  return `c:${row.client_id}`
}

const tabItems = DCTFWEB_TABS.map(t => ({ label: t.label, value: t.value }))

// —— Modais locais (histórico / prévia / rastreio / preferências) ——
const historyOpen = ref(false)
const previewOpen = ref(false)
const trackingOpen = ref(false)
const prefsOpen = ref(false)
const mitListOpen = ref(false)
const modalClientId = ref<number | null>(null)
const modalClientName = ref<string | null>(null)
const modalCnpjMasked = ref<string | null>(null)
const modalPreference = ref<PgdasdCommunicationPreference | null>(null)

function openFor(row: DctfwebClientRow, kind: 'history' | 'preview' | 'tracking' | 'prefs') {
  modalClientId.value = row.client_id
  modalClientName.value = row.legal_name || row.name || null
  modalCnpjMasked.value = row.cnpj_masked || null
  modalPreference.value = dctfwebSummary(row)?.communication || null
  historyOpen.value = kind === 'history'
  previewOpen.value = kind === 'preview'
  trackingOpen.value = kind === 'tracking'
  prefsOpen.value = kind === 'prefs'
}

// —— Envio automático (Switch da coluna Comunicação) ——
const toggleBusyClientIds = ref<Set<number>>(new Set())

async function onToggleAutomatic(row: DctfwebClientRow, value: boolean) {
  const preference = dctfwebSummary(row)?.communication
  if (!preference) return
  const next = new Set(toggleBusyClientIds.value)
  next.add(row.client_id)
  toggleBusyClientIds.value = next
  try {
    await dctfwebMonitoring.updatePreferences(row.client_id, {
      email_enabled: preference.email_enabled,
      whatsapp_enabled: preference.whatsapp_enabled,
      automatic_requested: value,
      lock_version: preference.lock_version
    })
    toast.add({
      title: value ? 'Envio automático ativado' : 'Envio automático desativado',
      description: 'A preferência foi registrada; o envio efetivo segue o kill-switch do provider.',
      color: 'success'
    })
    await refresh()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao atualizar a preferência de envio automático.'),
      color: 'error'
    })
  } finally {
    const cleared = new Set(toggleBusyClientIds.value)
    cleared.delete(row.client_id)
    toggleBusyClientIds.value = cleared
  }
}

const dctfwebColumns = computed(() => buildDctfwebColumns({
  onHistory: row => openFor(row, 'history'),
  onTracking: row => openFor(row, 'tracking'),
  onConfigure: row => openFor(row, 'prefs'),
  onSend: row => openFor(row, 'preview'),
  onToggleAutomatic: (row, value) => { void onToggleAutomatic(row, value) },
  onEditClient: canManageClients.value
    ? (row) => { void openEditClient(row.client_id) }
    : undefined,
  toggleBusyClientIds: toggleBusyClientIds.value,
  onExclude: (row) => {
    void (async () => {
      try {
        await api.fiscal.monitoringMembership.exclude({
          module: 'dctfweb',
          submodule: submodule.value,
          client_ids: [row.client_id]
        })
        toast.add({ title: 'Cliente removido do monitoramento', color: 'success' })
        await refresh()
      } catch (caught) {
        toast.add({
          title: apiErrorMessage(caught, 'Falha ao excluir do monitoramento.'),
          color: 'error'
        })
      }
    })()
  }
}))

const mitColumns = computed(() => buildMitColumns({
  onOpenClient: (row) => {
    navigateTo(`/monitoring/clients/${row.client_id}`)
  },
  onListApuracoes: (row) => {
    modalClientId.value = row.client_id
    modalClientName.value = row.legal_name || row.name || null
    modalCnpjMasked.value = row.cnpj_masked || null
    mitListOpen.value = true
  },
  onEditClient: canManageClients.value
    ? (row) => { void openEditClient(row.client_id) }
    : undefined
}))

const columns = computed(() => {
  if (isMit.value) return mitColumns.value
  return dctfwebColumns.value
})

watch(submodule, (next, prev) => {
  if (next === prev) return
  historyOpen.value = false
  previewOpen.value = false
  trackingOpen.value = false
  prefsOpen.value = false
  mitListOpen.value = false
  modalClientId.value = null
  setPage(1)
  resetFilters()
})
</script>

<template>
  <MonitoringModuleTable
    title="DCTFWeb"
    panel-id="monitoring-dctfweb"
    module-key="dctfweb"
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
    :selection-enabled="canManageClients"
    :initial-hidden-columns="['evidence', 'darf']"
    :horizontal-scroll="false"
    empty-title="Nenhum cliente"
    :column-labels="isMit
      ? {
        situation: 'Situação',
        period: 'Competência',
        closure: 'Encerramento',
        ...MONITORING_SHARED_COLUMN_LABELS,
        client: 'Cliente'
      }
      : {
        situation: 'Situação',
        last_declaration: 'Últ. Declaração',
        ...MONITORING_SHARED_COLUMN_LABELS,
        client: 'Cliente'
      }"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="sorting = $event"
    @quick-filter-change="applyQuickFilters"
    @apply-filters="applyFilters"
    @reset-filters="resetFilters"
    @refresh="refresh"
  >
    <template #submodules>
      <!-- Controle segmentado local; não compete com as tabs de rota acima. -->
      <div
        class="flex min-w-0 flex-col gap-2"
        data-testid="dctfweb-capsule-control"
      >
        <p class="text-xs font-medium text-muted">
          Declaração
        </p>
        <ShellScrollableTabs
          v-model="submodule"
          :items="tabItems"
          size="sm"
          color="primary"
          variant="pill"
          class="w-full min-w-0"
          aria-label="Declaração: DCTFWeb ou MIT"
          test-id="dctfweb-submodule-tabs"
        />
      </div>
    </template>

    <template
      v-if="overviewError"
      #utilities
    >
      <UAlert
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="overviewError"
        class="w-full"
      />
    </template>
  </MonitoringModuleTable>

  <MonitoringDctfwebHistoryModal
    v-if="isDctfweb"
    v-model:open="historyOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
  />
  <MonitoringPgdasdCommunicationModals
    v-if="isDctfweb"
    v-model:preview-open="previewOpen"
    v-model:tracking-open="trackingOpen"
    v-model:prefs-open="prefsOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :preference="modalPreference"
    context="DCTFWEB"
  />
  <MonitoringMitListaApuracoesModal
    v-if="isMit"
    v-model:open="mitListOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
    :can-consult="canTriggerSync"
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

<script setup lang="ts">
/** Carteira compartilhada pelas rotas fixas Simples Nacional (PGDAS-D) e MEI (PGMEI). */
import type {
  MonitoringFilterConfig,
  PgdasdCommunicationPreference,
  SimplesMeiClientRow
} from '~/types/fiscal-modules'
import { buildPgdasdColumns } from '~/utils/pgdasd-table'
import { buildPgmeiColumns } from '~/utils/pgmei-table'
import { pgdasdSummary } from '~/utils/pgdasd'
import { pgmeiSummary } from '~/utils/pgmei'
import { MONITORING_SHARED_COLUMN_LABELS } from '~/utils/monitoring-table-columns'
import { extractConsultRunRef } from '~/utils/fiscal-monitoring-run'
import { apiErrorMessage } from '~/utils/api-error'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = defineProps<{
  submodule: 'PGDASD' | 'PGMEI'
}>()

const { canManageClients, canTriggerSync } = useDashboard()

// A rota escolhe a cápsula; não há troca local entre PGDAS-D e PGMEI.
const submodule = toRef(props, 'submodule')

/**
 * Ano-calendário PGMEI fixo no ano corrente (sem seletor na UI).
 * O scheduler/backend cobrem outros anos; a carteira operacional mostra o ano atual.
 */
const pgmeiYear = computed(() => new Date().getFullYear())

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
  loadClients,
  applyFilters,
  applyQuickFilters,
  resetFilters
} = useFiscalModulePortfolio('simples_mei', {
  submodule,
  year: computed(() => isPgmeiCapsule(submodule.value) ? pgmeiYear.value : null)
})

const {
  formOpen: clientFormOpen,
  formClient,
  canManageCredentials,
  openEditClient,
  onFormSaved: onClientFormSaved
} = useMonitoringClientEdit(() => refresh())

const {
  pendingClientIds,
  track: trackConsultPending
} = useSimplesMeiConsultPending({
  // Silent: não liga `refreshing` da tabela inteira — só a linha fica em skeleton.
  onSettled: () => loadClients({ silent: true })
})

function isPgmeiCapsule(value: string | undefined | null): boolean {
  const s = String(value || '').toLowerCase()
  return s === 'pgmei' || s === 'mei'
}

const isPgdasd = computed(() => {
  const s = String(submodule.value || '').toLowerCase()
  return s === 'pgdasd' || s === 'pgdas-d' || s === 'simples'
})

const isPgmei = computed(() => isPgmeiCapsule(submodule.value))
const title = computed(() => isPgmei.value ? 'MEI' : 'Simples Nacional')
const panelId = computed(() => isPgmei.value ? 'monitoring-mei' : 'monitoring-simples')
/** Prefixo estável de data-testid (não acoplado ao slug da URL). */
const testIdPrefix = computed(() => isPgmei.value ? 'mei' : 'simples-mei')

/**
 * Simples Nacional (PGDASD): Situação · Cliente · Competência · Envio no popover
 * (Situação sincroniza com as KPIs). MEI permanece só com Cliente.
 */
const filterConfig = computed<MonitoringFilterConfig>(() => {
  if (isPgmei.value) {
    return {
      fields: [
        { key: 'clientId', kind: 'client', label: 'Cliente' }
      ]
    }
  }
  return {
    fields: [
      { key: 'situation', kind: 'option', label: 'Situação' },
      { key: 'clientId', kind: 'client', label: 'Cliente' },
      { key: 'competence', kind: 'month', label: 'Competência' },
      {
        key: 'sendStatus',
        kind: 'option',
        label: 'Envio',
        items: [
          { label: 'Enviado', value: 'sent' },
          { label: 'Não enviado', value: 'not_sent' }
        ]
      }
    ]
  }
})

function getRowId(row: SimplesMeiClientRow) {
  return `c:${row.client_id}`
}

// —— Seleção (bulk no cabeçalho Enviar) ——
// Seleção NÃO entra no computed de colunas: recriar colunas a cada toggle
// faz o UTable reemitir selection-change e estoura "Maximum recursive updates".
const moduleTableRef = ref<{ clearSelection: () => void } | null>(null)
const selectedClientIds = ref<number[]>([])
const selectedCount = ref(0)

function sameClientIds(a: number[], b: number[]) {
  if (a.length !== b.length) return false
  for (let i = 0; i < a.length; i += 1) {
    if (a[i] !== b[i]) return false
  }
  return true
}

function onSelectionChange(payload: { clientIds: number[], count: number }) {
  if (
    payload.count === selectedCount.value
    && sameClientIds(payload.clientIds, selectedClientIds.value)
  ) {
    return
  }
  selectedClientIds.value = payload.clientIds
  selectedCount.value = payload.count
}

function clearSelection() {
  moduleTableRef.value?.clearSelection()
  selectedClientIds.value = []
  selectedCount.value = 0
}

// —— Modais compartilhados por cápsula ——
const historyOpen = ref(false)
const previewOpen = ref(false)
const trackingOpen = ref(false)
const prefsOpen = ref(false)
const consultOpen = ref(false)
const consultKind = ref<'pgdasd' | 'pgmei'>('pgmei')
const publicServicesOpen = ref(false)
const publicServicesClientId = ref<number | null>(null)
const publicServicesClientName = ref<string | null>(null)
const publicServicesCnpjMasked = ref<string | null>(null)
const consultClientId = ref<number | null>(null)
const consultClientName = ref<string | null>(null)
const consultQuerying = ref(false)
const modalClientId = ref<number | null>(null)
const modalClientName = ref<string | null>(null)
const modalCnpjMasked = ref<string | null>(null)
const modalPreference = ref<PgdasdCommunicationPreference | null>(null)

const { requestConsult } = usePgmeiMonitoring()
const pgdasdMonitoring = usePgdasdMonitoring()
const pgmeiMonitoring = usePgmeiMonitoring()
const { enqueueReadUpdate } = useMonitoringActions('simples_mei')
const toast = useToast()
const api = useApi()
const membershipOpen = ref(false)

// —— Envio automático (Switch da coluna Comunicação) ——
const toggleBusyClientIds = ref<Set<number>>(new Set())

async function onToggleAutomatic(row: SimplesMeiClientRow, value: boolean) {
  const preference = isPgmei.value
    ? pgmeiSummary(row, pgmeiYear.value)?.communication
    : pgdasdSummary(row)?.communication
  if (!preference) return
  const next = new Set(toggleBusyClientIds.value)
  next.add(row.client_id)
  toggleBusyClientIds.value = next
  try {
    const body = {
      email_enabled: preference.email_enabled,
      whatsapp_enabled: preference.whatsapp_enabled,
      automatic_requested: value,
      lock_version: preference.lock_version
    }
    if (isPgmei.value) {
      await pgmeiMonitoring.updatePreferences(row.client_id, body)
    } else {
      await pgdasdMonitoring.updatePreferences(row.client_id, body)
    }
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

async function excludeFromMonitoring(clientIds: number[]) {
  if (!clientIds.length) return
  try {
    await api.fiscal.monitoringMembership.exclude({
      module: 'simples_mei',
      submodule: submodule.value,
      client_ids: clientIds
    })
    toast.add({ title: 'Cliente removido do monitoramento', color: 'success' })
    clearSelection()
    await refresh()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao excluir do monitoramento.'),
      color: 'error'
    })
  }
}

const excludeConfirmOpen = ref(false)
const excludePendingIds = ref<number[]>([])
const excludeBusy = ref(false)

function requestExcludeFromMonitoring(clientIds: number[]) {
  if (!clientIds.length) return
  excludePendingIds.value = [...clientIds]
  excludeConfirmOpen.value = true
}

async function confirmExcludeFromMonitoring() {
  if (excludeBusy.value || !excludePendingIds.value.length) return
  excludeBusy.value = true
  try {
    await excludeFromMonitoring(excludePendingIds.value)
    excludeConfirmOpen.value = false
    excludePendingIds.value = []
  } finally {
    excludeBusy.value = false
  }
}

function openFor(row: SimplesMeiClientRow, kind: 'history' | 'preview' | 'tracking' | 'prefs') {
  modalClientId.value = row.client_id
  modalClientName.value = row.legal_name || row.name || null
  modalCnpjMasked.value = row.cnpj_masked || null
  if (isPgmei.value) {
    modalPreference.value = pgmeiSummary(row, pgmeiYear.value)?.communication || null
  } else {
    modalPreference.value = pgdasdSummary(row)?.communication || null
  }
  historyOpen.value = kind === 'history'
  previewOpen.value = kind === 'preview'
  trackingOpen.value = kind === 'tracking'
  prefsOpen.value = kind === 'prefs'
}

function openPgdasdHistory(row: SimplesMeiClientRow) {
  void navigateTo(`/monitoring/clients/${row.client_id}/pgdasd`)
}

function openConsultConfirm(row: SimplesMeiClientRow, kind: 'pgdasd' | 'pgmei' = 'pgmei') {
  consultKind.value = kind
  consultClientId.value = row.client_id
  consultClientName.value = row.legal_name || row.name || `Cliente #${row.client_id}`
  consultOpen.value = true
}

async function confirmRowConsult() {
  if (consultKind.value === 'pgdasd') {
    await confirmPgdasdRowConsult()
    return
  }
  await confirmPgmeiConsult()
}

async function confirmPgdasdRowConsult() {
  if (consultQuerying.value || !consultClientId.value) return
  consultQuerying.value = true
  try {
    const run = await enqueueReadUpdate({ client_id: consultClientId.value })
    if (run) {
      const ref = extractConsultRunRef(run)
      if (ref) trackConsultPending([ref])
      consultOpen.value = false
      await refresh()
    }
  } finally {
    consultQuerying.value = false
  }
}

function openMeiPublicServices(clientId: number) {
  const row = rows.value.find(item => item.client_id === clientId)
  if (!row) return
  publicServicesClientId.value = row.client_id
  publicServicesClientName.value = row.legal_name || row.name || `Cliente #${row.client_id}`
  publicServicesCnpjMasked.value = row.cnpj_masked || null
  publicServicesOpen.value = true
}

async function confirmPgmeiConsult() {
  if (consultQuerying.value || !consultClientId.value) return
  consultQuerying.value = true
  try {
    const response = await requestConsult([consultClientId.value], pgmeiYear.value)
    const enqueued = (response.data || [])
      .map(run => extractConsultRunRef(run))
      .filter((entry): entry is { clientId: number, runId: number } => entry != null)
    if (enqueued.length) trackConsultPending(enqueued)
    toast.add({
      title: 'Consulta PGMEI solicitada.',
      description: `Ano-calendário ${pgmeiYear.value}. A atualização aparecerá após o processamento.`,
      color: 'success'
    })
    consultOpen.value = false
    void refresh()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível solicitar a consulta.'),
      color: 'error'
    })
  } finally {
    consultQuerying.value = false
  }
}

// Membership não passa pelo menu Ações da seleção (sem confirmação).

const pgdasdColumns = computed(() => buildPgdasdColumns({
  onHistory: openPgdasdHistory,
  onTracking: row => openFor(row, 'tracking'),
  onConfigure: row => openFor(row, 'prefs'),
  onSend: row => openFor(row, 'preview'),
  onToggleAutomatic: (row, value) => { void onToggleAutomatic(row, value) },
  onEditClient: canManageClients.value
    ? (row) => { void openEditClient(row.client_id) }
    : undefined,
  onConsult: row => openConsultConfirm(row, 'pgdasd'),
  onExclude: (row) => { requestExcludeFromMonitoring([row.client_id]) },
  canConsult: canTriggerSync.value,
  pendingClientIds: pendingClientIds.value,
  toggleBusyClientIds: toggleBusyClientIds.value
}))

const pgmeiColumns = computed(() => buildPgmeiColumns({
  year: pgmeiYear.value,
  onHistory: row => openFor(row, 'history'),
  onConsult: row => openConsultConfirm(row, 'pgmei'),
  onTracking: row => openFor(row, 'tracking'),
  onConfigure: row => openFor(row, 'prefs'),
  onPublicServices: row => openMeiPublicServices(row.client_id),
  onSend: row => openFor(row, 'preview'),
  onToggleAutomatic: (row, value) => { void onToggleAutomatic(row, value) },
  onEditClient: canManageClients.value
    ? (row) => { void openEditClient(row.client_id) }
    : undefined,
  onExclude: (row) => { requestExcludeFromMonitoring([row.client_id]) },
  canConsult: canTriggerSync.value,
  pendingClientIds: pendingClientIds.value,
  toggleBusyClientIds: toggleBusyClientIds.value
}))

const columns = computed(() => {
  if (isPgmei.value) return pgmeiColumns.value
  return pgdasdColumns.value
})

const columnLabels = computed<Record<string, string>>(() => {
  if (isPgmei.value) {
    return {
      situation: 'Situação',
      ...MONITORING_SHARED_COLUMN_LABELS,
      client: 'Cliente'
    }
  }
  return {
    situation: 'Situação',
    last_declaration: 'Declaração',
    rbt12: 'RBT12',
    ...MONITORING_SHARED_COLUMN_LABELS,
    client: 'Cliente'
  }
})

function onSortingUpdate(next: typeof sorting.value) {
  const current = sorting.value
  if (
    current.length === next.length
    && current.every((entry, i) => entry.id === next[i]?.id && entry.desc === next[i]?.desc)
  ) {
    return
  }
  sorting.value = next
}
</script>

<template>
  <MonitoringModuleTable
    ref="moduleTableRef"
    :title="title"
    :panel-id="panelId"
    module-key="simples_mei"
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
    :show-pending-search="false"
    :sorting="sorting"
    :get-row-id="getRowId"
    :get-client-id="row => row.client_id"
    :submodule="submodule"
    :selection-enabled="canManageClients"
    :custom-bulk-actions="true"
    :horizontal-scroll="false"
    empty-title="Nenhum cliente"
    :column-labels="columnLabels"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="onSortingUpdate"
    @quick-filter-change="applyQuickFilters"
    @apply-filters="applyFilters"
    @reset-filters="resetFilters"
    @refresh="refresh"
    @selection-change="onSelectionChange"
  >
    <!-- Associar via modal dedicado; Ações da seleção só consulta (com confirmação). -->
    <template #bulk-actions="{ selectedClientIds: ids, selectedCount: count, clearSelection: clear }">
      <div class="flex flex-wrap items-center gap-1.5">
        <UButton
          v-if="canManageClients"
          color="neutral"
          variant="outline"
          icon="i-lucide-user-plus"
          label="Associar clientes"
          aria-label="Associar clientes ao monitoramento"
          :ui="COMPACT_BUTTON_LABEL_UI"
          :data-testid="`${testIdPrefix}-associate-clients`"
          @click="() => { membershipOpen = true }"
        />
        <MonitoringPgdasdSelectionActions
          v-if="isPgdasd"
          :selected-client-ids="ids"
          :selected-count="count"
          :can-consult="canTriggerSync"
          @clear="clear"
          @refresh="refresh"
          @consult-enqueued="trackConsultPending"
        />
        <MonitoringPgmeiBulkActions
          v-else-if="isPgmei"
          :selected-client-ids="ids"
          :selected-count="count"
          :year="pgmeiYear"
          :can-use-public-services="canTriggerSync"
          @clear="clear"
          @refresh="refresh"
          @public-services="openMeiPublicServices"
          @consult-enqueued="trackConsultPending"
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
      >
        <template #actions>
          <UButton
            size="xs"
            color="neutral"
            variant="outline"
            label="Retry overview"
            @click="refresh"
          />
        </template>
      </UAlert>
    </template>
  </MonitoringModuleTable>

  <MonitoringPgdasdCommunicationModals
    v-if="isPgdasd"
    v-model:preview-open="previewOpen"
    v-model:tracking-open="trackingOpen"
    v-model:prefs-open="prefsOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :preference="modalPreference"
  />

  <MonitoringPgmeiHistoryModal
    v-if="isPgmei"
    v-model:open="historyOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :cnpj-masked="modalCnpjMasked"
    :year="pgmeiYear"
  />
  <MonitoringPgdasdCommunicationModals
    v-if="isPgmei"
    v-model:preview-open="previewOpen"
    v-model:tracking-open="trackingOpen"
    v-model:prefs-open="prefsOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :preference="modalPreference"
    context="PGMEI"
    :year="pgmeiYear"
  />
  <MonitoringMeiPublicServicesModal
    v-if="isPgmei"
    v-model:open="publicServicesOpen"
    :client-id="publicServicesClientId"
    :client-name="publicServicesClientName"
    :cnpj-masked="publicServicesCnpjMasked"
    :can-refresh="canTriggerSync"
  />

  <MonitoringAssociateMonitoringClientsModal
    v-model:open="membershipOpen"
    module-key="simples_mei"
    :submodule="submodule"
    :surface-label="isPgmei ? 'DAS MEI' : 'DAS do Simples'"
    @success="refresh"
  />

  <ClientsClientFormModal
    v-if="canManageClients"
    v-model:open="clientFormOpen"
    :client="formClient"
    :can-manage-credentials="canManageCredentials"
    :can-manage-clients="canManageClients"
    @saved="onClientFormSaved"
  />

  <ShellConfirmModal
    v-model:open="consultOpen"
    :title="consultKind === 'pgdasd' ? 'Confirmar consulta PGDAS-D' : 'Confirmar consulta de dívida ativa'"
    :description="consultKind === 'pgdasd'
      ? `A consulta PGDAS-D à SERPRO para ${consultClientName || 'o cliente'} é explícita e pode ser faturável.`
      : `A consulta à SERPRO para ${consultClientName || 'o cliente'}, ano ${pgmeiYear}, é explícita e pode ser faturável.`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    confirm-label="Confirmar consulta"
    confirm-icon="i-lucide-refresh-cw"
    :loading="consultQuerying"
    :confirm-test-id="consultKind === 'pgdasd' ? 'pgdasd-row-consult-confirm' : 'pgmei-consult-confirm'"
    @confirm="confirmRowConsult"
  >
    <template #body>
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Uma chamada por cliente"
      >
        <template #description>
          Abrir esta confirmação não consulta a SERPRO. A chamada ocorrerá somente ao confirmar.
        </template>
      </UAlert>
    </template>
  </ShellConfirmModal>

  <ShellConfirmModal
    v-model:open="excludeConfirmOpen"
    title="Excluir do monitoramento?"
    :description="excludePendingIds.length === 1
      ? 'O cliente deixará de aparecer nesta carteira. Você pode associá-lo de novo depois.'
      : `${excludePendingIds.length} clientes deixarão de aparecer nesta carteira. Você pode associá-los de novo depois.`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    tone="danger"
    confirm-label="Excluir do monitoramento"
    confirm-icon="i-lucide-user-minus"
    :loading="excludeBusy"
    :confirm-test-id="`${testIdPrefix}-exclude-confirm`"
    @confirm="confirmExcludeFromMonitoring"
  />
</template>

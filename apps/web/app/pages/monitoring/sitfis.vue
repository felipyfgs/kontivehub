<script setup lang="ts">
/**
 * Situação Fiscal (SITFIS) — carteira + idade/TTL + contagem de achados.
 * USlideover com findings/pendências normalizadas (sem JSON bruto).
 * Task 7.5 · deep-links /monitoring/clients/{id}/sitfis
 */
import type { FiscalFinding, FiscalPendingItem } from '~/types/api'
import type {
  MonitoringFilterConfig,
  PgdasdCommunicationPreference,
  SitfisClientRow,
  SitfisShowResponse
} from '~/types/fiscal-modules'
import { commercialBlockLabel } from '~/utils/monitor-commercial'
import {
  buildSitfisColumns,
  sitfisAgeLabel as ageLabel,
  sitfisDetailOf as detailOf
} from '~/utils/sitfis-table'
import { MONITORING_SHARED_COLUMN_LABELS } from '~/utils/monitoring-table-columns'
import { apiErrorMessage } from '~/utils/api-error'
import { useSitfisMonitoring } from '~/composables/useSitfisMonitoring'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { fiscalDocumentDownloadFilename } from '~/utils/authenticated-download'

const api = useApi()
const sitfisMonitoring = useSitfisMonitoring()
const { download: downloadAuthenticated, downloading: evidenceDownloadBusy } = useAuthenticatedDownload()
const { canManageClients, canTriggerSync } = useDashboard()
const toast = useToast()

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
  allowsDocument,
  sorting,
  setPage,
  setPerPage,
  refresh,
  applyFilters,
  applyQuickFilters,
  resetFilters
} = useFiscalModulePortfolio('sitfis')

const {
  formOpen: clientFormOpen,
  formClient,
  canManageCredentials,
  openEditClient,
  onFormSaved: onClientFormSaved
} = useMonitoringClientEdit(() => refresh())

const filterConfig: MonitoringFilterConfig = {
  fields: [
    { key: 'situation', kind: 'option', label: 'Situação' },
    { key: 'clientId', kind: 'client', label: 'Cliente' },
    {
      key: 'coverage',
      kind: 'option',
      label: 'Cobertura',
      items: fiscalCoverageFilterItems()
    }
  ]
}

function getRowId(row: SitfisClientRow) {
  return `c:${row.client_id}`
}

const slideOpen = ref(false)
const selected = ref<SitfisClientRow | null>(null)
const findings = ref<FiscalFinding[]>([])
const pending = ref<FiscalPendingItem[]>([])
const sitfisMeta = ref<SitfisShowResponse | null>(null)

function provenanceLabel(value?: string | null) {
  if (value === 'SERPRO_REAL') return 'Fonte SERPRO real'
  if (value === 'SERPRO_TRIAL') return 'Fonte SERPRO Trial'
  if (value === 'FIXTURE') return 'Fixture (desenvolvimento)'
  if (value === 'SIMULATED') return 'Simulado (desenvolvimento)'
  if (value === 'UNVERIFIED') return 'Não verificado (legado)'
  return null
}
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const detailRefreshing = ref(false)

function clientHref(id: number) {
  return `/monitoring/clients/${id}/sitfis`
}

function evidenceDownloadHref(meta: SitfisShowResponse | null): string | null {
  const fromLinks = meta?.links?.evidence_download?.trim()
  if (fromLinks) return fromLinks
  if (meta?.evidence_artifact_id != null) {
    return sitfisMonitoring.evidenceDownloadUrl(meta.evidence_artifact_id)
  }
  return null
}

async function downloadEvidence() {
  const href = evidenceDownloadHref(sitfisMeta.value)
  if (!href) return
  await downloadAuthenticated(href, 'relatorio-sitfis.pdf')
}

async function downloadRowDocument(row: SitfisClientRow) {
  const href = row.document?.href?.trim()
  if (!href) return
  await downloadAuthenticated(href, fiscalDocumentDownloadFilename({
    label: row.document?.label,
    kind: row.document?.kind
  }))
}

async function openDetail(row: SitfisClientRow) {
  selected.value = row
  slideOpen.value = true
  detailLoading.value = true
  detailError.value = null
  findings.value = []
  pending.value = []
  sitfisMeta.value = null
  try {
    const [findRes, pendRes, sitRes] = await Promise.allSettled([
      api.fiscal.findings({ client_id: row.client_id, per_page: 50, active_only: true }),
      api.fiscal.pending({ client_id: row.client_id, per_page: 50, status: 'OPEN' }),
      sitfisMonitoring.show(row.client_id)
    ])
    if (findRes.status === 'fulfilled') {
      findings.value = ((findRes.value as { data: FiscalFinding[] }).data) || []
    }
    if (pendRes.status === 'fulfilled') {
      pending.value = ((pendRes.value as { data: FiscalPendingItem[] }).data) || []
    }
    if (sitRes.status === 'fulfilled') {
      const view = sitRes.value
      const d = detailOf(row)
      const snap = view.snapshot as { observed_at?: string, coverage?: string, source_provenance?: string, verification_state?: string } | null | undefined
      sitfisMeta.value = {
        ...view,
        observed_at: view.observed_at || snap?.observed_at || d.observed_at || null,
        age_seconds: view.age_seconds ?? d.age_seconds ?? null,
        ttl_seconds: view.ttl_seconds ?? d.ttl_seconds ?? null,
        source_provenance: view.source_provenance || snap?.source_provenance || null,
        verification_state: view.verification_state || snap?.verification_state || null,
        coverage: String(view.coverage || snap?.coverage || row.coverage || '') || null
      }
    }
    if (
      findRes.status === 'rejected'
      && pendRes.status === 'rejected'
      && sitRes.status === 'rejected'
    ) {
      detailError.value = 'Falha ao carregar detalhe SITFIS.'
    }
  } finally {
    detailLoading.value = false
  }
}

const recentConfirmOpen = ref(false)

async function doRefreshSelected(force = false) {
  if (!selected.value || !canTriggerSync.value) return
  detailRefreshing.value = true
  try {
    const result = await sitfisMonitoring.refresh({
      client_id: selected.value.client_id,
      force
    })
    if (result.enqueued) {
      toast.add({ title: 'Atualização SITFIS solicitada', color: 'success' })
    } else {
      toast.add({
        title: 'Atualização não enfileirada',
        description: result.reason === 'WITHIN_TTL'
          ? 'Snapshot ainda dentro do TTL.'
          : result.reason === 'ALREADY_RUNNING'
            ? 'Já existe uma consulta em andamento.'
            : (result.reason || 'A API não enfileirou a atualização.'),
        color: 'warning'
      })
    }
    recentConfirmOpen.value = false
    await openDetail(selected.value)
    await refresh()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao solicitar refresh SITFIS. Verifique procuração e franquia.'),
      color: 'error'
    })
  } finally {
    detailRefreshing.value = false
  }
}

async function refreshSelected() {
  if (!selected.value || !canTriggerSync.value) return
  const recent = selected.value.is_recent_snapshot
    || sitfisMeta.value?.is_within_ttl === true
  if (recent) {
    recentConfirmOpen.value = true
    return
  }
  await doRefreshSelected(false)
}

// —— Comunicação (padrão DCTFWeb) ——
const previewOpen = ref(false)
const trackingOpen = ref(false)
const prefsOpen = ref(false)
const modalClientId = ref<number | null>(null)
const modalClientName = ref<string | null>(null)
const modalPreference = ref<PgdasdCommunicationPreference | null>(null)
const toggleBusyClientIds = ref<Set<number>>(new Set())

function openCommunication(row: SitfisClientRow, kind: 'preview' | 'tracking' | 'prefs') {
  const communication = detailOf(row).communication
  if (!communication) return
  modalClientId.value = row.client_id
  modalClientName.value = row.legal_name || row.name || null
  modalPreference.value = communication
  previewOpen.value = kind === 'preview'
  trackingOpen.value = kind === 'tracking'
  prefsOpen.value = kind === 'prefs'
}

async function onSitfisToggleAutomatic(row: SitfisClientRow, value: boolean) {
  const preference = detailOf(row).communication
  if (!preference) return
  const next = new Set(toggleBusyClientIds.value)
  next.add(row.client_id)
  toggleBusyClientIds.value = next
  try {
    await sitfisMonitoring.updatePreferences(row.client_id, {
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

const columns = computed(() => buildSitfisColumns({
  allowsDocument: allowsDocument.value,
  onFindings: openDetail,
  onTracking: row => openCommunication(row, 'tracking'),
  onSend: row => openCommunication(row, 'preview'),
  onDocument: (row) => { void downloadRowDocument(row) },
  onToggleAutomatic: (row, value) => { void onSitfisToggleAutomatic(row, value) },
  onEditClient: canManageClients.value
    ? (row) => { void openEditClient(row.client_id) }
    : undefined
}))
</script>

<template>
  <MonitoringModuleTable
    title="Situação Fiscal"
    panel-id="monitoring-sitfis"
    module-key="sitfis"
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
    :horizontal-scroll="false"
    :initial-hidden-columns="['procuracao', 'franchise', 'age']"
    empty-title="Nenhum cliente"
    :column-labels="{
      situation: 'Situação',
      findings: 'Achados',
      coverage: 'Cobertura',
      actions: 'Ações',
      client: 'Cliente',
      procuracao: 'Procuração',
      franchise: 'Franquia / agenda',
      age: 'Idade / TTL',
      ...MONITORING_SHARED_COLUMN_LABELS
    }"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="sorting = $event"
    @quick-filter-change="applyQuickFilters"
    @apply-filters="applyFilters"
    @reset-filters="resetFilters"
    @refresh="refresh"
  >
    <template #utilities>
      <UAlert
        v-if="overviewError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="overviewError"
        class="w-full"
      />
      <MonitoringManualConsultCta
        v-if="selected?.client_id"
        :client-id="selected.client_id"
        module-key="sitfis"
        surface-key="sitfis"
        preferred-action-id="sitfis:sitfis.solicitar_protocolo"
        label="Consulta SITFIS"
        @refresh="refresh"
      />
    </template>

    <template #detail>
      <USlideover
        v-model:open="slideOpen"
        :title="selected
          ? (selected.name || selected.legal_name || `Cliente #${selected.client_id}`)
          : 'Detalhe SITFIS'"
      >
        <template #body>
          <div
            v-if="detailLoading"
            class="py-8 text-sm text-muted"
          >
            Carregando achados…
          </div>
          <UAlert
            v-else-if="detailError"
            color="error"
            :title="detailError"
          />
          <div
            v-else
            class="flex flex-col gap-4"
          >
            <div class="flex flex-wrap items-center gap-2">
              <FiscalStatusBadge
                v-if="selected"
                :status="selected.situation"
                show-hint
              />
              <span class="text-sm text-muted">
                Idade: {{ ageLabel(sitfisMeta?.age_seconds ?? (selected ? detailOf(selected).age_seconds : null)) }}
                · TTL: {{ ageLabel(sitfisMeta?.ttl_seconds ?? (selected ? detailOf(selected).ttl_seconds : null)) }}
              </span>
              <UBadge
                v-if="provenanceLabel(sitfisMeta?.source_provenance)"
                :color="sitfisMeta?.source_provenance === 'SERPRO_REAL' ? 'success' : 'warning'"
                variant="subtle"
                size="sm"
              >
                {{ provenanceLabel(sitfisMeta?.source_provenance) }}
              </UBadge>
              <UBadge
                v-if="sitfisMeta?.block_reason === 'RUN_IN_PROGRESS'"
                color="info"
                variant="subtle"
                size="sm"
              >
                Atualização em processamento
              </UBadge>
              <UButton
                v-if="canTriggerSync && selected && sitfisMeta?.can_refresh !== false"
                size="xs"
                color="neutral"
                variant="outline"
                icon="i-lucide-refresh-cw"
                label="Solicitar atualização"
                :loading="detailRefreshing"
                data-testid="sitfis-request-refresh"
                @click="refreshSelected()"
              />
              <MonitoringManualConsultCta
                v-if="selected?.client_id"
                :client-id="selected.client_id"
                module-key="sitfis"
                surface-key="sitfis"
                preferred-action-id="sitfis:sitfis.solicitar_protocolo"
                label="Consulta manual"
                size="xs"
                @refresh="() => { void openDetail(selected!); void refresh() }"
              />
              <span
                v-else-if="sitfisMeta?.block_reason === 'WITHIN_TTL' && sitfisMeta?.next_refresh_at"
                class="text-xs text-muted"
              >
                Dados recentes · próxima atualização a partir de {{ formatDateTime(sitfisMeta.next_refresh_at) }}
              </span>
              <UAlert
                v-if="selected?.block_message || selected?.block_reason"
                color="warning"
                icon="i-lucide-triangle-alert"
                class="w-full"
                :title="selected.block_message || commercialBlockLabel(selected.block_reason) || 'Bloqueio operacional — revise e-CAC ou franquia'"
              />
              <FiscalDocumentAction
                v-if="selected"
                :document="selected.document"
                :disabled="!allowsDocument"
              />
              <UButton
                v-if="evidenceDownloadHref(sitfisMeta)"
                size="xs"
                color="neutral"
                variant="outline"
                icon="i-lucide-file-down"
                label="Baixar relatório"
                :loading="evidenceDownloadBusy"
                data-testid="sitfis-evidence-download"
                @click="downloadEvidence"
              />
              <UButton
                v-if="selected"
                size="xs"
                color="neutral"
                variant="ghost"
                label="Painel do cliente"
                :to="clientHref(selected.client_id)"
              />
            </div>

            <UPageCard
              v-if="sitfisMeta || selected"
              variant="subtle"
              title="Resumo do snapshot"
            >
              <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <dt class="text-muted">
                    Observado em
                  </dt>
                  <dd>
                    {{ formatDateTime(String(sitfisMeta?.observed_at || (selected ? detailOf(selected).observed_at : '') || '') || null) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Expira em
                  </dt>
                  <dd>
                    {{ formatDateTime(String(sitfisMeta?.expires_at || '') || null) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Dentro do TTL
                  </dt>
                  <dd>
                    <template v-if="sitfisMeta?.is_within_ttl === true">
                      Sim
                    </template>
                    <template v-else-if="sitfisMeta?.is_within_ttl === false">
                      Não
                    </template>
                    <template v-else>
                      —
                    </template>
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Cobertura
                  </dt>
                  <dd>
                    <FiscalCoverageBadge
                      :coverage="String(sitfisMeta?.coverage || selected?.coverage || '')"
                    />
                  </dd>
                </div>
              </dl>
              <p
                v-if="sitfisMeta?.disclaimer"
                class="mt-3 text-xs text-muted"
              >
                {{ sitfisMeta.disclaimer }}
              </p>
            </UPageCard>

            <div>
              <h3 class="mb-2 text-sm font-medium">
                Findings ativos
              </h3>
              <div
                v-if="!findings.length"
                class="text-sm text-muted"
              >
                Nenhum finding ativo retornado.
              </div>
              <ul
                v-else
                class="divide-y divide-default"
              >
                <li
                  v-for="f in findings"
                  :key="f.id"
                  class="flex items-start justify-between gap-3 py-3 text-sm"
                >
                  <div class="min-w-0">
                    <p class="font-medium text-highlighted">
                      {{ f.title || f.code || `Finding #${f.id}` }}
                    </p>
                    <p class="text-xs text-muted">
                      {{ f.detail || '—' }}
                    </p>
                  </div>
                  <FiscalStatusBadge :status="f.situation || f.severity" />
                </li>
              </ul>
            </div>

            <div>
              <h3 class="mb-2 text-sm font-medium">
                Pendências abertas
              </h3>
              <div
                v-if="!pending.length"
                class="text-sm text-muted"
              >
                Nenhuma pendência aberta retornada.
              </div>
              <ul
                v-else
                class="divide-y divide-default"
              >
                <li
                  v-for="p in pending"
                  :key="p.id"
                  class="flex items-start justify-between gap-3 py-3 text-sm"
                >
                  <div class="min-w-0">
                    <p class="font-medium text-highlighted">
                      {{ p.title || p.code || `Pendência #${p.id}` }}
                    </p>
                    <p class="text-xs text-muted">
                      Venc.: {{ formatDateTime(p.due_at) }} · {{ p.detail || '—' }}
                    </p>
                  </div>
                  <FiscalStatusBadge :status="p.situation || p.status" />
                </li>
              </ul>
            </div>
          </div>
        </template>
      </USlideover>
    </template>
  </MonitoringModuleTable>

  <MonitoringRecentRefreshConfirmModal
    v-model:open="recentConfirmOpen"
    :last-at="sitfisMeta?.observed_at || selected?.last_snapshot_at || selected?.last_consulted_at"
    :remaining="selected?.commercial_quota?.remaining"
    :loading="detailRefreshing"
    @confirm="doRefreshSelected(true)"
  />

  <MonitoringPgdasdCommunicationModals
    v-model:preview-open="previewOpen"
    v-model:tracking-open="trackingOpen"
    v-model:prefs-open="prefsOpen"
    :client-id="modalClientId"
    :client-name="modalClientName"
    :preference="modalPreference"
    context="SITFIS"
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

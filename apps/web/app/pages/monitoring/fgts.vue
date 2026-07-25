<script setup lang="ts">
/**
 * FGTS / eSocial — eventos oficiais pelo eSocial BX, com limites e readiness explícitos.
 * Guia e pagamento permanecem independentes até o provider FGTS Digital estar habilitado.
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type {
  FgtsCoverageManifest,
  FgtsDigitalCoverage,
  FgtsDigitalPreviewResponse,
  FgtsDigitalReadiness,
  FgtsDigitalRun,
  FgtsEsocialReadiness
} from '~/types/api'
import type { FgtsClientDetail, FgtsClientRow, MonitoringFilterConfig } from '~/types/fiscal-modules'
import { sortHeader } from '~/utils/table-sort'
import { pgdasdCanRequestAutomatic, pgdasdTrackingMeta } from '~/utils/pgdasd'
import {
  buildMonitoringActionsMenuCell,
  buildMonitoringConsultedColumn,
  buildMonitoringComunicacaoColumn,
  MONITORING_ACTIONS_LABEL,
  MONITORING_ACTIONS_META,
  MONITORING_CLIENT_COLUMN_META,
  MONITORING_SHARED_COLUMN_LABELS,
  type MonitoringSendColumnState
} from '~/utils/monitoring-table-columns'

const FiscalStatusBadge = resolveComponent('FiscalStatusBadge')
const FiscalClientCell = resolveComponent('FiscalClientCell')

const api = useApi()
const { canManageClients } = useDashboard()

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
} = useFiscalModulePortfolio('fgts')

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
    { key: 'competence', kind: 'month', label: 'Competência' }
  ]
}

function getRowId(row: FgtsClientRow) {
  return `c:${row.client_id}`
}

const coverage = ref<FgtsCoverageManifest | null>(null)
const coverageError = ref<string | null>(null)
const digitalCoverage = ref<FgtsDigitalCoverage | null>(null)
const digitalCoverageError = ref<string | null>(null)

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const detailStatus = ref<Record<string, unknown> | null>(null)
const detailEvents = ref<Array<Record<string, unknown>>>([])
const detailDivergences = ref<Array<{ code?: string, title?: string, detail?: string, severity?: string, situation?: string }>>([])
const detailClient = ref<FgtsClientRow | null>(null)
const detailReadiness = ref<FgtsEsocialReadiness | null>(null)
const detailDigitalReadiness = ref<FgtsDigitalReadiness | null>(null)
const detailDigitalRuns = ref<FgtsDigitalRun[]>([])
const detailDigitalGuides = ref<Array<Record<string, unknown>>>([])
const digitalActionLoading = ref(false)
const digitalActionError = ref<string | null>(null)
const digitalActionSuccess = ref<string | null>(null)
const digitalPreviewOpen = ref(false)
const digitalPreviewLoading = ref(false)
const digitalPreviewError = ref<string | null>(null)
const digitalPreview = ref<FgtsDigitalPreviewResponse | null>(null)
const digitalConfirmation = ref('')
const digitalForm = reactive({
  guideType: 'MONTHLY',
  competence: '',
  amount: '',
  debitIds: ''
})
const digitalGuideTypes = [
  { label: 'Mensal', value: 'MONTHLY' },
  { label: 'Rescisória', value: 'TERMINATION' },
  { label: 'Consignado', value: 'CONSIGNMENT' },
  { label: 'Mista', value: 'MIXED' },
  { label: 'Parametrizada', value: 'PARAMETERIZED' }
]
const communicationPreviewOpen = ref(false)
const communicationTrackingOpen = ref(false)
const communicationPreferencesOpen = ref(false)
const communicationRow = ref<FgtsClientRow | null>(null)

function clientHref(id: number) {
  return `/monitoring/clients/${id}/fgts`
}

function detailOf(row: FgtsClientRow): FgtsClientDetail {
  return row.detail || {}
}

function parseStatusId(row: FgtsClientRow): number | null {
  const link = detailOf(row).links?.status
  if (!link) return null
  const m = String(link).match(/\/competences\/(\d+)/)
  return m ? Number(m[1]) : null
}

async function loadCoverage() {
  const [esocial, portal] = await Promise.allSettled([
    api.fiscal.fgts.coverage(),
    api.fiscal.fgts.digital.coverage()
  ])
  coverage.value = esocial.status === 'fulfilled' ? esocial.value.data : null
  coverageError.value = esocial.status === 'rejected'
    ? apiErrorMessage(esocial.reason, 'Falha ao carregar manifesto de cobertura FGTS.')
    : null
  digitalCoverage.value = portal.status === 'fulfilled' ? portal.value.data : null
  digitalCoverageError.value = portal.status === 'rejected'
    ? apiErrorMessage(portal.reason, 'Falha ao carregar cobertura do portal FGTS Digital.')
    : null
}

async function loadDigitalDetail(clientId: number) {
  const [readiness, runs, guides] = await Promise.allSettled([
    api.fiscal.fgts.digital.readiness(clientId),
    api.fiscal.fgts.digital.runs({ client_id: clientId, per_page: 10 }),
    api.fiscal.guides.list({ client_id: clientId, per_page: 50 })
  ])
  detailDigitalReadiness.value = readiness.status === 'fulfilled' ? readiness.value.data : null
  detailDigitalRuns.value = runs.status === 'fulfilled' ? runs.value.data : []
  detailDigitalGuides.value = guides.status === 'fulfilled'
    ? guides.value.data.filter(item => String(item.source || '') === 'FGTS_DIGITAL_PORTAL')
    : []
  if (readiness.status === 'rejected') {
    digitalActionError.value = apiErrorMessage(readiness.reason, 'Falha ao consultar readiness do portal.')
  }
}

async function openDetail(row: FgtsClientRow) {
  detailClient.value = row
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null
  detailStatus.value = null
  detailEvents.value = []
  detailDivergences.value = []
  detailReadiness.value = null
  detailDigitalReadiness.value = null
  detailDigitalRuns.value = []
  detailDigitalGuides.value = []
  digitalActionError.value = null
  digitalActionSuccess.value = null
  const digitalDetail = loadDigitalDetail(row.client_id)

  const d = detailOf(row)
  try {
    let statusId = parseStatusId(row)
    if (!statusId) {
      const listRes = await api.fiscal.fgts.competences({
        client_id: row.client_id,
        competence_period_key: d.competence_period_key || filters.value.competence || undefined,
        per_page: 5
      })
      const first = (listRes.data || [])[0] as { id?: number } | undefined
      statusId = first?.id ? Number(first.id) : null
    }

    if (!statusId) {
      detailDivergences.value.push({
        code: 'ESOCIAL_COMPETENCE_NOT_FOUND',
        title: 'Sem competência eSocial',
        detail: 'O portal FGTS Digital continua disponível abaixo, mas não há competência eSocial local para detalhar.'
      })
      return
    }

    const [statusRes, findingsRes, readinessRes] = await Promise.allSettled([
      api.fiscal.fgts.competence(statusId),
      api.fiscal.findings({ client_id: row.client_id, per_page: 50, active_only: true }),
      api.fiscal.fgts.readiness(row.client_id)
    ])

    if (statusRes.status === 'fulfilled') {
      detailStatus.value = (statusRes.value.data || {}) as Record<string, unknown>
      detailEvents.value = (statusRes.value.events || []) as Array<Record<string, unknown>>
      const lim = detailStatus.value.limitations
      if (Array.isArray(lim)) {
        detailDivergences.value = lim.map((item) => {
          if (item && typeof item === 'object' && !Array.isArray(item)) {
            const o = item as Record<string, unknown>
            return {
              code: o.code != null ? String(o.code) : undefined,
              title: o.title != null ? String(o.title) : (o.code != null ? String(o.code) : 'Limitação'),
              detail: o.detail != null ? String(o.detail) : (o.message != null ? String(o.message) : String(item)),
              severity: o.severity != null ? String(o.severity) : undefined,
              situation: o.situation != null ? String(o.situation) : undefined
            }
          }
          return {
            title: 'Limitação de cobertura',
            detail: String(item)
          }
        })
      }
    } else {
      detailError.value = apiErrorMessage(statusRes.reason, 'Falha ao carregar competência FGTS.')
    }

    if (findingsRes.status === 'fulfilled') {
      const finds = findingsRes.value.data || []
      const esocial = finds.filter((f) => {
        const code = String(f.code || '').toUpperCase()
        return code.startsWith('ESOCIAL') || code.includes('TOTALIZER') || code.includes('FGTS')
      })
      for (const f of esocial) {
        detailDivergences.value.push({
          code: f.code != null ? String(f.code) : undefined,
          title: String(f.title || f.code || 'Divergência'),
          detail: f.detail != null ? String(f.detail) : undefined,
          severity: f.severity != null ? String(f.severity) : undefined,
          situation: f.situation != null ? String(f.situation) : undefined
        })
      }
    }

    if (readinessRes.status === 'fulfilled') {
      detailReadiness.value = readinessRes.value.data
    }
  } catch (caught) {
    detailError.value = apiErrorMessage(caught, 'Falha ao carregar detalhe FGTS/eSocial.')
  } finally {
    await digitalDetail
    detailLoading.value = false
  }
}

async function syncDigitalGuides() {
  if (!detailClient.value) return
  digitalActionLoading.value = true
  digitalActionError.value = null
  digitalActionSuccess.value = null
  try {
    const response = await api.fiscal.fgts.digital.sync({ client_id: detailClient.value.client_id })
    digitalActionSuccess.value = `Consulta enfileirada (run #${response.data.id}).`
    await loadDigitalDetail(detailClient.value.client_id)
  } catch (caught) {
    digitalActionError.value = apiErrorMessage(caught, 'Não foi possível enfileirar a consulta ao portal.')
  } finally {
    digitalActionLoading.value = false
  }
}

function openDigitalPreview() {
  const competence = String(detailStatus.value?.competence_period_key || filters.value.competence || '')
  digitalForm.competence = /^\d{4}-\d{2}$/.test(competence) ? competence : ''
  digitalForm.guideType = 'MONTHLY'
  digitalForm.amount = ''
  digitalForm.debitIds = ''
  digitalPreview.value = null
  digitalPreviewError.value = null
  digitalConfirmation.value = ''
  digitalPreviewOpen.value = true
}

function closeDigitalPreview(): void {
  digitalPreviewOpen.value = false
}

function amountToCents(value: string): number | undefined {
  const normalized = value.trim().replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '')
  if (!normalized) return undefined
  const amount = Number(normalized)
  return Number.isFinite(amount) && amount >= 0 ? Math.round(amount * 100) : undefined
}

async function requestDigitalPreview() {
  if (!detailClient.value) return
  digitalPreviewLoading.value = true
  digitalPreviewError.value = null
  try {
    const debitIds = digitalForm.debitIds.split(/[,\n]/).map(value => value.trim()).filter(Boolean)
    const amountCents = amountToCents(digitalForm.amount)
    const response = await api.fiscal.fgts.digital.preview({
      client_id: detailClient.value.client_id,
      guide_type: digitalForm.guideType,
      parameters: {
        competence_period_key: digitalForm.competence,
        ...(amountCents == null ? {} : { amount_cents: amountCents }),
        ...(debitIds.length ? { debit_ids: debitIds } : {})
      }
    })
    digitalPreview.value = response.data
    digitalConfirmation.value = ''
  } catch (caught) {
    digitalPreviewError.value = apiErrorMessage(caught, 'Não foi possível gerar a prévia no portal.')
  } finally {
    digitalPreviewLoading.value = false
  }
}

async function authorizeDigitalEmission() {
  const preview = digitalPreview.value
  if (!preview?.preview_token || !preview.run.confirmation_phrase || !detailClient.value) return
  if (digitalConfirmation.value.trim().toLocaleLowerCase('pt-BR')
    !== preview.run.confirmation_phrase.trim().toLocaleLowerCase('pt-BR')) {
    digitalPreviewError.value = 'Digite exatamente a frase de confirmação exibida na prévia.'
    return
  }
  digitalPreviewLoading.value = true
  digitalPreviewError.value = null
  try {
    const response = await api.fiscal.fgts.digital.emit(preview.run.id, {
      preview_token: preview.preview_token,
      confirmation_phrase: digitalConfirmation.value
    })
    digitalActionSuccess.value = response.data.reused
      ? `Guia equivalente reutilizada (run #${response.data.run.id}).`
      : `Emissão autorizada e enfileirada (run #${response.data.run.id}).`
    digitalPreviewOpen.value = false
    await loadDigitalDetail(detailClient.value.client_id)
  } catch (caught) {
    digitalPreviewError.value = apiErrorMessage(caught, 'A autorização da emissão foi recusada.')
  } finally {
    digitalPreviewLoading.value = false
  }
}

async function downloadDigitalGuide(guide: Record<string, unknown>) {
  const id = Number(guide.id || 0)
  if (!id) return
  digitalActionLoading.value = true
  digitalActionError.value = null
  try {
    const response = await api.fiscal.guides.issueDownloadToken(id)
    window.open(api.fiscal.guides.downloadUrl(response.data.token), '_blank', 'noopener,noreferrer')
  } catch (caught) {
    digitalActionError.value = apiErrorMessage(caught, 'Não foi possível baixar o PDF protegido.')
  } finally {
    digitalActionLoading.value = false
  }
}

function digitalGuideHasDocument(guide: Record<string, unknown>): boolean {
  const version = guide.current_version
  return Boolean(
    version
    && typeof version === 'object'
    && !Array.isArray(version)
    && (version as Record<string, unknown>).has_document
  )
}

function digitalGuideAmount(guide: Record<string, unknown>): string {
  const cents = Number(guide.amount_cents)
  if (!Number.isFinite(cents)) return 'Valor não informado'
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100)
}

function onFgtsTracking(row: FgtsClientRow) {
  communicationRow.value = row
  communicationTrackingOpen.value = true
}

function onFgtsSend(row: FgtsClientRow) {
  communicationRow.value = row
  communicationPreviewOpen.value = true
}

function onFgtsToggleAutomatic(row: FgtsClientRow) {
  communicationRow.value = row
  communicationPreferencesOpen.value = true
}

function fgtsSendState(row: FgtsClientRow): MonitoringSendColumnState {
  const communication = detailOf(row).communication
  const trackingStatus = communication?.tracking_status
    || (detailOf(row).guide_status === 'UNSUPPORTED' ? 'SKIPPED_NO_DOCUMENT' : null)
  const tracking = pgdasdTrackingMeta(trackingStatus)
  return {
    trackingIcon: tracking.icon,
    trackingLabel: communication || detailOf(row).guide_status === 'UNSUPPORTED'
      ? tracking.label
      : 'Sem histórico local',
    trackingColor: communication || detailOf(row).guide_status === 'UNSUPPORTED'
      ? tracking.color
      : 'neutral',
    trackingDisabled: !communication,
    automaticRequested: communication?.automatic_requested === true,
    canToggleAutomatic: pgdasdCanRequestAutomatic(communication),
    canSend: communication?.can_send === true
  }
}

function fgtsActionItems(row: FgtsClientRow): DropdownMenuItem[][] {
  const items: DropdownMenuItem[] = [
    {
      label: 'Abrir cliente',
      icon: 'i-lucide-building-2',
      to: clientHref(row.client_id)
    }
  ]
  if (canManageClients.value) {
    items.push({
      label: 'Editar cliente',
      icon: 'i-lucide-pencil',
      onSelect: () => { void openEditClient(row.client_id) }
    })
  }
  items.push({
    label: 'Ver detalhe FGTS',
    icon: 'i-lucide-panel-right',
    onSelect: () => openDetail(row)
  })
  return [items]
}

const columns = computed<TableColumn<FgtsClientRow>[]>(() => [
  {
    id: 'situation',
    header: ({ column }) => sortHeader('Situação', column),
    enableSorting: false,
    meta: { class: { th: 'w-28 min-w-24', td: 'w-28 min-w-24' } },
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(row.original.situation || 'UNKNOWN') })
  },
  {
    id: 'client',
    header: ({ column }) => sortHeader('Cliente', column),
    enableHiding: false,
    meta: { ...MONITORING_CLIENT_COLUMN_META },
    cell: ({ row }) => h(FiscalClientCell, {
      clientId: row.original.client_id,
      name: row.original.name || row.original.display_name,
      legalName: row.original.legal_name,
      cnpj: row.original.cnpj,
      cnpjMasked: row.original.cnpj_masked,
      to: clientHref(row.original.client_id)
    })
  },
  {
    id: 'competence',
    header: ({ column }) => sortHeader('Competência', column),
    cell: ({ row }) => String(
      row.original.competence
      || detailOf(row.original).competence_period_key
      || '—'
    )
  },
  {
    id: 'closure',
    header: 'Fechamento',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(detailOf(row.original).closure_status || row.original.situation || 'UNKNOWN') })
  },
  {
    id: 'totalization',
    header: 'Totalização',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(detailOf(row.original).totalization_status || 'UNKNOWN') })
  },
  {
    id: 'guide',
    header: 'Guia FGTS Digital',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(detailOf(row.original).guide_status || 'UNSUPPORTED') })
  },
  {
    id: 'payment',
    header: 'Pagamento',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(detailOf(row.original).payment_status || 'UNSUPPORTED') })
  },
  buildMonitoringComunicacaoColumn<FgtsClientRow>({
    getState: row => fgtsSendState(row),
    onTracking: onFgtsTracking,
    onSend: onFgtsSend,
    onToggleAutomatic: onFgtsToggleAutomatic,
    testIdPrefix: 'fgts-tracking'
  }),
  buildMonitoringConsultedColumn<FgtsClientRow>({
    getAt: row => detailOf(row).last_synced_at || row.last_consulted_at,
    format: 'datetime',
    testId: 'fgts-last-consulted'
  }),
  {
    id: 'actions',
    header: MONITORING_ACTIONS_LABEL,
    enableHiding: false,
    enableSorting: false,
    meta: { ...MONITORING_ACTIONS_META },
    cell: ({ row }) => {
      const name = row.original.name || row.original.legal_name || `cliente ${row.original.client_id}`
      return buildMonitoringActionsMenuCell({
        ariaLabel: `Mais ações de ${name}`,
        testId: 'fgts-row-actions',
        items: fgtsActionItems(row.original)
      })
    }
  }
])

onMounted(() => {
  void loadCoverage()
})
</script>

<template>
  <MonitoringModuleTable
    title="FGTS Digital"
    panel-id="monitoring-fgts"
    module-key="fgts"
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
    empty-title="Nenhum cliente FGTS"
    :column-labels="{
      situation: 'Situação',
      closure: 'Fechamento',
      totalization: 'Totalização',
      guide: 'Guia FGTS Digital',
      payment: 'Pagamento',
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
        v-if="coverage"
        :color="coverage.source_available ? 'info' : 'warning'"
        icon="i-lucide-shield-check"
        :title="coverage.source_available && coverage.driver === 'official_bx'
          ? `eSocial BX oficial · ${coverage.environment === 'production' ? 'Produção' : 'Produção Restrita'}`
          : 'eSocial BX oficial desabilitado'"
        :description="coverage.official_limits
          ? `S-1299 e S-5013 automáticos via ${coverage.transport || 'SOAP 1.1/mTLS'} · limite local ${coverage.official_limits.daily_accesses_per_employer}/dia · lote de até ${coverage.official_limits.max_ids_per_download} IDs · atraso mínimo de ${coverage.official_limits.minimum_lag_minutes} min · intervalo de até ${coverage.official_limits.max_query_interval_days} dias · indisponível nos dias 1–7.`
          : 'A fonte oficial precisa ser habilitada de forma explícita.'"
        class="w-full"
        data-testid="fgts-esocial-coverage"
      />

      <UAlert
        v-if="coverage"
        color="warning"
        variant="subtle"
        icon="i-lucide-info"
        title="Cobertura parcial — não equivale ao FGTS Digital"
        description="O eSocial BX confirma fechamento e totalização conhecidos. Não consulta guia, pagamento, PIX nem pendências do portal FGTS Digital; esses estados permanecem independentes e não suportados por esta fonte."
        class="w-full"
        data-testid="fgts-esocial-partial-warning"
      >
        <template
          v-if="coverage.official_links?.manual"
          #actions
        >
          <UButton
            :to="coverage.official_links.manual"
            target="_blank"
            rel="noopener noreferrer"
            color="neutral"
            variant="outline"
            size="xs"
            label="Manual oficial"
            trailing-icon="i-lucide-external-link"
          />
        </template>
      </UAlert>

      <UAlert
        v-if="digitalCoverage"
        :color="digitalCoverage.driver === 'disabled' ? 'warning' : 'info'"
        icon="i-lucide-panels-top-left"
        :title="digitalCoverage.driver === 'disabled'
          ? 'Portal FGTS Digital desabilitado'
          : `Portal FGTS Digital · ${digitalCoverage.driver === 'fixture' ? 'fixture sem rede' : 'browser controlado'}`"
        :description="`Consulta de guias, pagamento e PDF separada do eSocial · manifesto ${digitalCoverage.portal_manifest_version} · Pix não suportado. Solver ${digitalCoverage.human_challenge_policy === 'SOLVE_HCAPTCHA_OR_PAUSE' ? 'NopeCHA externo com pausa segura' : 'desligado, com importação autorizada de sessão'}.`"
        class="w-full"
        data-testid="fgts-digital-coverage"
      />

      <UAlert
        v-if="coverageError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="coverageError"
        class="w-full"
      />

      <UAlert
        v-if="digitalCoverageError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="digitalCoverageError"
        class="w-full"
      />

      <UAlert
        v-if="overviewError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="overviewError"
        class="w-full"
      />
    </template>

    <template #detail>
      <USlideover
        v-model:open="detailOpen"
        :title="detailClient
          ? (detailClient.name || detailClient.legal_name || `Cliente #${detailClient.client_id}`)
          : 'Detalhe FGTS / eSocial'"
      >
        <template #body>
          <div
            v-if="detailLoading"
            class="py-8 text-sm text-muted"
          >
            Carregando competência e eventos…
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
            <UAlert
              v-if="detailReadiness"
              :color="detailReadiness.ready ? 'success' : 'warning'"
              :icon="detailReadiness.ready ? 'i-lucide-circle-check' : 'i-lucide-shield-alert'"
              :title="detailReadiness.ready ? 'eSocial BX pronto para consultar' : 'Consulta oficial bloqueada'"
              :description="detailReadiness.ready
                ? `${detailReadiness.locally_remaining} de ${detailReadiness.daily_limit} acessos locais restantes hoje.`
                : `${detailReadiness.blockers[0]?.message || 'Revise a configuração e a credencial A1.'} Código: ${detailReadiness.blockers[0]?.code || 'ESOCIAL_BX_NOT_READY'}.`"
              data-testid="fgts-esocial-readiness"
            />
            <p
              v-if="detailReadiness?.credential"
              class="text-xs text-muted"
              data-testid="fgts-esocial-credential-summary"
            >
              Certificado A1 •••{{ detailReadiness.credential.fingerprint_suffix }}
              <template v-if="detailReadiness.credential.expires_at">
                · válido até {{ formatDateTime(detailReadiness.credential.expires_at) }}
              </template>
            </p>

            <section
              class="space-y-3 rounded-xl border border-default p-3"
              data-testid="fgts-digital-portal-detail"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 class="text-sm font-medium text-highlighted">
                    Portal FGTS Digital
                  </h3>
                  <p class="text-xs text-muted">
                    Guias, PDFs e pagamento consultado no portal; independente do eSocial BX.
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-refresh-cw"
                    label="Consultar guias"
                    :loading="digitalActionLoading"
                    :disabled="!detailDigitalReadiness?.ready_for_read"
                    data-testid="fgts-digital-sync"
                    @click="syncDigitalGuides"
                  />
                  <UButton
                    v-if="canManageCredentials"
                    size="xs"
                    color="warning"
                    variant="soft"
                    icon="i-lucide-file-plus-2"
                    label="Preparar emissão"
                    :disabled="!detailDigitalReadiness?.ready_for_mutation"
                    data-testid="fgts-digital-open-preview"
                    @click="openDigitalPreview"
                  />
                </div>
              </div>

              <UAlert
                v-if="detailDigitalReadiness"
                :color="detailDigitalReadiness.ready_for_read ? 'success' : 'warning'"
                :icon="detailDigitalReadiness.ready_for_read ? 'i-lucide-circle-check' : 'i-lucide-shield-alert'"
                :title="detailDigitalReadiness.ready_for_read ? 'Portal pronto para consulta' : 'Portal bloqueado antes do browser'"
                :description="detailDigitalReadiness.ready_for_read
                  ? `${detailDigitalReadiness.credential_source === 'OFFICE' ? 'Procuração do escritório' : 'A1 do cliente'} · sessão ${detailDigitalReadiness.has_authorized_session ? 'autorizada' : 'será criada no login'} · CAPTCHA ${detailDigitalReadiness.captcha.driver}.`
                  : `${detailDigitalReadiness.blockers[0]?.message || 'Revise a configuração.'} Código: ${detailDigitalReadiness.blockers[0]?.code || 'FGTS_DIGITAL_NOT_READY'}.`"
                data-testid="fgts-digital-readiness"
              />

              <UAlert
                v-if="detailDigitalRuns.some(run => run.status === 'HUMAN_CHALLENGE_REQUIRED' || run.code === 'CAPTCHA_TOKEN_REJECTED')"
                color="warning"
                variant="subtle"
                icon="i-lucide-user-round-check"
                title="Ação humana necessária"
                description="O portal rejeitou ou substituiu o desafio automatizado. Importe uma sessão autorizada ou configure um proxy compartilhado antes de tentar novamente; nenhuma emissão foi executada."
                data-testid="fgts-digital-human-challenge"
              />

              <UAlert
                v-if="digitalActionError"
                color="error"
                :title="digitalActionError"
              />
              <UAlert
                v-if="digitalActionSuccess"
                color="success"
                :title="digitalActionSuccess"
              />

              <div>
                <h4 class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">
                  Guias consultadas
                </h4>
                <p v-if="!detailDigitalGuides.length" class="text-sm text-muted">
                  Nenhuma guia do portal persistida para este cliente.
                </p>
                <ul v-else class="divide-y divide-default text-sm">
                  <li
                    v-for="guide in detailDigitalGuides"
                    :key="String(guide.id)"
                    class="flex items-center justify-between gap-3 py-2"
                  >
                    <div class="min-w-0">
                      <p class="truncate font-medium text-highlighted">
                        {{ guide.identifier_code || guide.debit_ref || `Guia #${guide.id}` }}
                      </p>
                      <p class="text-xs text-muted">
                        {{ guide.competence_period_key || 'Sem competência' }} · {{ digitalGuideAmount(guide) }}
                      </p>
                    </div>
                    <div class="flex items-center gap-2">
                      <FiscalStatusBadge :status="String(guide.payment_status || 'UNKNOWN')" show-hint />
                      <UButton
                        v-if="digitalGuideHasDocument(guide)"
                        size="xs"
                        color="neutral"
                        variant="ghost"
                        icon="i-lucide-download"
                        aria-label="Baixar PDF protegido"
                        @click="downloadDigitalGuide(guide)"
                      />
                    </div>
                  </li>
                </ul>
              </div>

              <div>
                <h4 class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">
                  Execuções recentes
                </h4>
                <p v-if="!detailDigitalRuns.length" class="text-sm text-muted">
                  Nenhuma execução do portal registrada.
                </p>
                <ul v-else class="divide-y divide-default text-sm">
                  <li
                    v-for="run in detailDigitalRuns"
                    :key="run.id"
                    class="flex items-center justify-between gap-3 py-2"
                  >
                    <span class="min-w-0 truncate">
                      #{{ run.id }} · {{ run.operation }} · {{ run.code || 'sem código' }}
                    </span>
                    <FiscalStatusBadge :status="run.status" />
                  </li>
                </ul>
              </div>
            </section>

            <dl
              v-if="detailStatus"
              class="grid gap-2 text-sm sm:grid-cols-2"
            >
              <div>
                <dt class="text-muted">
                  Competência
                </dt>
                <dd class="font-medium">
                  {{ detailStatus.competence_period_key || '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Situação
                </dt>
                <dd>
                  <FiscalStatusBadge :status="String(detailStatus.situation || '')" />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Fechamento
                </dt>
                <dd>
                  <FiscalStatusBadge
                    :status="String(detailStatus.closure_status || 'UNKNOWN')"
                    show-hint
                  />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Totalização
                </dt>
                <dd>
                  <FiscalStatusBadge
                    :status="String(detailStatus.totalization_status || 'UNKNOWN')"
                    show-hint
                  />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Guia FGTS Digital
                </dt>
                <dd>
                  <FiscalStatusBadge
                    :status="String(detailStatus.guide_status || 'UNSUPPORTED')"
                    show-hint
                  />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Pagamento
                </dt>
                <dd>
                  <FiscalStatusBadge
                    :status="String(detailStatus.payment_status || 'UNSUPPORTED')"
                    show-hint
                  />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Último sync
                </dt>
                <dd class="font-medium">
                  {{ formatDateTime(String(detailStatus.last_synced_at || '') || null) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Cobertura
                </dt>
                <dd class="font-medium">
                  {{ detailStatus.coverage || 'PARTIAL' }}
                </dd>
              </div>
            </dl>

            <div>
              <h3 class="mb-2 text-sm font-medium">
                Eventos eSocial
              </h3>
              <div
                v-if="!detailEvents.length"
                class="text-sm text-muted"
              >
                Nenhum evento retornado para a competência.
              </div>
              <ul
                v-else
                class="divide-y divide-default text-sm"
              >
                <li
                  v-for="(ev, i) in detailEvents"
                  :key="String(ev.id || i)"
                  class="flex items-start justify-between gap-2 py-2"
                >
                  <div class="min-w-0">
                    <p class="font-medium text-highlighted">
                      {{ ev.event_label || ev.event_code || `Evento #${ev.id || i + 1}` }}
                    </p>
                    <p class="text-xs text-muted">
                      {{ formatDateTime(String(ev.observed_at || ev.created_at || '') || null) }}
                      <template v-if="ev.content_sha256">
                        · sha {{ String(ev.content_sha256).slice(0, 10) }}…
                      </template>
                    </p>
                  </div>
                  <FiscalStatusBadge
                    v-if="ev.status || ev.situation"
                    :status="String(ev.status || ev.situation)"
                  />
                </li>
              </ul>
            </div>

            <div>
              <h3 class="mb-2 text-sm font-medium">
                Divergências e limitações
              </h3>
              <div
                v-if="!detailDivergences.length"
                class="text-sm text-muted"
              >
                Nenhuma divergência eSocial retornada.
              </div>
              <ul
                v-else
                class="divide-y divide-default text-sm"
              >
                <li
                  v-for="(div, i) in detailDivergences"
                  :key="`${div.code || 'lim'}-${i}`"
                  class="flex items-start justify-between gap-2 py-2"
                >
                  <div class="min-w-0">
                    <p class="font-medium text-highlighted">
                      {{ div.title || div.code || `Item #${i + 1}` }}
                    </p>
                    <p class="text-xs text-muted">
                      {{ div.detail || '—' }}
                    </p>
                  </div>
                  <FiscalStatusBadge
                    v-if="div.situation || div.severity"
                    :status="String(div.situation || div.severity)"
                  />
                </li>
              </ul>
            </div>

            <UButton
              v-if="detailClient"
              size="sm"
              color="neutral"
              variant="outline"
              label="Painel do cliente"
              :to="clientHref(detailClient.client_id)"
            />
          </div>
        </template>
      </USlideover>
    </template>
  </MonitoringModuleTable>

  <ShellFormModal
    v-model:open="digitalPreviewOpen"
    title="Prévia de guia FGTS Digital"
    description="A prévia é somente leitura. A emissão só é enfileirada após a frase de confirmação; o hub nunca inicia pagamento ou Pix."
    :show-default-footer="false"
    test-id="fgts-digital-preview-modal"
  >
    <template #body>
      <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <UFormField label="Tipo de guia" required>
            <USelect
              v-model="digitalForm.guideType"
              :items="digitalGuideTypes"
              value-key="value"
              label-key="label"
              class="w-full"
              data-testid="fgts-digital-guide-type"
            />
          </UFormField>
          <UFormField label="Competência" required>
            <UInput
              v-model="digitalForm.competence"
              type="month"
              class="w-full"
              data-testid="fgts-digital-competence"
            />
          </UFormField>
          <UFormField label="Valor esperado" hint="Opcional na guia manual">
            <UInput
              v-model="digitalForm.amount"
              inputmode="decimal"
              placeholder="0,00"
              class="w-full"
              data-testid="fgts-digital-amount"
            />
          </UFormField>
          <UFormField
            v-if="digitalForm.guideType === 'PARAMETERIZED'"
            label="IDs dos débitos"
            hint="Um por linha; armazenados somente no vault até a execução"
          >
            <UTextarea
              v-model="digitalForm.debitIds"
              :rows="3"
              class="w-full"
              data-testid="fgts-digital-debit-ids"
            />
          </UFormField>
        </div>

        <UAlert
          v-if="digitalPreviewError"
          color="error"
          :title="digitalPreviewError"
        />

        <div
          v-if="digitalPreview"
          class="space-y-3 rounded-xl border border-warning/40 bg-warning/5 p-3"
          data-testid="fgts-digital-preview-result"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium">Run de prévia #{{ digitalPreview.run.id }}</span>
            <FiscalStatusBadge :status="digitalPreview.run.status" />
          </div>
          <p class="text-xs text-muted">
            Expira em {{ formatDateTime(digitalPreview.run.preview_expires_at) }}. Se competência, valor ou seleção mudarem, gere uma nova prévia.
          </p>
          <UAlert
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Confirmação vinculada à seleção"
            :description="`Digite: ${digitalPreview.run.confirmation_phrase || 'frase indisponível'}`"
          />
          <UFormField label="Frase de confirmação" required>
            <UInput
              v-model="digitalConfirmation"
              autocomplete="off"
              class="w-full"
              data-testid="fgts-digital-confirmation"
            />
          </UFormField>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancelar"
            @click="closeDigitalPreview"
          />
          <UButton
            v-if="!digitalPreview?.preview_token"
            color="neutral"
            variant="solid"
            icon="i-lucide-scan-search"
            label="Gerar prévia"
            :loading="digitalPreviewLoading"
            :disabled="!digitalForm.competence"
            data-testid="fgts-digital-preview-submit"
            @click="requestDigitalPreview"
          />
          <UButton
            v-else
            color="warning"
            variant="solid"
            icon="i-lucide-shield-check"
            label="Autorizar emissão"
            :loading="digitalPreviewLoading"
            :disabled="!digitalConfirmation"
            data-testid="fgts-digital-emit-submit"
            @click="authorizeDigitalEmission"
          />
        </div>
      </div>
    </template>
  </ShellFormModal>

  <ClientsClientFormModal
    v-if="canManageClients"
    v-model:open="clientFormOpen"
    :client="formClient"
    :can-manage-credentials="canManageCredentials"
    :can-manage-clients="canManageClients"
    @saved="onClientFormSaved"
  />

  <MonitoringPgdasdCommunicationModals
    v-model:preview-open="communicationPreviewOpen"
    v-model:tracking-open="communicationTrackingOpen"
    v-model:prefs-open="communicationPreferencesOpen"
    context="FGTS"
    :client-id="communicationRow?.client_id || null"
    :client-name="communicationRow?.name || communicationRow?.legal_name"
    :preference="communicationRow ? detailOf(communicationRow).communication : null"
    :period-key="communicationRow
      ? (detailOf(communicationRow).competence_period_key || filters.competence)
      : filters.competence"
  />
</template>

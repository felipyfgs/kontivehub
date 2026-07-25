<script setup lang="ts">
/**
 * Guias (7.9) — amount_cents, payment_status e emissão independentes;
 * detalhe + download com token efêmero; FiscalClientPicker; demo quando origem DEMO.
 */
import type { TableColumn } from '@nuxt/ui'
import type {
  FiscalModuleOverview,
  MonitoringFilterConfig,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { fiscalDocumentDownloadFilename } from '~/utils/authenticated-download'
import { resolveGuideEmissionCodes } from '~/utils/fiscal-high-risk'
import { sortHeader } from '~/utils/table-sort'

const FiscalStatusBadge = resolveComponent('FiscalStatusBadge')
const UButton = resolveComponent('UButton')
const { download: downloadAuthenticated } = useAuthenticatedDownload()

/** payment_status oficiais (TaxGuidePaymentStatus) — independentes de emissão. */
const PAYMENT_STATUS_ITEMS: Array<{ label: string, value: string }> = [
  { label: 'Todos os pagamentos', value: 'all' },
  { label: 'Desconhecido', value: 'UNKNOWN' },
  { label: 'Sem confirmação', value: 'NOT_CONFIRMED' },
  { label: 'Pago (oficial)', value: 'CONFIRMED' },
  { label: 'Parcial', value: 'PARTIAL' }
]

const api = useApi()
const route = useRoute()
const router = useRouter()
const { sessionEpoch, canAccessAdministration } = useDashboard()
const toast = useToast()
const {
  page, perPage, total, lastPage, clientId, competence, q,
  loading, loadError, applyPaginator
} = useServerPage()

/** Coluna UI → campo aceito por `GET /api/v1/fiscal/guides`. */
const GUIDE_SORT_COLUMN_TO_API = Object.freeze<Record<string, 'client_id' | 'competence' | 'due_at' | 'amount' | 'payment_status'>>({
  client: 'client_id',
  competence: 'competence',
  due: 'due_at',
  amount: 'amount',
  payment: 'payment_status'
})

function resolveGuideSortApi(columnId: string | undefined): 'client_id' | 'competence' | 'due_at' | 'amount' | 'payment_status' {
  if (!columnId) return 'due_at'
  return GUIDE_SORT_COLUMN_TO_API[columnId] ?? 'due_at'
}

const sorting = ref<{ id: string, desc: boolean }[]>([{ id: 'due', desc: true }])

const paymentStatus = ref('all')

const rows = ref<Record<string, unknown>[]>([])
const overview = ref<FiscalModuleOverview | null>(null)
const overviewError = ref<string | null>(null)
/** Contadores de payment_status do read-model unificado (office/cliente). */
const paymentCounters = ref<{
  UNKNOWN: number
  NOT_CONFIRMED: number
  CONFIRMED: number
  PARTIAL: number
}>({
  UNKNOWN: 0,
  NOT_CONFIRMED: 0,
  CONFIRMED: 0,
  PARTIAL: 0
})

/** KPI strip: mapeia pagamento → situação operacional da faixa. */
const guideKpiCounters = computed(() => ({
  up_to_date: paymentCounters.value.CONFIRMED,
  processing: 0,
  pending: paymentCounters.value.NOT_CONFIRMED,
  attention: paymentCounters.value.PARTIAL,
  unknown: paymentCounters.value.UNKNOWN,
  error: 0,
  blocked: 0,
  unsupported: 0,
  not_applicable: 0
}))

const guideKpiTotal = computed(() =>
  paymentCounters.value.UNKNOWN
  + paymentCounters.value.NOT_CONFIRMED
  + paymentCounters.value.CONFIRMED
  + paymentCounters.value.PARTIAL
)

function paymentStatusFromSituation(situation: string | null | undefined): string {
  const sit = String(situation || '').trim().toUpperCase()
  if (!sit || sit === 'ALL') return 'all'
  switch (sit) {
    case 'UP_TO_DATE':
      return 'CONFIRMED'
    case 'PENDING':
      return 'NOT_CONFIRMED'
    case 'ATTENTION':
      return 'PARTIAL'
    case 'UNKNOWN':
      return 'UNKNOWN'
    default:
      return 'all'
  }
}

function situationFromPaymentStatus(status: string): string {
  switch (String(status || '').toUpperCase()) {
    case 'CONFIRMED':
      return 'UP_TO_DATE'
    case 'NOT_CONFIRMED':
      return 'PENDING'
    case 'PARTIAL':
      return 'ATTENTION'
    case 'UNKNOWN':
      return 'UNKNOWN'
    default:
      return 'all'
  }
}

function isNumericGuideId(id: unknown): id is number {
  return typeof id === 'number' && Number.isFinite(id) && id > 0
}

function isVirtualGuideRow(row: Record<string, unknown>): boolean {
  const source = String(row.source || '')
  return source === 'PGDASD_CONSULT' || source === 'DCTFWEB_DARF' || !isNumericGuideId(row.id as number)
}

const detailOpen = ref(false)
const detail = ref<Record<string, unknown> | null>(null)
const detailId = ref<number | null>(null)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const downloading = ref(false)

const mutationOpen = ref(false)
const mutationRequest = ref<{
  client_id: number
  solution_code: string
  service_code: string
  operation_code: string
  competence_period_key?: string | null
  module?: string
} | null>(null)

const filters = computed<MonitoringFilterValue>(() => normalizeMonitoringFilters({
  q: q.value,
  // Competência não é filtro da UI de Guias (endpoint não aplica).
  competence: '',
  clientIds: Number(clientId.value) >= 1 ? [Number(clientId.value)] : [],
  paymentStatus: paymentStatus.value,
  // Espelha payment → situation para o KPI strip destacar a faixa ativa.
  situation: situationFromPaymentStatus(paymentStatus.value)
}))
const filterConfig: MonitoringFilterConfig = {
  // List API de guias não aceita `q` — busca rápida ficaria decorativa.
  search: false,
  // Endpoint atual não aplica competência — não expor na UI.
  fields: [
    { key: 'clientId', kind: 'client', label: 'Cliente', multiple: false },
    {
      key: 'paymentStatus',
      kind: 'option',
      label: 'Status de pagamento',
      items: PAYMENT_STATUS_ITEMS
    }
  ]
}

function getGuideRowId(row: Record<string, unknown>) {
  return `guide:${String(row.id)}`
}

function getGuideClientId(row: Record<string, unknown>) {
  const id = Number(row.client_id)
  return Number.isFinite(id) && id > 0 ? Math.floor(id) : null
}

function versionOf(row: Record<string, unknown>): Record<string, unknown> | null {
  const v = row.current_version
  return v && typeof v === 'object' ? (v as Record<string, unknown>) : null
}

function emissionOf(row: Record<string, unknown>): string {
  const v = versionOf(row)
  return String(v?.emission_status || row.emission_status || '')
}

let overviewSeq = 0
let listSeq = 0

function stillCurrent(seq: number, kind: 'overview' | 'list', epoch: number) {
  if (epoch !== sessionEpoch.value) return false
  return kind === 'overview' ? seq === overviewSeq : seq === listSeq
}

async function loadOverview() {
  const seq = ++overviewSeq
  const epoch = sessionEpoch.value
  try {
    const data = (await api.fiscal.modules.overview('guides', {
      q: q.value.trim() || undefined,
      // overview usa situation de carteira; payment_status vai só na lista de guias
      situation: undefined,
      client_id: clientId.value ? Number(clientId.value) : undefined
    })).data
    if (!stillCurrent(seq, 'overview', epoch)) return
    overview.value = data
    overviewError.value = null
  } catch (caught) {
    if (!stillCurrent(seq, 'overview', epoch)) return
    overviewError.value = apiErrorMessage(caught, 'Falha no overview de guias.')
  }
}

/** URL Nuxt path-only — limpa query residual (bookmarks legados). */
async function syncGuidesUrl() {
  if (Object.keys(route.query).length > 0) {
    await router.replace({ path: route.path })
  }
}

async function load() {
  const seq = ++listSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    await syncGuidesUrl()
    if (!stillCurrent(seq, 'list', epoch)) return
    const sort = sorting.value[0]
    const res = await api.fiscal.guides.list({
      page: page.value,
      per_page: perPage.value,
      client_id: clientId.value ? Number(clientId.value) : undefined,
      payment_status: paymentStatus.value !== 'all' ? paymentStatus.value : undefined,
      sort: resolveGuideSortApi(sort?.id),
      direction: sort?.desc ? 'desc' : 'asc'
    }) as Record<string, unknown>
    if (!stillCurrent(seq, 'list', epoch)) return
    rows.value = (res.data as Record<string, unknown>[]) || []
    applyPaginator(res)
    const counters = res.payment_counters as Partial<typeof paymentCounters.value> | undefined
    if (counters && typeof counters === 'object') {
      paymentCounters.value = {
        UNKNOWN: Number(counters.UNKNOWN) || 0,
        NOT_CONFIRMED: Number(counters.NOT_CONFIRMED) || 0,
        CONFIRMED: Number(counters.CONFIRMED) || 0,
        PARTIAL: Number(counters.PARTIAL) || 0
      }
    }
    if (res.total == null && !(res.meta as { total?: number } | undefined)?.total) {
      total.value = rows.value.length
      lastPage.value = 1
    }
  } catch (caught) {
    if (!stillCurrent(seq, 'list', epoch)) return
    rows.value = []
    total.value = 0
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar guias.')
    paymentCounters.value = {
      UNKNOWN: 0,
      NOT_CONFIRMED: 0,
      CONFIRMED: 0,
      PARTIAL: 0
    }
  } finally {
    if (stillCurrent(seq, 'list', epoch)) loading.value = false
  }
}

async function openDetail(id: number) {
  detailOpen.value = true
  detailId.value = id
  detailLoading.value = true
  detailError.value = null
  detail.value = null
  try {
    detail.value = (await api.fiscal.guides.get(id)).data
  } catch (caught) {
    detailError.value = apiErrorMessage(caught, 'Falha ao carregar guia.')
  } finally {
    detailLoading.value = false
  }
}

async function downloadGuide(id: number) {
  downloading.value = true
  try {
    const tokenRes = await api.fiscal.guides.issueDownloadToken(id)
    const url = api.fiscal.guides.downloadUrl(tokenRes.data.token)
    window.open(url, '_blank', 'noopener')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao emitir token de download.'),
      color: 'error'
    })
  } finally {
    downloading.value = false
  }
}

const columns: TableColumn<Record<string, unknown>>[] = [
  {
    id: 'id',
    accessorKey: 'id',
    header: 'ID',
    enableSorting: false,
    meta: { class: { th: 'w-16', td: 'w-16' } },
    cell: ({ row }) => {
      const id = row.original.id
      if (isNumericGuideId(id as number)) return String(id)
      const code = row.original.identifier_code || row.original.das_number
      return code ? String(code).slice(-8) : '—'
    }
  },
  {
    id: 'client',
    header: ({ column }) => sortHeader('Cliente', column),
    cell: ({ row }) => {
      const cid = row.original.client_id as number | undefined
      if (!cid) return '—'
      return h(UButton, {
        size: 'xs',
        color: 'neutral',
        variant: 'link',
        label: `#${cid}`,
        to: `/monitoring/clients/${cid}/guides`
      })
    }
  },
  {
    id: 'system',
    header: 'Sistema / tipo',
    enableSorting: false,
    cell: ({ row }) => {
      const source = String(row.original.source || '')
      if (source === 'PGDASD_CONSULT') {
        return 'PGDAS-D / DAS'
      }
      if (source === 'DCTFWEB_DARF') {
        return 'DCTFWeb / DARF'
      }
      return [row.original.system_code, row.original.service_code].filter(Boolean).join(' / ') || '—'
    }
  },
  {
    id: 'competence',
    header: ({ column }) => sortHeader('Competência', column),
    cell: ({ row }) => String(row.original.competence_period_key || row.original.period_key || '—')
  },
  {
    id: 'amount',
    header: 'Valor',
    enableSorting: false,
    cell: ({ row }) => formatAmountCents(row.original.amount_cents as number | null)
  },
  {
    id: 'due',
    header: ({ column }) => sortHeader('Vencimento', column),
    cell: ({ row }) => formatDateTime(String(row.original.due_at || '') || null)
  },
  {
    id: 'emission',
    header: 'Emissão',
    enableSorting: false,
    cell: ({ row }) => {
      const status = emissionOf(row.original)
      return status
        ? h(FiscalStatusBadge, { fill: true, status })
        : '—'
    }
  },
  {
    id: 'payment',
    header: 'Pagamento',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: String(row.original.payment_status || 'UNKNOWN') })
  },
  {
    id: 'validity',
    header: 'Validade',
    enableSorting: false,
    cell: ({ row }) => {
      const v = versionOf(row.original)
      return formatDateTime(String(v?.valid_until || '') || null)
    }
  },
  {
    id: 'version',
    header: 'Versão',
    enableSorting: false,
    cell: ({ row }) => {
      const v = versionOf(row.original)
      return String(
        v?.version_number
        ?? row.original.current_version_id
        ?? v?.id
        ?? '—'
      )
    }
  },
  {
    id: 'actions',
    header: 'Ações',
    enableHiding: false,
    enableSorting: false,
    cell: ({ row }) => {
      const original = row.original
      const cid = getGuideClientId(original)
      const children = []

      if (cid) {
        children.push(h(UButton, {
          size: 'xs',
          color: 'neutral',
          variant: 'ghost',
          label: 'Cliente',
          to: `/monitoring/clients/${cid}/guides`
        }))
      }

      if (!isVirtualGuideRow(original) && isNumericGuideId(original.id as number)) {
        const id = original.id as number
        children.push(
          h(UButton, {
            size: 'xs',
            color: 'neutral',
            variant: 'ghost',
            label: 'Detalhe',
            onClick: () => openDetail(id)
          }),
          h(UButton, {
            size: 'xs',
            color: 'neutral',
            variant: 'ghost',
            label: 'Download',
            onClick: () => downloadGuide(id)
          })
        )
        const emissionCodes = canAccessAdministration.value
          ? resolveGuideEmissionCodes(original)
          : null
        if (emissionCodes) {
          children.push(h(UButton, {
            size: 'xs',
            color: 'warning',
            variant: 'ghost',
            label: 'Emitir',
            onClick: () => {
              mutationRequest.value = {
                client_id: Number(original.client_id),
                solution_code: emissionCodes.solution_code,
                service_code: emissionCodes.service_code,
                operation_code: emissionCodes.operation_code,
                competence_period_key: String(original.competence_period_key || '') || null,
                module: emissionCodes.module || 'guides'
              }
              mutationOpen.value = true
            }
          }))
        }
      } else {
        const doc = original.document as {
          href?: string | null
          available?: boolean
          label?: string | null
          kind?: string | null
        } | null
        if (doc?.available && doc.href) {
          const href = doc.href
          children.push(h(UButton, {
            size: 'xs',
            color: 'neutral',
            variant: 'ghost',
            label: 'Documento',
            onClick: () => {
              void downloadAuthenticated(
                href,
                fiscalDocumentDownloadFilename({ label: doc.label, kind: doc.kind })
              )
            }
          }))
        }
      }

      return h('div', { class: 'flex justify-end gap-1' }, children)
    }
  }
]

function setPage(next: number) {
  page.value = Math.max(1, Math.floor(Number(next) || 1))
}

function setPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (perPage.value === target) return
  perPage.value = target
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
}

/** Transação de filtro: evita double-load entre watch(page) e mutação de filtros. */
let filterTransactionDepth = 0

async function applyModuleFilters(nextValue: MonitoringFilterValue) {
  const next = normalizeMonitoringFilters(nextValue)
  // UI de Guias não expõe competência — sempre limpar residual.
  next.competence = ''
  const nextClientId = next.clientIds[0] ? String(next.clientIds[0]) : ''
  // KPI strip emite `situation`; filtro estruturado usa `paymentStatus`.
  const resolvedPayment = next.situation && next.situation !== 'all'
    ? paymentStatusFromSituation(next.situation)
    : (next.paymentStatus || 'all')

  if (
    q.value === next.q
    && clientId.value === nextClientId
    && paymentStatus.value === resolvedPayment
  ) return

  filterTransactionDepth += 1
  try {
    q.value = next.q
    competence.value = ''
    clientId.value = nextClientId
    paymentStatus.value = resolvedPayment
    page.value = 1
    await nextTick()
  } finally {
    filterTransactionDepth -= 1
  }

  await Promise.all([load(), loadOverview()])
}

async function resetModuleFilters() {
  await applyModuleFilters(resetMonitoringFilters())
}

/** Métricas opcionais do overview (campo extra pode existir no payload). */
const unpaidAmountCents = computed(() => {
  const metrics = overview.value?.metrics as Record<string, unknown> | undefined
  const raw = metrics?.unpaid_amount_cents
  return typeof raw === 'number' ? raw : null
})

function refreshAll() {
  void load()
  void loadOverview()
}

watch(page, () => {
  if (filterTransactionDepth > 0) return
  void load()
})
watch(sorting, () => {
  if (filterTransactionDepth > 0) return
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
}, { deep: true })
watch(sessionEpoch, () => {
  filterTransactionDepth += 1
  try {
    q.value = ''
    competence.value = ''
    clientId.value = ''
    paymentStatus.value = 'all'
    sorting.value = [{ id: 'due', desc: true }]
    rows.value = []
    total.value = 0
    lastPage.value = 1
    page.value = 1
    overview.value = null
    paymentCounters.value = {
      UNKNOWN: 0,
      NOT_CONFIRMED: 0,
      CONFIRMED: 0,
      PARTIAL: 0
    }
    detail.value = null
    detailOpen.value = false
    mutationOpen.value = false
    mutationRequest.value = null
  } finally {
    filterTransactionDepth -= 1
  }
  void load()
  void loadOverview()
})
onMounted(() => {
  void load()
  void loadOverview()
})
</script>

<template>
  <MonitoringModuleTable
    title="Guias"
    panel-id="monitoring-guides"
    module-key="guides"
    surface="monitoring.guides"
    :columns="columns"
    :rows="rows"
    :loading="loading"
    :error="loadError"
    :page="page"
    :last-page="lastPage"
    :total="total"
    :per-page="perPage"
    :filters="filters"
    :filter-config="filterConfig"
    :sorting="sorting"
    :get-row-id="getGuideRowId"
    :get-client-id="getGuideClientId"
    :total-clients="guideKpiTotal"
    :counters="guideKpiCounters"
    :surface-summary="overview?.surface ?? null"
    :data-origin="overview?.data_origin"
    :data-origin-label="overview?.data_origin_label"
    :source-label="overview?.source_label"
    :as-of="overview?.as_of"
    :horizontal-scroll="false"
    empty-title="Nenhuma guia"
    :column-labels="{
      system: 'Sistema / tipo',
      amount: 'Valor',
      due: 'Vencimento',
      emission: 'Emissão',
      payment: 'Pagamento',
      validity: 'Validade',
      version: 'Versão'
    }"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="sorting = $event"
    @quick-filter-change="applyModuleFilters"
    @apply-filters="applyModuleFilters"
    @reset-filters="resetModuleFilters"
    @refresh="refreshAll"
  >
    <template #utilities>
      <UAlert
        v-if="overviewError"
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
            @click="loadOverview"
          />
        </template>
      </UAlert>
      <p
        v-if="unpaidAmountCents != null"
        class="text-sm text-muted"
      >
        Em aberto (métrica do overview):
        <span class="font-medium text-highlighted">
          {{ formatAmountCents(unpaidAmountCents) }}
        </span>
      </p>
    </template>

    <template #detail>
      <USlideover
        v-model:open="detailOpen"
        title="Detalhe da guia"
      >
        <template #body>
          <div
            v-if="detailLoading"
            class="py-8 text-sm text-muted"
          >
            Carregando…
          </div>
          <UAlert
            v-else-if="detailError"
            color="error"
            :title="detailError"
          >
            <template #actions>
              <UButton
                v-if="detailId"
                size="xs"
                color="neutral"
                variant="outline"
                label="Tentar de novo"
                @click="openDetail(detailId)"
              />
            </template>
          </UAlert>
          <div
            v-else-if="detail"
            class="flex flex-col gap-4 text-sm"
          >
            <dl class="grid gap-2 sm:grid-cols-2">
              <div>
                <dt class="text-muted">
                  Valor
                </dt>
                <dd class="font-medium">
                  {{ formatAmountCents(detail.amount_cents as number | null) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Pagamento
                </dt>
                <dd>
                  <FiscalStatusBadge :status="String(detail.payment_status || '')" />
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Emissão
                </dt>
                <dd>
                  <FiscalStatusBadge
                    v-if="emissionOf(detail)"
                    :status="emissionOf(detail)"
                  />
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Competência
                </dt>
                <dd>{{ detail.competence_period_key || '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Vencimento
                </dt>
                <dd>{{ formatDateTime(String(detail.due_at || '') || null) }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Validade do documento
                </dt>
                <dd>
                  {{ formatDateTime(String(versionOf(detail)?.valid_until || '') || null) }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Sistema
                </dt>
                <dd>{{ detail.system_code }} / {{ detail.service_code }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Versão atual
                </dt>
                <dd>
                  {{ versionOf(detail)?.version_number ?? detail.current_version_id ?? '—' }}
                </dd>
              </div>
              <div v-if="detail.identifier_code">
                <dt class="text-muted">
                  Identificador
                </dt>
                <dd>{{ detail.identifier_code }}</dd>
              </div>
              <div v-if="detail.payment_source">
                <dt class="text-muted">
                  Fonte do pagamento
                </dt>
                <dd>{{ detail.payment_source }}</dd>
              </div>
            </dl>
            <UButton
              icon="i-lucide-download"
              label="Download protegido"
              :loading="downloading"
              @click="downloadGuide(Number(detail.id))"
            />
            <p class="text-xs text-muted">
              O download obtém um token efêmero; o caminho do cofre nunca é exposto.
            </p>
          </div>
        </template>
      </USlideover>

      <FiscalMutationConfirmModal
        v-model:open="mutationOpen"
        :request="mutationRequest"
        :context="{
          effect: 'Emitir guia (efeito financeiro/fiscal)',
          competence: mutationRequest?.competence_period_key || undefined
        }"
        @success="refreshAll"
      />
    </template>
  </MonitoringModuleTable>
</template>

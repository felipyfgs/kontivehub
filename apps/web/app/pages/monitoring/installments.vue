<script setup lang="ts">
/**
 * Parcelamentos — modalidades, pedido, saldo, parcelas, próxima, atraso, guia.
 * Detalhe do pedido em slideover navegável. Task 7.4
 */
import type { TableColumn } from '@nuxt/ui'
import type {
  InstallmentModalityCatalogItem,
  InstallmentOrder,
  InstallmentParcel,
  InstallmentPayment,
  InstallmentsClientDetail,
  InstallmentsClientRow,
  MonitoringFilterConfig
} from '~/types/fiscal-modules'
import { sortHeader } from '~/utils/table-sort'
import { MONITORING_CLIENT_COLUMN_META } from '~/utils/monitoring-table-columns'

const FiscalStatusBadge = resolveComponent('FiscalStatusBadge')
const FiscalClientCell = resolveComponent('FiscalClientCell')
const FiscalDocumentAction = resolveComponent('FiscalDocumentAction')
const UButton = resolveComponent('UButton')

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
  allowsDocument,
  sorting,
  setPage,
  setPerPage,
  refresh,
  applyFilters,
  applyQuickFilters,
  resetFilters
} = useFiscalModulePortfolio('installments')

/** Catálogo oficial Integra-Parcelamento (fallback se a API falhar). */
const CATALOG_MODALITIES = [
  { code: 'PARCSN', label: 'Parcelamento Simples Nacional', regime: 'SN', executable: true },
  { code: 'PARCSN-ESP', label: 'Parcelamento Especial Simples Nacional', regime: 'SN', executable: true },
  { code: 'PERTSN', label: 'PERT Simples Nacional', regime: 'SN', executable: true },
  { code: 'RELPSN', label: 'RELP Simples Nacional', regime: 'SN', executable: true },
  { code: 'PARCMEI', label: 'Parcelamento MEI', regime: 'MEI', executable: true },
  { code: 'PARCMEI-ESP', label: 'Parcelamento Especial MEI', regime: 'MEI', executable: true },
  { code: 'PERTMEI', label: 'PERT MEI', regime: 'MEI', executable: true },
  { code: 'RELPMEI', label: 'RELP MEI', regime: 'MEI', executable: true },
  { code: 'PARC-PAEX', label: 'Parcelamento PAEX', regime: 'GERAL', executable: false },
  { code: 'PARC-SIPADE', label: 'Parcelamento SIPADE', regime: 'GERAL', executable: false }
] as const satisfies ReadonlyArray<Pick<InstallmentModalityCatalogItem, 'code' | 'label' | 'regime' | 'executable'>>

const api = useApi()
const modalities = ref<InstallmentModalityCatalogItem[]>([])
const modalitiesError = ref<string | null>(null)
const distinctOverviewError = computed(() =>
  overviewError.value && overviewError.value !== loadError.value
    ? overviewError.value
    : null
)

const resolvedModalities = computed(() => modalities.value.length
  ? modalities.value
  : CATALOG_MODALITIES.map(item => ({
      ...item,
      official_state: item.executable ? 'PRODUCTION' : 'PROSPECTION',
      official_state_label: item.executable ? 'Em produção' : 'Em prospecção',
      monitoring_supported: item.executable,
      required_power: null
    })))

const filterConfig = computed<MonitoringFilterConfig>(() => ({
  fields: [
    { key: 'situation', kind: 'option', label: 'Situação' },
    { key: 'clientId', kind: 'client', label: 'Cliente' }
  ]
}))

function getRowId(row: InstallmentsClientRow) {
  return `c:${row.client_id}`
}

const INSTALLMENT_TAB_LABELS: Record<string, string> = {
  'PARCSN': 'Simples',
  'PARCSN-ESP': 'Simples Especial',
  'PERTSN': 'PERT Simples',
  'RELPSN': 'RELP Simples',
  'PARCMEI': 'MEI',
  'PARCMEI-ESP': 'MEI Especial',
  'PERTMEI': 'PERT MEI',
  'RELPMEI': 'RELP MEI',
  'PARC-PAEX': 'PAEX',
  'PARC-SIPADE': 'SIPADE'
}

function activeModalityCodes() {
  return String(filters.value.modality || '')
    .split(',')
    .map(code => code.trim().toUpperCase())
    .filter(Boolean)
}

const selectedInstallmentType = computed<string>({
  get: () => {
    const active = activeModalityCodes()
    if (active.length === 1 && resolvedModalities.value.some(item => item.code === active[0])) {
      return active[0]!
    }
    return 'all'
  },
  set: (value) => {
    const item = resolvedModalities.value.find(modality => modality.code === value)
    if (value !== 'all' && !item?.executable) return
    const modality = value === 'all' ? 'all' : value
    if ((filters.value.modality || 'all') === modality) return
    void applyFilters({ ...filters.value, modality })
  }
})

function tabBadge(key: string): number | string {
  const count = overview.value?.metrics?.tab_counts?.[key]
  if (typeof count === 'number' && Number.isFinite(count)) return count
  return overviewLoading.value || (!overview.value && !overviewError.value) ? '…' : '—'
}

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const detailOrder = ref<InstallmentOrder | null>(null)
const detailOrders = ref<InstallmentOrder[]>([])
const detailParcels = ref<InstallmentParcel[]>([])
const detailRow = ref<InstallmentsClientRow | null>(null)

/**
 * O endpoint do pedido já retorna as parcelas persistidas. A consulta paralela
 * preserva o contrato paginado e serve como fallback para projeções antigas.
 * Ambas são leituras locais: abrir o detalhe nunca dispara uma consulta SERPRO.
 */
const orderParcels = computed(() => {
  const embedded = detailOrder.value?.parcels
  return Array.isArray(embedded) && embedded.length > 0
    ? embedded
    : detailParcels.value
})
const orderPayments = computed<InstallmentPayment[]>(() => detailOrder.value?.payments || [])

const detailHasOverdueParcels = computed(() => orderParcels.value.some((parcel) => {
  const status = String(parcel.status || '').toUpperCase()
  return status.includes('ATRAS') || status.includes('OVERDUE')
}))

function parcelLabel(parcel: InstallmentParcel) {
  return String(parcel.parcel_number || parcel.id || '—')
}

function parcelStatus(parcel: InstallmentParcel) {
  return String(parcel.status || 'PENDING')
}

function parcelPaymentStatus(parcel: InstallmentParcel) {
  const value = String(parcel.payment_status || '').trim()
  return value || null
}

function detailOf(row: InstallmentsClientRow): InstallmentsClientDetail {
  return row.detail || {}
}

const installmentTypeTabs = computed(() => [
  { label: 'Todos', value: 'all', badge: tabBadge('all') },
  ...resolvedModalities.value.map(item => ({
    label: item.executable
      ? (INSTALLMENT_TAB_LABELS[item.code] || item.label)
      : `${INSTALLMENT_TAB_LABELS[item.code] || item.label} · em prospecção`,
    value: item.code,
    badge: tabBadge(item.code),
    disabled: !item.executable
  }))
] satisfies Array<{ label: string, value: string, badge: number | string, disabled?: boolean }>)

/** Lista já filtrada server-side por modality — sem refiltro local. */
const displayRows = computed(() => rows.value)
const displayTotal = computed(() => total.value)

async function loadModalities() {
  try {
    modalities.value = (await api.fiscal.installments.modalities()).data || []
    modalitiesError.value = null
  } catch (caught) {
    modalities.value = []
    modalitiesError.value = apiErrorMessage(caught, 'Falha ao carregar modalidades do catálogo.')
  }
}

async function openOrder(orderId: number, row?: InstallmentsClientRow, availableOrders?: InstallmentOrder[]) {
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null
  detailOrder.value = null
  detailParcels.value = []
  if (row) detailRow.value = row
  if (availableOrders) detailOrders.value = availableOrders
  try {
    const [orderRes, parcelsRes] = await Promise.allSettled([
      api.fiscal.installments.order(orderId),
      api.fiscal.installments.parcels({ order_id: orderId, per_page: 50 })
    ])
    if (orderRes.status === 'fulfilled') {
      detailOrder.value = orderRes.value.data
    } else {
      detailError.value = apiErrorMessage(orderRes.reason, 'Falha ao carregar pedido.')
    }
    if (parcelsRes.status === 'fulfilled') {
      detailParcels.value = parcelsRes.value.data || []
    }
  } finally {
    detailLoading.value = false
  }
}

async function openClientOrders(row: InstallmentsClientRow) {
  const embedded = detailOf(row).orders || []
  let clientOrders = embedded
  if (clientOrders.length === 0) {
    const modalities = activeModalityCodes()
    const response = await api.fiscal.installments.orders({
      client_id: row.client_id,
      modality: modalities.length === 1 ? modalities[0] : undefined,
      per_page: 100
    })
    clientOrders = modalities.length > 1
      ? (response.data || []).filter(order => modalities.includes(order.modality))
      : response.data || []
  }
  const first = clientOrders[0]
  if (!first) return
  await openOrder(first.id, row, clientOrders)
}

const columns: TableColumn<InstallmentsClientRow>[] = [
  {
    id: 'situation',
    header: 'Situação',
    enableSorting: false,
    cell: ({ row }) => h(FiscalStatusBadge, {
      fill: true,
      status: String(detailOf(row.original).order_situation || row.original.situation)
    })
  },
  {
    id: 'modality',
    header: 'Modalidade',
    enableSorting: false,
    cell: ({ row }) => {
      const d = detailOf(row.original)
      const values = d.modalities?.length
        ? d.modalities
        : d.modality ? [String(d.modality)] : []
      if (values.length <= 1) return values[0] || '—'
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'font-medium text-highlighted' }, `${values.length} modalidades`),
        h('p', { class: 'truncate text-xs text-muted', title: values.join(', ') }, values.join(', '))
      ])
    }
  },
  {
    id: 'order',
    header: 'Pedido',
    enableSorting: false,
    cell: ({ row }) => {
      const d = detailOf(row.original)
      if ((d.order_count || 0) > 1) return `${d.order_count} pedidos`
      return String(d.external_order_id || d.order_id || '—')
    }
  },
  {
    id: 'total',
    header: 'Saldo',
    enableSorting: false,
    cell: ({ row }) => formatAmountCents(detailOf(row.original).total_amount_cents)
  },
  {
    id: 'parcels',
    header: 'Parcelas',
    enableSorting: false,
    cell: ({ row }) => {
      const d = detailOf(row.original)
      const count = d.parcel_count ?? '—'
      const overdue = d.overdue_parcels ?? 0
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'font-medium text-highlighted' }, String(count)),
        overdue
          ? h('p', { class: 'text-xs font-medium text-error' }, `${overdue} em atraso`)
          : h('p', { class: 'text-xs text-muted' }, 'Sem atraso sinalizado')
      ])
    }
  },
  {
    id: 'next',
    header: 'Próxima parcela',
    enableSorting: false,
    cell: ({ row }) => {
      const d = detailOf(row.original)
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'font-medium text-highlighted' }, formatDateTime(d.next_parcel_due_at)),
        h('p', { class: 'text-xs text-muted' }, formatAmountCents(d.next_parcel_amount_cents))
      ])
    }
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
      cnpjMasked: row.original.cnpj_masked
    })
  },
  {
    id: 'actions',
    header: 'Ações',
    enableHiding: false,
    enableSorting: false,
    cell: ({ row }) => {
      const orderId = detailOf(row.original).order_id
      const children = [
        h(FiscalDocumentAction, {
          document: row.original.document,
          disabled: !allowsDocument.value
        })
      ]
      if (orderId) {
        children.push(h(UButton, {
          size: 'xs',
          color: 'primary',
          variant: 'ghost',
          icon: 'i-lucide-panel-right-open',
          label: 'Ver pedido',
          onClick: () => openClientOrders(row.original)
        }))
      }
      return h('div', { class: 'flex items-center justify-end gap-1' }, children)
    }
  }
]

onMounted(() => {
  void loadModalities()
})
</script>

<template>
  <MonitoringModuleTable
    title="Parcelamentos"
    panel-id="monitoring-installments"
    module-key="installments"
    :columns="columns"
    :rows="displayRows"
    :loading="loading"
    :refreshing="refreshing"
    :error="loadError"
    :page="page"
    :last-page="lastPage"
    :total="displayTotal"
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
    empty-title="Nenhum parcelamento"
    :column-labels="{
      modality: 'Modalidade',
      order: 'Pedido',
      total: 'Saldo',
      parcels: 'Parcelas',
      next: 'Próxima parcela'
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
      <div
        class="flex w-full min-w-0 max-w-full items-center gap-2"
        data-testid="installments-modality-control"
      >
        <ShellScrollableTabs
          v-model="selectedInstallmentType"
          :items="installmentTypeTabs"
          size="md"
          class="w-full min-w-0 max-w-full"
          aria-label="Filtrar por tipo de parcelamento"
          test-id="installments-type-tabs"
        />
      </div>
    </template>

    <template #utilities>
      <UAlert
        v-if="modalitiesError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="modalitiesError || 'Catálogo local (PARCSN…RELPMEI)'"
        class="w-full"
      />
      <UAlert
        v-if="distinctOverviewError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="distinctOverviewError"
        class="w-full"
      />
    </template>

    <template #detail>
      <USlideover
        v-model:open="detailOpen"
        title="Detalhe do pedido"
      >
        <template #body>
          <div
            v-if="detailLoading"
            class="py-8 text-sm text-muted"
          >
            Carregando pedido…
          </div>
          <UAlert
            v-else-if="detailError"
            color="error"
            :title="detailError"
          />
          <div
            v-else-if="detailOrder"
            class="flex flex-col gap-4"
          >
            <div
              v-if="detailOrders.length > 1"
              class="flex min-w-0 gap-2 overflow-x-auto pb-1"
              data-testid="installments-order-navigation"
            >
              <UButton
                v-for="order in detailOrders"
                :key="order.id"
                size="xs"
                color="neutral"
                :variant="order.id === detailOrder.id ? 'solid' : 'outline'"
                :label="`${order.modality} · ${order.external_order_id}`"
                @click="openOrder(order.id, detailRow || undefined)"
              />
            </div>
            <div
              v-if="detailRow?.document"
              class="flex flex-wrap items-center gap-2"
            >
              <FiscalDocumentAction
                :document="detailRow.document"
                :disabled="!allowsDocument"
              />
            </div>
            <UAlert
              v-if="detailHasOverdueParcels"
              color="warning"
              icon="i-lucide-circle-alert"
              title="Há parcelas em atraso neste pedido"
              data-testid="installments-detail-overdue-alert"
            />

            <UPageCard
              title="Resumo do pedido"
              variant="subtle"
              data-testid="installments-order-summary"
            >
              <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                  <dt class="text-muted">
                    Pedido
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ detailOrder.external_order_id || detailOrder.id || '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Situação
                  </dt>
                  <dd class="mt-1">
                    <FiscalStatusBadge :status="String(detailOrder.situation || '')" />
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Modalidade
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ detailOrder.modality || '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Regime
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ detailOrder.regime || '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Valor consolidado
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ formatAmountCents(detailOrder.total_amount_cents as number | null) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Quantidade de parcelas
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ detailOrder.parcel_count ?? orderParcels.length }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Solicitado em
                  </dt>
                  <dd class="mt-1 text-highlighted">
                    {{ formatDateTime(detailOrder.requested_at as string | null) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Consolidado em
                  </dt>
                  <dd class="mt-1 text-highlighted">
                    {{ formatDateTime(detailOrder.consolidated_at as string | null) }}
                  </dd>
                </div>
              </dl>
            </UPageCard>
            <UPageCard
              v-if="orderPayments.length"
              title="Pagamentos confirmados"
              description="Demonstrativo persistido junto ao pedido; nenhuma chamada externa é feita ao navegar."
              variant="subtle"
              data-testid="installments-order-payments"
            >
              <div class="divide-y divide-default">
                <article
                  v-for="payment in orderPayments"
                  :key="payment.id"
                  class="grid gap-2 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                >
                  <div>
                    <p class="font-medium text-highlighted">
                      {{ payment.payment_ref || `Pagamento #${payment.id}` }}
                    </p>
                    <p class="mt-1 text-muted">
                      Pago em {{ formatDateTime(payment.paid_at) }}
                    </p>
                  </div>
                  <div class="flex items-center gap-2 sm:justify-end">
                    <span class="font-medium text-highlighted">
                      {{ formatAmountCents(payment.amount_cents) }}
                    </span>
                    <FiscalStatusBadge :status="payment.status" />
                  </div>
                </article>
              </div>
            </UPageCard>

            <UPageCard
              title="Parcelas e pagamentos"
              description="Histórico local do pedido. A abertura deste detalhe não realiza uma consulta fiscal."
              variant="subtle"
              data-testid="installments-order-parcels"
            >
              <div
                v-if="!orderParcels.length"
                class="flex flex-col items-center gap-2 py-6 text-center"
                data-testid="installments-order-parcels-empty"
              >
                <UIcon name="i-lucide-calendar-x" class="size-7 text-dimmed" />
                <p class="text-sm font-medium text-highlighted">
                  Nenhuma parcela disponível
                </p>
                <p class="text-sm text-muted">
                  O pedido foi importado sem parcelas associadas.
                </p>
              </div>
              <div
                v-else
                class="divide-y divide-default"
              >
                <article
                  v-for="parcel in orderParcels"
                  :key="String(parcel.id || parcel.parcel_key || parcelLabel(parcel))"
                  class="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                >
                  <div class="min-w-0">
                    <p class="font-medium text-highlighted">
                      Parcela {{ parcelLabel(parcel) }}
                    </p>
                    <p class="mt-1 text-sm text-muted">
                      Vencimento {{ formatDateTime(parcel.due_at as string | null) }}
                      <span v-if="parcel.paid_at"> · Pago em {{ formatDateTime(parcel.paid_at as string | null) }}</span>
                    </p>
                  </div>
                  <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <span class="font-medium text-highlighted">
                      {{ formatAmountCents(parcel.amount_cents as number | null) }}
                    </span>
                    <FiscalStatusBadge :status="parcelStatus(parcel)" />
                    <FiscalStatusBadge
                      v-if="parcelPaymentStatus(parcel)"
                      :status="parcelPaymentStatus(parcel) || ''"
                    />
                    <UBadge
                      v-if="parcel.document_available"
                      color="success"
                      variant="subtle"
                      icon="i-lucide-file-check-2"
                      label="Guia disponível"
                    />
                  </div>
                </article>
              </div>
            </UPageCard>
          </div>
        </template>
      </USlideover>
    </template>
  </MonitoringModuleTable>
</template>

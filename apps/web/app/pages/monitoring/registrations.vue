<script setup lang="ts">
/**
 * Cadastro e vínculos (PNR Contador) — lista tenant-scoped via MonitoringModuleTable.
 * Arquétipo customers.vue; sem office_id no request; sem segredos.
 */
import type { TableColumn } from '@nuxt/ui'
import type {
  FiscalRegistrationLink,
  MonitoringFilterConfig,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import { tableCellBadgeProps } from '~/utils/table-ui'

const UButton = resolveComponent('UButton')
const UBadge = resolveComponent('UBadge')

const api = useApi()
const { canTriggerSync, sessionEpoch } = useDashboard()
const toast = useToast()

const loading = ref(false)
const refreshingClientId = ref<number | null>(null)
const loadError = ref<string | null>(null)
const rows = ref<FiscalRegistrationLink[]>([])
const page = ref(1)
const perPage = ref(20)
const lastPage = ref(1)
const total = ref(0)
const q = ref('')
const status = ref('all')
const clientId = ref<number | null>(null)
const sorting = ref<{ id: string, desc: boolean }[]>([])
let loadSeq = 0
let filterTransactionDepth = 0

const statusItems = [
  { label: 'Todos', value: 'all' },
  { label: 'Ativo', value: 'ACTIVE' },
  { label: 'Desconhecido', value: 'UNKNOWN' }
]
const filters = computed<MonitoringFilterValue>(() => normalizeMonitoringFilters({
  q: q.value,
  status: status.value,
  clientIds: clientId.value != null && clientId.value >= 1 ? [clientId.value] : []
}))
const filterConfig: MonitoringFilterConfig = {
  search: {
    placeholder: 'Buscar cliente ou vínculo…',
    ariaLabel: 'Buscar por cliente, CNPJ ou vínculo'
  },
  fields: [
    { key: 'status', kind: 'option', label: 'Status', items: statusItems },
    { key: 'clientId', kind: 'client', label: 'Cliente', multiple: false }
  ]
}

async function applyFilters(nextValue: MonitoringFilterValue) {
  const next = normalizeMonitoringFilters(nextValue)
  if (q.value === next.q && status.value === next.status && (clientId.value ?? null) === (next.clientIds[0] ?? null) && next.clientIds.length <= 1) return
  filterTransactionDepth += 1
  try {
    q.value = next.q
    status.value = next.status
    clientId.value = next.clientIds[0] ?? null
    page.value = 1
    await nextTick()
  } finally {
    filterTransactionDepth -= 1
  }
  await load()
}

function resetFilters() {
  void applyFilters(resetMonitoringFilters())
}

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.fiscal.registrations.list({
      page: page.value,
      per_page: perPage.value,
      q: q.value || undefined,
      status: status.value === 'all' ? undefined : status.value,
      client_id: clientId.value != null && clientId.value >= 1 ? clientId.value : undefined
    })
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = res.data || []
    const meta = res.meta
    total.value = meta?.total ?? rows.value.length
    lastPage.value = meta?.last_page ?? 1
    if (typeof meta?.per_page === 'number') perPage.value = meta.per_page
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = []
    total.value = 0
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar vínculos.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) loading.value = false
  }
}

async function setPage(next: number) {
  page.value = Math.max(1, Math.floor(Number(next) || 1))
  await load()
}

async function setPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (perPage.value === target) return
  perPage.value = target
  page.value = 1
  await load()
}

async function refreshClient(clientId: number) {
  if (!canTriggerSync.value) return
  refreshingClientId.value = clientId
  try {
    await api.fiscal.registrations.refresh(clientId)
    toast.add({ title: 'Refresh enfileirado', color: 'success' })
  } catch (caught) {
    toast.add({
      title: 'Falha ao enfileirar refresh',
      description: apiErrorMessage(caught, 'Erro desconhecido'),
      color: 'error'
    })
  } finally {
    refreshingClientId.value = null
  }
}

function clientHref(id: number) {
  return `/monitoring/clients/${id}/registrations`
}

const columns: TableColumn<FiscalRegistrationLink>[] = [
  {
    id: 'client',
    accessorKey: 'client_id',
    header: 'Cliente',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => h(UButton, {
      variant: 'link',
      color: 'primary',
      to: clientHref(row.original.client_id),
      label: String(row.original.client_id)
    })
  },
  {
    accessorKey: 'link_key',
    header: 'Vínculo',
    enableSorting: false
  },
  {
    accessorKey: 'status',
    header: 'Status',
    enableSorting: false,
    cell: ({ row }) => h('div', { class: 'block w-full min-w-0' }, [
      h(UBadge, tableCellBadgeProps({
        color: row.original.status === 'ACTIVE' ? 'success' : 'neutral',
        label: row.original.status
      }))
    ])
  },
  {
    id: 'source',
    header: 'Fonte',
    enableSorting: false,
    cell: ({ row }) => row.original.is_simulated ? 'Simulado' : 'SERPRO'
  },
  {
    id: 'refreshed',
    header: 'Atualizado',
    enableSorting: false,
    cell: ({ row }) => row.original.refreshed_at || row.original.observed_at || '—'
  },
  {
    id: 'actions',
    header: 'Ações',
    enableHiding: false,
    enableSorting: false,
    cell: ({ row }) => canTriggerSync.value
      ? h(UButton, {
          'size': 'xs',
          'variant': 'soft',
          'icon': 'i-lucide-refresh-cw',
          'loading': refreshingClientId.value === row.original.client_id,
          'aria-label': `Atualizar vínculos do cliente ${row.original.client_id}`,
          'onClick': () => refreshClient(row.original.client_id)
        })
      : null
  }
]

watch([q, status, clientId], () => {
  if (filterTransactionDepth > 0) return
  page.value = 1
  void load()
}, { immediate: true })
watch(sessionEpoch, () => {
  loadSeq += 1
  filterTransactionDepth += 1
  try {
    q.value = ''
    status.value = 'all'
    clientId.value = null
    rows.value = []
    total.value = 0
    lastPage.value = 1
    page.value = 1
  } finally {
    filterTransactionDepth -= 1
  }
  void load()
})
</script>

<template>
  <MonitoringModuleTable
    title="Cadastro e Vínculos"
    panel-id="monitoring-registrations"
    surface="monitoring.registrations"
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
    :get-row-id="row => `registration:${row.id}`"
    :show-kpis="false"
    :horizontal-scroll="false"
    empty-title="Nenhum vínculo"
    empty-description="Atualize por cliente."
    :column-labels="{
      link_key: 'Vínculo',
      status: 'Status',
      source: 'Fonte',
      refreshed: 'Atualizado'
    }"
    @update:page="setPage"
    @update:per-page="setPerPage"
    @update:sorting="sorting = $event"
    @apply-filters="applyFilters"
    @reset-filters="resetFilters"
    @refresh="load"
  />
</template>

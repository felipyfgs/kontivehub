<script setup lang="ts">
/**
 * Lista admin de clientes — corpo do #body (customers.vue @ 0f30c09).
 * Fragmento: toolbar · UTable.shrink-0 · footer mt-auto (filhos do painel).
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { Client, ClientCategory, ClientListStats } from '~/types/api'
import { upperFirst } from 'scule'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { buildClientsColumns } from '~/utils/clients-table'
import { clientsColumnLabels } from '~/utils/clients-table-labels'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import {
  clientsFiltersToPayload,
  clientsPayloadToFilters,
  hasActiveClientsFiltersForSave
} from '~/utils/saved-list-filters'
import type { DashboardKpiItem } from '~/utils/kpi-ui'
import {
  CLIENTS_LIST_QUERY_SCHEMA,
  serializeListFilterQuery,
  useListFilterQuery
} from '~/composables/useListFilterQuery'
import ShellKpiStrip from '~/components/shell/KpiStrip.vue'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import { clientSectionPath } from '~/composables/useClientDetail'
import { normalizeCnpj } from '~/utils/format'
import { apiErrorMessage } from '~/utils/api-error'
import { CLIENT_TAX_REGIME_FILTER_ITEMS } from '~/utils/clients-tax-regime'
import { normalizeListTablePerPage } from '~/utils/table-ui'
import { monitoringDestinationAfterClientCreate } from '~/utils/monitoring-post-create'

const CLIENT_COLUMN_LABELS = clientsColumnLabels()
const UCheckbox = resolveComponent('UCheckbox')

function getClientRowId(row: Client) {
  return String(row.id)
}

/** Viewport &lt; md: cards via ShellDataTable; desktop: UTable. */
const breakpoints = useBreakpoints(breakpointsTailwind)
const useMobileCards = breakpoints.smaller('md')
const isNarrow = breakpoints.smaller('sm')
const paginationSiblingCount = computed(() => (isNarrow.value ? 0 : 1))

const api = useApi()
const route = useRoute()
const router = useRouter()
const {
  canManageClients,
  canManageClientCategoryCatalog,
  canAssignClientCategories,
  canManageCredentials,
  isClientFormOpen,
  clientFormCreateNonce,
  sessionEpoch
} = useDashboard()
const toast = useToast()
const clientsListQuery = useListFilterQuery(CLIENTS_LIST_QUERY_SCHEMA)

const table = useTemplateRef<{ tableApi?: {
  getAllColumns: () => Array<{
    id: string
    getCanHide: () => boolean
    getIsVisible: () => boolean
    getColumn: (id: string) => { toggleVisibility: (v: boolean) => void } | undefined
  }>
  getColumn: (id: string) => { toggleVisibility: (v: boolean) => void } | undefined
  resetRowSelection: () => void
} } | null>('shellTable')

const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([{ id: 'legal_name', desc: false }])
const page = ref(1)
const perPage = ref(20)
const total = ref(0)
const lastPage = ref(1)
const search = ref('')
const categoryIdsFilter = ref('')
const taxRegimesFilter = ref('')
const procuracaoStatusesFilter = ref('')

const clients = ref<Client[]>([])
const clientCategories = ref<ClientCategory[]>([])
const categoriesLoading = ref(false)
const stats = ref<ClientListStats>({
  total: 0,
  active: 0,
  with_credential: 0,
  without_credential: 0,
  credential_expiring_30d: 0,
  credential_expired: 0,
  capture_problem: 0
})
const loading = ref(false)
const loadError = ref<string | null>(null)

const formOpen = isClientFormOpen
const formClient = ref<Client | null>(null)
const detailOpen = ref(false)
const detailClientId = ref<number | null>(null)
const detailSection = ref<'resumo' | 'cadastro' | 'certificado' | 'sincronizacao'>('resumo')
const credentialModalOpen = ref(false)
const credentialModalClient = ref<Client | null>(null)
const deleteOpen = ref(false)
const deleteClient = ref<Client | null>(null)
const deleting = ref(false)
const bulkStatusOpen = ref(false)
const bulkStatusTarget = ref(true)
const bulkStatusClientIds = ref<number[]>([])
const bulkStatusSubmitting = ref(false)
const categoryManagerOpen = ref(false)
const categoryAssignmentOpen = ref(false)
const categoryAssignmentMode = ref<'replace' | 'add' | 'remove'>('replace')
const categoryAssignmentClient = ref<Client | null>(null)
const categoryAssignmentClientIds = ref<number[]>([])

const emptyStats: ClientListStats = {
  total: 0,
  active: 0,
  with_credential: 0,
  without_credential: 0,
  credential_expiring_30d: 0,
  credential_expired: 0,
  capture_problem: 0
}

/**
 * Espelho do campo de busca do template, com consulta server-side.
 */
const filter = computed({
  get: (): string => search.value,
  set: (value: string) => {
    search.value = value
    page.value = 1
  }
})

type KpiFilter
  = | 'total'
    | 'with_credential'
    | 'without_credential'
    | 'expiring'
    | 'credential_expired'
    | 'capture_problem'

/** Filtro dos cards KPI (clique = filtra a tabela pelo conteúdo do card). */
const kpiFilter = ref<KpiFilter>('total')
const statusFilter = ref('all')

const CLIENT_PROCURACAO_FILTER_ITEMS = [
  { label: 'Autorizada', value: 'authorized' },
  { label: 'A vencer', value: 'expiring' },
  { label: 'Vencida', value: 'expired' },
  { label: 'Sem procuração', value: 'missing' },
  { label: 'Não verificada', value: 'unverified' }
]

const clientFilterDefinitions = computed<DataTableFilterDefinition[]>(() => [
  {
    key: 'status',
    kind: 'option',
    label: 'Estado',
    emptyValue: 'all',
    items: [
      { label: 'Ativos', value: 'active' },
      { label: 'Inativos', value: 'inactive' }
    ]
  },
  {
    key: 'category_ids',
    kind: 'option',
    label: 'Categorias',
    emptyValue: '',
    multiple: true,
    items: clientCategories.value.map(category => ({
      label: category.is_active ? category.name : `${category.name} (arquivada)`,
      value: String(category.id)
    }))
  },
  {
    key: 'tax_regimes',
    kind: 'option',
    label: 'Regime tributário',
    emptyValue: '',
    multiple: true,
    items: CLIENT_TAX_REGIME_FILTER_ITEMS
  },
  {
    key: 'procuracao_statuses',
    kind: 'option',
    label: 'Procuração',
    emptyValue: '',
    multiple: true,
    items: CLIENT_PROCURACAO_FILTER_ITEMS
  }
])

function modelsFromClientStatus(): DataTableFilterModel[] {
  const values: Array<[string, string]> = [
    ['status', statusFilter.value],
    ['category_ids', categoryIdsFilter.value],
    ['tax_regimes', taxRegimesFilter.value],
    ['procuracao_statuses', procuracaoStatusesFilter.value]
  ]
  return values.flatMap(([key, value]) => {
    const definition = findDefinition(clientFilterDefinitions.value, key)
    if (!definition) return []
    const model = createFilterModel(definition, value)
    return model ? [model] : []
  })
}

const chipModels = ref<DataTableFilterModel[]>(modelsFromClientStatus())

function syncClientChips() {
  chipModels.value = modelsFromClientStatus()
}

function onStructuredFilters(models: DataTableFilterModel[]) {
  const statusModel = models.find(m => m.key === 'status')
  const categoriesModel = models.find(m => m.key === 'category_ids')
  const taxRegimesModel = models.find(m => m.key === 'tax_regimes')
  const procuracaoModel = models.find(m => m.key === 'procuracao_statuses')
  statusFilter.value = statusModel ? String(statusModel.value) : 'all'
  categoryIdsFilter.value = categoriesModel ? String(categoriesModel.value) : ''
  taxRegimesFilter.value = taxRegimesModel ? String(taxRegimesModel.value) : ''
  procuracaoStatusesFilter.value = procuracaoModel ? String(procuracaoModel.value) : ''
  chipModels.value = models
  page.value = 1
}

function onClearStructuredFilters() {
  statusFilter.value = 'all'
  categoryIdsFilter.value = ''
  taxRegimesFilter.value = ''
  procuracaoStatusesFilter.value = ''
  chipModels.value = []
  page.value = 1
}

const CLIENT_KPI_KEYS = new Set<string>([
  'total',
  'with_credential',
  'without_credential',
  'expiring',
  'credential_expired',
  'capture_problem'
])

function hydrateClientsFromQuery() {
  const state = clientsListQuery.read()
  search.value = String(state.q ?? '')
  statusFilter.value = String(state.status ?? 'all')
  categoryIdsFilter.value = String(state.category_ids ?? '')
  taxRegimesFilter.value = String(state.tax_regimes ?? '')
  procuracaoStatusesFilter.value = String(state.procuracao_statuses ?? '')
  const op = String(state.operational_filter ?? 'total')
  kpiFilter.value = (CLIENT_KPI_KEYS.has(op) ? op : 'total') as KpiFilter
  page.value = Math.max(1, Number(state.page) || 1)
  perPage.value = Math.max(1, Number(state.per_page) || 20)
  const sortIdRaw = String(state.sort ?? 'legal_name')
  const sortId = sortIdRaw === 'is_active' || sortIdRaw === 'tax_regime' ? sortIdRaw : 'legal_name'
  const desc = String(state.sort_direction ?? 'asc') === 'desc'
  sorting.value = [{ id: sortId, desc }]
  syncClientChips()
}

async function syncClientsUrl() {
  const sort = sorting.value[0]
  const query = serializeListFilterQuery({
    q: search.value,
    status: statusFilter.value,
    operational_filter: kpiFilter.value,
    page: page.value,
    per_page: perPage.value,
    category_ids: categoryIdsFilter.value,
    tax_regimes: taxRegimesFilter.value,
    procuracao_statuses: procuracaoStatusesFilter.value,
    sort: sort?.id === 'is_active' || sort?.id === 'tax_regime'
      ? sort.id
      : 'legal_name',
    sort_direction: sort?.desc ? 'desc' : 'asc'
  }, CLIENTS_LIST_QUERY_SCHEMA)
  await router.replace({ path: route.path, query })
}

hydrateClientsFromQuery()

/** Evita double-fetch quando preset hidrata vários refs de uma vez. */
let applyingClientsPreset = false

function applyClientsPresetPayload(payload: SavedListFilterPayload) {
  const next = clientsPayloadToFilters(payload)
  applyingClientsPreset = true
  if (searchTimer) {
    clearTimeout(searchTimer)
    searchTimer = null
  }
  search.value = next.q
  statusFilter.value = next.status
  categoryIdsFilter.value = next.category_ids
  taxRegimesFilter.value = next.tax_regimes
  procuracaoStatusesFilter.value = next.procuracao_statuses
  kpiFilter.value = (CLIENT_KPI_KEYS.has(next.operational_filter)
    ? next.operational_filter
    : 'total') as KpiFilter
  page.value = 1
  syncClientChips()
  void nextTick(() => {
    applyingClientsPreset = false
    void load()
  })
}

function applyKpiFilter(key: KpiFilter) {
  // segundo clique no mesmo card limpa (volta ao total)
  kpiFilter.value = kpiFilter.value === key && key !== 'total' ? 'total' : key
  statusFilter.value = 'all'
  syncClientChips()
  page.value = 1
}

/**
 * KPIs de cadastro (contagens reais da API):
 * total · com certificado · sem certificado · a vencer · vencido · captura problemática
 */
const kpiItems = computed((): DashboardKpiItem[] => [
  {
    key: 'total',
    title: 'Total',
    value: loading.value && !clients.value.length ? '…' : stats.value.total,
    icon: 'i-lucide-users',
    ariaLabel: 'Filtrar lista: Total'
  },
  {
    key: 'with_credential',
    title: 'Com certificado',
    value: loading.value && !clients.value.length
      ? '…'
      : (stats.value.with_credential ?? Math.max(0, stats.value.total - stats.value.without_credential)),
    icon: 'i-lucide-badge-check',
    ariaLabel: 'Filtrar lista: Com certificado'
  },
  {
    key: 'without_credential',
    title: 'Sem certificado',
    value: loading.value && !clients.value.length ? '…' : stats.value.without_credential,
    icon: 'i-lucide-shield-off',
    ariaLabel: 'Filtrar lista: Sem certificado'
  },
  {
    key: 'expiring',
    title: 'A vencer (30d)',
    value: loading.value && !clients.value.length ? '…' : stats.value.credential_expiring_30d,
    icon: 'i-lucide-badge-alert',
    ariaLabel: 'Filtrar lista: A vencer (30d)'
  },
  {
    key: 'credential_expired',
    title: 'Certificado vencido',
    value: loading.value && !clients.value.length ? '…' : stats.value.credential_expired,
    icon: 'i-lucide-badge-x',
    ariaLabel: 'Filtrar lista: Certificado vencido'
  },
  {
    key: 'capture_problem',
    title: 'Captura problemática',
    value: loading.value && !clients.value.length ? '…' : (stats.value.capture_problem ?? 0),
    icon: 'i-lucide-triangle-alert',
    ariaLabel: 'Filtrar lista: Captura problemática'
  }
])

function onKpiSelect(key: string) {
  if (!CLIENT_KPI_KEYS.has(key)) return
  applyKpiFilter(key as KpiFilter)
}

function openCredentialModal(client: Client) {
  credentialModalClient.value = client
  credentialModalOpen.value = true
}

async function copyCnpj(value?: string | null) {
  const clean = normalizeCnpj(value)
  if (!clean) return
  try {
    await navigator.clipboard.writeText(clean)
    toast.add({
      title: 'CNPJ copiado',
      description: clean,
      color: 'success'
    })
  } catch {
    toast.add({ title: 'Não foi possível copiar o CNPJ', color: 'error' })
  }
}

function openPage(
  client: Client,
  section: 'resumo' | 'cadastro' | 'certificado' | 'sincronizacao' = 'resumo'
) {
  navigateTo(clientSectionPath(client.id, section))
}

function openModal(
  client: Client,
  section: 'resumo' | 'cadastro' | 'certificado' | 'sincronizacao' = 'resumo'
) {
  detailClientId.value = client.id
  detailSection.value = section
  detailOpen.value = true
}

function openClientCategories(client: Client) {
  if (!canAssignClientCategories.value) return
  categoryAssignmentMode.value = 'replace'
  categoryAssignmentClient.value = client
  categoryAssignmentClientIds.value = [client.id]
  categoryAssignmentOpen.value = true
}

function openBulkCategories(operation: 'add' | 'remove') {
  if (!canAssignClientCategories.value || !selectedClients.value.length) return
  categoryAssignmentMode.value = operation
  categoryAssignmentClient.value = null
  categoryAssignmentClientIds.value = selectedClients.value.map(client => client.id)
  categoryAssignmentOpen.value = true
}

/**
 * Colunas com cell renderers — desktop (UTable) e mobile (ModuleMobileCards).
 * Razão social (+ CNPJ) · Certificado · Procuração · Estado · Regime · Ações
 */
const columns = computed(() => buildClientsColumns({
  canManageClients: canManageClients.value,
  canAssignClientCategories: canAssignClientCategories.value,
  canManageCredentials: canManageCredentials.value,
  onOpenPage: client => openPage(client),
  onOpenModal: client => openModal(client),
  onEdit: client => openEditForm(client),
  onManageCategories: client => openClientCategories(client),
  onAskDelete: client => askDelete(client),
  onReactivate: client => reactivateClient(client),
  onOpenCredential: client => openCredentialModal(client),
  onCopyCnpj: value => void copyCnpj(value),
  onCredentialToast: (title, description) => {
    toast.add({ title, description, color: 'warning' })
  }
}))

const selectColumn = computed<TableColumn<Client>>(() => ({
  id: 'select',
  enableHiding: false,
  enableSorting: false,
  meta: {
    class: {
      th: 'w-10 min-w-10',
      td: 'w-10 min-w-10'
    }
  },
  header: ({ table: current }) => h(UCheckbox, {
    'modelValue': current.getIsSomePageRowsSelected()
      ? 'indeterminate'
      : current.getIsAllPageRowsSelected(),
    'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
      current.toggleAllPageRowsSelected(!!value),
    'ariaLabel': 'Selecionar todos os clientes desta página'
  }),
  cell: ({ row }) => h(UCheckbox, {
    'modelValue': row.getIsSelected(),
    'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
    'ariaLabel': `Selecionar ${row.original.legal_name || row.original.name}`
  })
}))

const tableColumns = computed<TableColumn<Client>[]>(() =>
  canManageClients.value ? [selectColumn.value, ...columns.value] : columns.value
)

const selectedClients = computed(() =>
  clients.value.filter(client => rowSelection.value[getClientRowId(client)] === true)
)
const selectedActiveClients = computed(() => selectedClients.value.filter(client => client.is_active))
const selectedInactiveClients = computed(() => selectedClients.value.filter(client => !client.is_active))

function clearSelection() {
  rowSelection.value = {}
  table.value?.tableApi?.resetRowSelection?.()
}

function askBulkStatus(isActive: boolean) {
  const candidates = isActive ? selectedInactiveClients.value : selectedActiveClients.value
  if (!canManageClients.value || !candidates.length) return
  bulkStatusTarget.value = isActive
  bulkStatusClientIds.value = candidates.map(client => client.id)
  bulkStatusOpen.value = true
}

const bulkStatusItems = computed<DropdownMenuItem[][]>(() => {
  const actions: DropdownMenuItem[] = []

  if (canAssignClientCategories.value) {
    actions.push(
      {
        label: 'Adicionar categorias',
        icon: 'i-lucide-tags',
        disabled: !clientCategories.value.some(category => category.is_active),
        onSelect: () => openBulkCategories('add')
      },
      {
        label: 'Remover categorias',
        icon: 'i-lucide-tag',
        disabled: !clientCategories.value.length,
        onSelect: () => openBulkCategories('remove')
      }
    )
  }

  if (selectedActiveClients.value.length) {
    actions.push({
      label: `Inativar ativos (${selectedActiveClients.value.length})`,
      icon: 'i-lucide-user-round-x',
      color: 'error',
      disabled: bulkStatusSubmitting.value,
      onSelect: () => askBulkStatus(false)
    })
  }

  if (selectedInactiveClients.value.length) {
    actions.push({
      label: `Reativar inativos (${selectedInactiveClients.value.length})`,
      icon: 'i-lucide-rotate-ccw',
      disabled: bulkStatusSubmitting.value,
      onSelect: () => askBulkStatus(true)
    })
  }

  return [
    actions,
    [{
      label: 'Limpar seleção',
      icon: 'i-lucide-x',
      disabled: bulkStatusSubmitting.value,
      onSelect: clearSelection
    }]
  ]
})

async function confirmBulkStatus() {
  if (!canManageClients.value || !bulkStatusClientIds.value.length) return
  bulkStatusSubmitting.value = true
  try {
    const target = bulkStatusTarget.value
    const response = await api.clients.bulkStatus({
      client_ids: bulkStatusClientIds.value,
      is_active: target,
      inactive_reason: target ? null : 'Inativado em massa pela lista de clientes'
    })
    toast.add({
      title: target ? 'Clientes reativados' : 'Clientes inativados',
      description: `${response.data.updated} cliente(s) atualizado(s).`,
      color: 'success'
    })
    bulkStatusOpen.value = false
    bulkStatusClientIds.value = []
    clearSelection()
    await load()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível atualizar os clientes selecionados.'),
      color: 'error'
    })
  } finally {
    bulkStatusSubmitting.value = false
  }
}

function askDelete(client: Client) {
  deleteClient.value = client
  deleteOpen.value = true
}

async function confirmDelete() {
  if (!deleteClient.value || !canManageClients.value) return
  deleting.value = true
  try {
    await api.clients.update(deleteClient.value.id, {
      is_active: false,
      inactive_reason: 'Inativado pela lista de clientes'
    })
    toast.add({
      title: 'Cliente inativado',
      description: deleteClient.value.legal_name || deleteClient.value.name,
      color: 'success'
    })
    deleteOpen.value = false
    deleteClient.value = null
    await load()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível inativar o cliente.'),
      color: 'error'
    })
  } finally {
    deleting.value = false
  }
}

async function reactivateClient(client: Client) {
  if (!canManageClients.value) return
  try {
    await api.clients.update(client.id, {
      is_active: true,
      inactive_reason: null
    })
    toast.add({
      title: 'Cliente reativado',
      description: client.legal_name || client.name,
      color: 'success'
    })
    await load()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível reativar o cliente.'),
      color: 'error'
    })
  }
}

let categoryLoadSequence = 0

async function loadCategories() {
  const sequence = ++categoryLoadSequence
  categoriesLoading.value = true
  try {
    const response = await api.clientCategories.list(true)
    if (sequence !== categoryLoadSequence) return
    clientCategories.value = response.data
    syncClientChips()
  } catch (caught) {
    if (sequence !== categoryLoadSequence) return
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível carregar as categorias de clientes.'),
      color: 'error'
    })
  } finally {
    if (sequence === categoryLoadSequence) categoriesLoading.value = false
  }
}

async function onCategoryCatalogUpdated() {
  await Promise.all([loadCategories(), load()])
}

async function onCategoryAssignmentSaved() {
  const wasBulk = categoryAssignmentMode.value !== 'replace'
  categoryAssignmentClient.value = null
  categoryAssignmentClientIds.value = []
  if (wasBulk) clearSelection()
  await Promise.all([loadCategories(), load()])
}

let loadSequence = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

/** Carrega somente o recorte solicitado; filtros e ordenação são aplicados pela API. */
async function load() {
  const sequence = ++loadSequence
  const epoch = sessionEpoch.value
  clearSelection()
  loading.value = true
  loadError.value = null
  try {
    await syncClientsUrl()
    const sort = sorting.value[0]
    const sortId = sort?.id === 'is_active' || sort?.id === 'tax_regime'
      ? sort.id
      : 'legal_name'
    const response = await api.clients.list({
      page: page.value,
      per_page: perPage.value,
      q: search.value.trim() || undefined,
      is_active: statusFilter.value === 'all' ? undefined : statusFilter.value === 'active',
      operational_filter: kpiFilter.value === 'total' ? undefined : kpiFilter.value,
      category_ids: categoryIdsFilter.value || undefined,
      tax_regimes: taxRegimesFilter.value || undefined,
      procuracao_statuses: procuracaoStatusesFilter.value || undefined,
      sort: sortId,
      direction: sort?.desc ? 'desc' : 'asc'
    })
    if (sequence !== loadSequence || epoch !== sessionEpoch.value) return
    clients.value = response.data
    total.value = response.meta.total
    lastPage.value = response.meta.last_page
    stats.value = response.meta.stats || {
      ...emptyStats,
      total: response.meta.total
    }
  } catch (caught) {
    if (sequence !== loadSequence || epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Erro ao listar clientes.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (sequence === loadSequence && epoch === sessionEpoch.value) loading.value = false
  }
}

function resetTenantScopedList() {
  clearSelection()
  clients.value = []
  total.value = 0
  lastPage.value = 1
  page.value = 1
  search.value = ''
  kpiFilter.value = 'total'
  statusFilter.value = 'all'
  categoryIdsFilter.value = ''
  taxRegimesFilter.value = ''
  procuracaoStatusesFilter.value = ''
  chipModels.value = []
  clientCategories.value = []
  stats.value = { ...emptyStats }
  formOpen.value = false
  formClient.value = null
  detailOpen.value = false
  detailClientId.value = null
  categoryManagerOpen.value = false
  categoryAssignmentOpen.value = false
  categoryAssignmentClient.value = null
  categoryAssignmentClientIds.value = []
  loadError.value = null
  void Promise.all([loadCategories(), load()])
}

function openCreateForm() {
  formClient.value = null
  formOpen.value = true
}

function openEditForm(client: Client) {
  formClient.value = client
  formOpen.value = true
}

async function onFormSaved(payload: {
  id: number
  mode: 'create' | 'edit'
  section?: 'resumo' | 'certificado'
  tax_regime?: string | null
}) {
  formOpen.value = false
  formClient.value = null
  await load()
  if (payload.mode === 'create') {
    const monitoring = monitoringDestinationAfterClientCreate(payload.tax_regime)
    if (monitoring) {
      await navigateTo(monitoring.path)
      return
    }
    await navigateTo(clientSectionPath(payload.id, payload.section || 'resumo'))
  }
}

/** Navbar / palette / onboarding: "Novo cliente" sempre em modo create. */
watch(clientFormCreateNonce, () => {
  formClient.value = null
  formOpen.value = true
})

/** Fecha modal → limpa cliente residual (evita reabrir em edição). */
watch(formOpen, (open) => {
  if (!open) formClient.value = null
})

watch(canManageClients, (enabled) => {
  if (!enabled) clearSelection()
})

watch(canManageClientCategoryCatalog, (enabled) => {
  if (!enabled) categoryManagerOpen.value = false
})

watch(canAssignClientCategories, (enabled) => {
  if (!enabled) {
    categoryAssignmentOpen.value = false
    categoryAssignmentClient.value = null
  }
})

watch(search, () => {
  if (applyingClientsPreset) return
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 350)
})

/** Mudança de recorte: volta à página 1 sem double-fetch quando já está nela. */
watch([statusFilter, kpiFilter, perPage, categoryIdsFilter, taxRegimesFilter, procuracaoStatusesFilter], () => {
  if (applyingClientsPreset) return
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
})

watch(page, () => {
  if (applyingClientsPreset) return
  void load()
})

watch(sorting, () => {
  if (applyingClientsPreset) return
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
}, { deep: true })

onMounted(() => void Promise.all([loadCategories(), load()]))

watch(sessionEpoch, () => {
  resetTenantScopedList()
})

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer)
})
</script>

<template>
  <ShellKpiStrip
    :items="kpiItems"
    :loading="loading && !clients.length"
    :active-key="kpiFilter"
    interactive
    test-id="clients-stats"
    :columns="6"
    @select="onKpiSelect"
  />

  <ShellListFilterToolbar
    :q="search"
    search-placeholder="Filtrar por nome ou CNPJ/CPF..."
    search-aria-label="Filtrar por nome ou CNPJ/CPF"
    :definitions="clientFilterDefinitions"
    :models="chipModels"
    :loading="loading"
    :reset-key="sessionEpoch"
    surface="clients.index"
    :get-payload="() => clientsFiltersToPayload({
      q: search,
      status: statusFilter,
      operational_filter: kpiFilter,
      category_ids: categoryIdsFilter,
      tax_regimes: taxRegimesFilter,
      procuracao_statuses: procuracaoStatusesFilter
    })"
    :can-save="() => hasActiveClientsFiltersForSave({
      q: search,
      status: statusFilter,
      operational_filter: kpiFilter,
      category_ids: categoryIdsFilter,
      tax_regimes: taxRegimesFilter,
      procuracao_statuses: procuracaoStatusesFilter
    })"
    test-id-prefix="clients-filter"
    @update:q="(value) => { filter = value }"
    @update:models="onStructuredFilters"
    @clear="onClearStructuredFilters"
    @refresh="load"
    @apply-preset="applyClientsPresetPayload"
  >
    <template #actions>
      <div
        v-if="selectedClients.length"
        data-testid="clients-bulk-actions"
      >
        <UDropdownMenu
          :items="bulkStatusItems"
          :content="{ align: 'start' }"
        >
          <UButton
            color="neutral"
            variant="subtle"
            icon="i-lucide-list-checks"
            label="Ações"
            aria-label="Ações em massa"
            :ui="COMPACT_BUTTON_LABEL_UI"
            :loading="bulkStatusSubmitting"
            data-testid="clients-bulk-actions-menu"
          >
            <template #trailing>
              <UKbd>{{ selectedClients.length }}</UKbd>
            </template>
          </UButton>
        </UDropdownMenu>
      </div>
    </template>

    <template #trailing>
      <div class="flex flex-wrap items-center gap-1.5">
        <UButton
          v-if="canManageClientCategoryCatalog"
          label="Categorias"
          icon="i-lucide-tags"
          color="neutral"
          variant="outline"
          aria-label="Gerenciar categorias de clientes"
          :ui="COMPACT_BUTTON_LABEL_UI"
          @click="() => { categoryManagerOpen = true }"
        />

        <UDropdownMenu
          v-if="!useMobileCards"
          :items="
            table?.tableApi
              ?.getAllColumns()
              .filter((column: any) => column.getCanHide())
              .map((column: any) => ({
                label: CLIENT_COLUMN_LABELS[column.id] || upperFirst(column.id),
                type: 'checkbox' as const,
                checked: column.getIsVisible(),
                onUpdateChecked(checked: boolean) {
                  table?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
                },
                onSelect(e?: Event) {
                  e?.preventDefault()
                }
              }))
          "
          :content="{ align: 'end' }"
        >
          <UButton
            label="Colunas"
            color="neutral"
            variant="outline"
            trailing-icon="i-lucide-settings-2"
            aria-label="Exibir colunas"
            :ui="COMPACT_BUTTON_LABEL_UI"
          />
        </UDropdownMenu>
      </div>
    </template>
  </ShellListFilterToolbar>

  <ShellLoadError
    v-if="loadError"
    color="warning"
    :title="loadError"
    @retry="load"
  />

  <ShellDataTable
    ref="shellTable"
    v-model:column-visibility="columnVisibility"
    v-model:row-selection="rowSelection"
    v-model:sorting="sorting"
    ui-preset="monitoring-compact"
    test-id="data-table"
    footer-test-id="clients-pagination"
    mobile-cards-test-id="fiscal-mobile-cards"
    :mobile-cards="true"
    :selection-enabled="canManageClients"
    :columns="tableColumns"
    :data="clients"
    :loading="loading"
    :page="page"
    :total="total"
    :items-per-page="perPage"
    :get-row-id="getClientRowId"
    :manual-sorting="true"
    :selected-count="canManageClients ? selectedClients.length : 0"
    :sibling-count="paginationSiblingCount"
    :show-edges="!isNarrow"
    :column-labels="CLIENT_COLUMN_LABELS"
    primary-column-id="legal_name"
    status-column-id="is_active"
    :summary-column-ids="['credential', 'tax_regime']"
    empty-title="Nenhum cliente encontrado"
    empty-description="Cadastre o primeiro cliente para começar."
    :error="loadError"
    per-page-aria-label="Clientes por página"
    @update:page="page = $event"
    @update:items-per-page="(n) => { perPage = normalizeListTablePerPage(n, 20) }"
    @retry="load"
  >
    <template #empty>
      <UEmpty
        v-if="!loadError"
        icon="i-lucide-building-2"
        title="Nenhum cliente encontrado"
        description="Cadastre o primeiro cliente para começar."
        class="py-10"
      >
        <UButton
          v-if="canManageClients"
          label="Cadastrar cliente"
          icon="i-lucide-plus"
          @click="openCreateForm"
        />
      </UEmpty>
      <div
        v-else
        class="py-10"
        aria-hidden="true"
      />
    </template>
    <template #footer>
      <template v-if="canManageClients && selectedClients.length">
        <span class="tabular-nums">{{ selectedClients.length }}</span> selecionado(s)
        <span class="text-dimmed"> · </span>
      </template>
      <span class="tabular-nums">{{ total }}</span> cliente(s)
      <template v-if="lastPage > 1">
        <span class="max-sm:hidden">
          · página {{ page }} de {{ Math.max(lastPage, 1) }}
        </span>
        <span class="sm:hidden tabular-nums">
          · {{ page }}/{{ Math.max(lastPage, 1) }}
        </span>
      </template>
    </template>
  </ShellDataTable>

  <ClientsClientFormModal
    v-if="canManageClients"
    v-model:open="formOpen"
    :client="formClient"
    :can-manage-credentials="canManageCredentials"
    :can-manage-clients="canManageClients"
    @saved="onFormSaved"
    @open-existing="(id) => navigateTo(clientSectionPath(id))"
  />

  <ClientsClientDetailModal
    v-model:open="detailOpen"
    :client-id="detailClientId"
    :initial-section="detailSection"
    @updated="load"
  />

  <ClientsClientCredentialModal
    v-model:open="credentialModalOpen"
    :client-id="credentialModalClient?.id ?? null"
    :client-label="credentialModalClient?.legal_name || credentialModalClient?.name"
    :can-manage-credentials="canManageCredentials"
    @saved="load"
  />

  <ClientsCategoryManagerModal
    v-if="canManageClientCategoryCatalog"
    v-model:open="categoryManagerOpen"
    :categories="clientCategories"
    :loading="categoriesLoading"
    @updated="onCategoryCatalogUpdated"
  />

  <ClientsAssignCategoriesModal
    v-if="canAssignClientCategories"
    v-model:open="categoryAssignmentOpen"
    :mode="categoryAssignmentMode"
    :categories="clientCategories"
    :client="categoryAssignmentClient"
    :client-ids="categoryAssignmentClientIds"
    @saved="onCategoryAssignmentSaved"
  />

  <ShellConfirmModal
    v-model:open="deleteOpen"
    title="Excluir cliente"
    :description="deleteClient
      ? `Inativar ${deleteClient.legal_name || deleteClient.name}? O cadastro permanece no escritório.`
      : undefined"
    tone="danger"
    confirm-label="Excluir"
    :loading="deleting"
    @confirm="confirmDelete"
  />

  <ShellConfirmModal
    v-model:open="bulkStatusOpen"
    :title="bulkStatusTarget ? 'Reativar clientes' : 'Inativar clientes'"
    :description="bulkStatusTarget
      ? `Reativar ${bulkStatusClientIds.length} cliente(s) selecionado(s)?`
      : `Inativar ${bulkStatusClientIds.length} cliente(s) selecionado(s)? Os cadastros permanecerão no escritório.`"
    :tone="bulkStatusTarget ? 'neutral' : 'danger'"
    :confirm-label="bulkStatusTarget ? 'Reativar' : 'Inativar'"
    :loading="bulkStatusSubmitting"
    @confirm="confirmBulkStatus"
  />
</template>

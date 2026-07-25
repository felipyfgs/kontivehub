/**
 * Carteira fiscal tenant-aware: overview + lista paginada do read model.
 * - Filtros, ordenação e paginação ficam em estado local (URL Nuxt path-only)
 * - Sem fallback sintético em erro/vazio
 * - Aborta/descarta requests quando o office ou módulo muda
 * - Ordenação é sempre server-side para não reordenar apenas o lote carregado
 */
import type { Ref } from 'vue'
import type {
  FiscalKpiKey,
  FiscalModuleClientRowFor,
  FiscalModuleOverview,
  FiscalModulePortfolioFilters,
  FiscalMonitoringSurfaceSummary,
  FiscalPortfolioModuleKey,
  MonitoringFilterValue
} from '~/types/fiscal-modules'
import {
  fiscalKpiSituationFilter,
  isSurfaceUnavailable,
  isSyntheticFiscalOrigin,
  surfaceAllowsDocument
} from '~/types/fiscal-modules'
import { laravelPageBatch, usePagedTable } from '~/composables/usePagedTable'
import { encodeClientIds } from '~/utils/data-table-filters'
import {
  hasActiveMonitoringFilters,
  normalizeMonitoringFilters,
  resetMonitoringFilters
} from '~/utils/monitoring-filters'

export interface UseFiscalModulePortfolioOptions {
  /** Submódulo controlado pela página (tabs locais — não entram na URL). */
  submodule?: Ref<string>
  /** Ano-calendário controlado pela cápsula PGMEI; nulo nas demais. */
  year?: Ref<number | null>
  /** delivery_status (declarações). */
  deliveryStatus?: Ref<string>
  perPage?: number
  /** Se false, não carrega overview (default true). */
  loadOverview?: boolean
  /** Auto-fetch no mount (default true). */
  immediate?: boolean
}

export interface FiscalModuleSortEntry {
  id: string
  desc: boolean
}

export type FiscalModuleSortingState = FiscalModuleSortEntry[]

const SORT_COLUMN_TO_API = Object.freeze<Record<string, NonNullable<FiscalModulePortfolioFilters['sort']>>>({
  client: 'legal_name',
  competence: 'competence',
  situation: 'situation',
  last_declaration: 'last_declaration',
  rbt12: 'rbt12',
  consulted: 'last_consulted_at',
  observed: 'last_consulted_at',
  synced: 'last_consulted_at',
  id: 'id'
})

export function fiscalModuleSortKey(columnId: string | null | undefined) {
  return columnId ? SORT_COLUMN_TO_API[columnId] : undefined
}

export function useFiscalModulePortfolio<M extends FiscalPortfolioModuleKey>(
  moduleKey: MaybeRefOrGetter<M>,
  options: UseFiscalModulePortfolioOptions = {}
) {
  const api = useApi()
  const { sessionEpoch } = useDashboard()
  const router = useRouter()
  const route = useRoute()

  const page = ref(1)
  /** pageSize alinhado à lista de clientes (20). */
  const perPage = ref(options.perPage ?? 20)
  const lastPage = ref(1)
  const q = ref('')
  const situation = ref('all')
  const competence = ref('')
  const submodule = options.submodule ?? ref('')
  const year = options.year ?? ref<number | null>(null)
  const deliveryStatus = options.deliveryStatus
    ?? ref('all')
  const sendStatus = ref('all')
  const clientIds = ref<number[]>([])
  const coverage = ref('all')
  const modality = ref('all')
  const sorting = ref<FiscalModuleSortingState>([{ id: 'client', desc: false }])

  const filters = computed<MonitoringFilterValue>(() => normalizeMonitoringFilters({
    q: q.value,
    situation: situation.value,
    competence: competence.value,
    clientIds: clientIds.value,
    deliveryStatus: deliveryStatus.value,
    sendStatus: sendStatus.value,
    coverage: coverage.value,
    modality: modality.value
  }))

  const overviewLoading = ref(false)
  const overviewError = ref<string | null>(null)
  const overview = shallowRef<FiscalModuleOverview<M> | null>(null)
  const lastValidAt = ref<string | null>(null)
  const hasLoadedOnce = ref(false)
  const manualRefreshing = ref(false)

  let overviewSeq = 0
  let clientsLoadSeq = 0
  let filterTransactionDepth = 0

  function currentSort() {
    const selected = sorting.value[0]
    return {
      sort: fiscalModuleSortKey(selected?.id) ?? 'legal_name',
      sort_direction: selected?.desc ? 'desc' as const : 'asc' as const
    }
  }

  function buildFilters(requestPage = page.value): FiscalModulePortfolioFilters {
    return {
      page: requestPage,
      per_page: perPage.value,
      q: q.value.trim() || undefined,
      situation: situation.value && situation.value !== 'all' ? situation.value : undefined,
      competence: competence.value.trim() || undefined,
      submodule:
        submodule.value && submodule.value !== 'all' && submodule.value.trim()
          ? submodule.value
          : undefined,
      year: Number.isInteger(year.value) && Number(year.value) >= 2000
        ? Number(year.value)
        : undefined,
      delivery_status:
        deliveryStatus.value && deliveryStatus.value !== 'all'
          ? deliveryStatus.value
          : undefined,
      send_status:
        sendStatus.value && sendStatus.value !== 'all'
          ? sendStatus.value
          : undefined,
      client_id: (() => {
        const encoded = encodeClientIds(clientIds.value)
        return encoded || undefined
      })(),
      coverage:
        coverage.value && coverage.value !== 'all'
          ? coverage.value
          : undefined,
      modality:
        modality.value && modality.value !== 'all'
          ? modality.value
          : undefined,
      ...currentSort()
    }
  }

  /**
   * Filtros do overview/contadores: independentes da cápsula (situation/KPI).
   * Só filtros avançados (busca, competência, submódulo, delivery, envio, cliente,
   * coverage, modality) redimensionam Total / Em dia / Pendências / etc.
   */
  function buildOverviewFilters(): Pick<
    FiscalModulePortfolioFilters,
    'q' | 'competence' | 'submodule' | 'year' | 'delivery_status' | 'send_status' | 'client_id' | 'coverage' | 'modality'
  > {
    const next = buildFilters(1)
    return {
      q: next.q,
      competence: next.competence,
      submodule: next.submodule,
      year: next.year,
      delivery_status: next.delivery_status,
      send_status: next.send_status,
      client_id: next.client_id,
      coverage: next.coverage,
      modality: next.modality
    }
  }

  const clientsFeed = usePagedTable<FiscalModuleClientRowFor<M>>({
    load: async ({ page: requestPage, signal }) => {
      const mod = toValue(moduleKey)
      const epoch = sessionEpoch.value
      const response = await api.fiscal.modules.clients(
        mod,
        buildFilters(requestPage),
        { signal }
      )

      if (signal.aborted || epoch !== sessionEpoch.value || mod !== toValue(moduleKey)) {
        throw new Error('Requisição da carteira cancelada após troca de contexto.')
      }

      const data = response.data ?? []
      if (data.some(row => row.module_key !== mod)) {
        throw new Error('Contrato incompatível: module_key da carteira não corresponde ao módulo.')
      }

      const meta = response.meta ?? response
      page.value = Number(meta.current_page ?? requestPage) || requestPage
      lastPage.value = Number(meta.last_page ?? page.value) || page.value
      if (typeof meta.per_page === 'number') perPage.value = meta.per_page
      lastValidAt.value = new Date().toISOString()
      hasLoadedOnce.value = true

      return laravelPageBatch(response)
    }
  })

  const rows = clientsFeed.rows
  const loading = clientsFeed.pendingInitial
  const refreshing = computed(() => manualRefreshing.value)
  const total = computed(() => clientsFeed.total.value ?? rows.value.length)
  const loadError = computed(() => clientsFeed.error.value
    ? apiErrorMessage(clientsFeed.error.value, 'Falha ao carregar carteira do módulo.')
    : null)

  const isSynthetic = computed(() =>
    isSyntheticFiscalOrigin(overview.value?.data_origin)
    || rows.value.some(row => isSyntheticFiscalOrigin(row.data_origin))
  )

  const dataOrigin = computed(() => overview.value?.data_origin ?? null)
  const dataOriginLabel = computed(() => overview.value?.data_origin_label ?? null)
  const sourceLabel = computed(() => overview.value?.source_label ?? null)
  const asOf = computed(() => overview.value?.as_of ?? null)
  const surface = computed<FiscalMonitoringSurfaceSummary | null>(() =>
    overview.value?.surface ?? null
  )
  const surfaceUnavailable = computed(() => isSurfaceUnavailable(surface.value))
  const allowsDocument = computed(() => surfaceAllowsDocument(surface.value))
  const counters = computed(() => overview.value?.counters ?? null)
  const totalClients = computed(() => overview.value?.total_clients ?? total.value)
  const hasRows = computed(() => rows.value.length > 0)
  const hasPreviousData = computed(() =>
    hasLoadedOnce.value && (hasRows.value || overview.value != null)
  )
  const isFiltered = computed(() => hasActiveMonitoringFilters({
    q: q.value,
    situation: situation.value,
    competence: competence.value,
    clientIds: clientIds.value,
    deliveryStatus: deliveryStatus.value,
    sendStatus: sendStatus.value,
    coverage: coverage.value,
    modality: modality.value
  }) || Boolean(submodule.value && submodule.value !== 'all' && submodule.value.trim()))

  /** URL Nuxt path-only — limpa query residual (bookmarks legados). */
  async function syncUrl() {
    if (Object.keys(route.query).length > 0) {
      await router.replace({ path: route.path })
    }
  }

  function overviewStillCurrent(seq: number, epoch: number) {
    return epoch === sessionEpoch.value && seq === overviewSeq
  }

  async function loadOverview() {
    if (options.loadOverview === false) return
    const seq = ++overviewSeq
    const epoch = sessionEpoch.value
    overviewLoading.value = true
    overviewError.value = null
    try {
      const mod = toValue(moduleKey)
      // Sem situation: badges das cápsulas não mudam ao clicar em Total/Em dia/…
      const response = await api.fiscal.modules.overview(mod, buildOverviewFilters())
      if (!overviewStillCurrent(seq, epoch)) return

      const data = response.data
      if (data?.module_key && data.module_key !== mod) {
        throw new Error('Contrato incompatível: module_key do overview não corresponde ao módulo.')
      }
      overview.value = data
    } catch (caught) {
      if (!overviewStillCurrent(seq, epoch)) return
      overviewError.value = apiErrorMessage(caught, 'Falha ao carregar overview do módulo.')
      // Mantém overview anterior se já houver.
    } finally {
      if (overviewStillCurrent(seq, epoch)) overviewLoading.value = false
    }
  }

  async function loadClients(opts?: { silent?: boolean }) {
    const seq = ++clientsLoadSeq
    await syncUrl()
    if (seq !== clientsLoadSeq) return

    const preservePrevious = opts?.silent === true && hasLoadedOnce.value
    const previous = preservePrevious
      ? {
          rows: [...rows.value],
          total: clientsFeed.total.value,
          page: page.value,
          lastPage: lastPage.value
        }
      : null

    await clientsFeed.resetAndLoad()

    // Refresh manual: se falhar e havia carteira válida, restaura rows locais.
    if (previous && clientsFeed.error.value) {
      clientsFeed.rows.value = previous.rows
      clientsFeed.total.value = previous.total ?? previous.rows.length
      clientsFeed.page.value = previous.page
      page.value = previous.page
      lastPage.value = previous.lastPage
    }
  }

  async function retryClients() {
    if (hasLoadedOnce.value && rows.value.length) {
      await loadClients({ silent: true })
      return
    }

    await clientsFeed.retry()
  }

  /** Paginação template: troca de página recarrega o lote (não só o ref local). */
  async function setPage(next: number) {
    const target = Math.max(1, Math.floor(Number(next) || 1))
    page.value = target
    await syncUrl()
    await clientsFeed.setPage(target)
    page.value = clientsFeed.page.value
    lastPage.value = clientsFeed.lastPage.value
  }

  /** Troca «N por página» — volta à página 1 e recarrega (padrão lista de clientes). */
  async function setPerPage(next: number) {
    const allowed = [10, 20, 50]
    const target = allowed.includes(Number(next)) ? Number(next) : (options.perPage ?? 20)
    if (perPage.value === target) return
    perPage.value = target
    page.value = 1
    await syncUrl()
    await loadClients()
  }

  async function load(opts?: { silent?: boolean }) {
    await Promise.all([loadOverview(), loadClients(opts)])
  }

  async function refresh() {
    manualRefreshing.value = true
    try {
      await load({ silent: true })
    } finally {
      manualRefreshing.value = false
    }
  }

  function resetPage() {
    page.value = 1
    lastPage.value = 1
  }

  function setSituationFromKpi(value: string | null) {
    situation.value = value || 'all'
    resetPage()
  }

  function selectKpi(key: FiscalKpiKey) {
    setSituationFromKpi(fiscalKpiSituationFilter(key))
  }

  /**
   * Aplica o formulário inteiro como uma transação reativa. Os watchers ignoram o
   * lote intermediário e a carteira faz uma única carga com o estado final.
   */
  async function applyFilters(nextValue: MonitoringFilterValue) {
    const next = normalizeMonitoringFilters(nextValue)
    const nextSituation = next.situation || 'all'
    const nextDeliveryStatus = next.deliveryStatus || 'all'
    const nextSendStatus = next.sendStatus || 'all'
    const nextCoverage = next.coverage || 'all'
    const nextModality = next.modality || 'all'

    const clientSig = (ids: number[]) => encodeClientIds(ids)
    const advancedChanged = q.value !== next.q
      || competence.value !== next.competence
      || deliveryStatus.value !== nextDeliveryStatus
      || sendStatus.value !== nextSendStatus
      || clientSig(clientIds.value) !== clientSig(next.clientIds)
      || coverage.value !== nextCoverage
      || modality.value !== nextModality
    const situationChanged = situation.value !== nextSituation

    if (!advancedChanged && !situationChanged) return

    filterTransactionDepth += 1
    try {
      q.value = next.q
      situation.value = nextSituation
      competence.value = next.competence
      deliveryStatus.value = nextDeliveryStatus
      sendStatus.value = nextSendStatus
      clientIds.value = [...next.clientIds]
      coverage.value = nextCoverage
      modality.value = nextModality
      resetPage()
      await nextTick()
    } finally {
      filterTransactionDepth -= 1
    }

    if (!ready) return
    if (advancedChanged) {
      await load()
      return
    }
    if (situationChanged) await loadClients()
  }

  async function applyQuickFilters(nextValue: MonitoringFilterValue) {
    const next = normalizeMonitoringFilters(nextValue)
    const qChanged = q.value !== next.q
    const situationChanged = situation.value !== next.situation
    if (!qChanged && !situationChanged) return

    filterTransactionDepth += 1
    try {
      q.value = next.q
      situation.value = next.situation
      resetPage()
      await nextTick()
    } finally {
      filterTransactionDepth -= 1
    }

    if (!ready) return
    if (qChanged) await load()
    else await loadClients()
  }

  async function resetFilters() {
    await applyFilters(resetMonitoringFilters())
  }

  let ready = false

  // Filtros avançados: recarregam contadores (overview) + lista.
  watch(
    [q, competence, submodule, year, deliveryStatus, sendStatus, clientIds, coverage, modality],
    () => {
      if (!ready || filterTransactionDepth > 0) return
      resetPage()
      void load()
    },
    { deep: true }
  )

  // Cápsula de situação + ordenação: só a lista — badges das cápsulas ficam estáveis.
  watch(
    [situation, sorting],
    () => {
      if (!ready || filterTransactionDepth > 0) return
      resetPage()
      void loadClients()
    },
    { deep: true }
  )

  /**
   * Troca de Office/módulo: invalida época, descarta overview/origem/contadores/linhas
   * e impede que resposta atrasada seja aplicada (seq + sessionEpoch).
   */
  function clearForContextChange() {
    overviewSeq += 1
    clientsLoadSeq += 1
    clientsFeed.reset()
    overview.value = null
    page.value = 1
    lastPage.value = 1
    lastValidAt.value = null
    hasLoadedOnce.value = false
    manualRefreshing.value = false
    overviewError.value = null
    overviewLoading.value = false
  }

  /** Limpa filtros aplicados (incl. cliente) antes da carga do novo Office. */
  function clearFiltersForTenantSwitch() {
    filterTransactionDepth += 1
    try {
      q.value = ''
      situation.value = 'all'
      competence.value = ''
      clientIds.value = []
      deliveryStatus.value = 'all'
      sendStatus.value = 'all'
      coverage.value = 'all'
      modality.value = 'all'
      page.value = 1
      lastPage.value = 1
    } finally {
      filterTransactionDepth -= 1
    }
  }

  watch(sessionEpoch, () => {
    // Troca de office aborta o request e limpa antes de recarregar: tenants não se misturam.
    clearFiltersForTenantSwitch()
    clearForContextChange()
    if (ready) void load()
  })

  watch(
    () => toValue(moduleKey),
    (next, prev) => {
      if (next === prev) return
      clearForContextChange()
      if (ready) void load()
    }
  )

  if (options.immediate !== false) {
    onMounted(async () => {
      // Ativa os watchers antes do request para uma troca de office durante
      // o carregamento inicial abortar e já iniciar a carteira do novo tenant.
      ready = true
      await load()
    })
  } else {
    ready = true
  }

  return {
    page,
    perPage,
    total,
    lastPage,
    q,
    situation,
    competence,
    submodule,
    year,
    deliveryStatus,
    sendStatus,
    clientIds,
    coverage,
    modality,
    filters,
    sorting,
    loading,
    refreshing,
    overviewLoading,
    loadError,
    overviewError,
    overview,
    rows,
    counters,
    totalClients,
    dataOrigin,
    dataOriginLabel,
    sourceLabel,
    asOf,
    surface,
    surfaceUnavailable,
    allowsDocument,
    isSynthetic,
    lastValidAt,
    hasLoadedOnce,
    hasRows,
    hasPreviousData,
    isFiltered,
    setPage,
    setPerPage,
    retry: retryClients,
    load,
    loadClients,
    loadOverview,
    refresh,
    resetPage,
    setSituationFromKpi,
    selectKpi,
    applyQuickFilters,
    applyFilters,
    resetFilters,
    syncUrl,
    buildFilters,
    buildOverviewFilters
  }
}

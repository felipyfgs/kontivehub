import {
  computed,
  onMounted,
  ref,
  watch,
  type ComputedRef,
  type Ref
} from 'vue'
import type { Flow, FlowStatus } from '~/types/communication/flows'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { apiErrorCode, apiErrorMessage } from '~/utils/api-error'
import {
  communicationFlowEmptyKind,
  communicationFlowsMutationBlocked,
  filterCommunicationFlows,
  paginateCommunicationFlows
} from '~/utils/communication-flows'
import { communicationFlowPath } from '~/utils/communication-routes'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import { canManageCommunicationFlows } from '~/utils/permissions'
import { COMMUNICATION_SURFACES, consumeSurfaceNavigationIntent, useSurfaceNavigationState } from './useSurfaceNavigationState'

interface FlowsCatalogApi {
  list: () => Promise<{
    data: Flow[]
    meta: { flows_enabled: boolean }
  }>
  create: (body: { name: string }) => Promise<{ data: Flow }>
  update: (
    id: number,
    body: { status: FlowStatus, lock_version: number }
  ) => Promise<{ data: Flow }>
}

interface FlowsCatalogDependencies {
  api: FlowsCatalogApi
  canManage: ComputedRef<boolean> | Ref<boolean>
  initialQuery: Record<string, unknown>
  navigate: (path: string) => void | Promise<void>
  sessionEpoch: Ref<number>
  toast: (title: string, color: 'success' | 'error' | 'warning') => void
}

function allowedPerPage(value: unknown): number {
  const parsed = Number(value)
  return [10, 20, 50].includes(parsed) ? parsed : 20
}

function initialStatus(value: unknown): 'all' | FlowStatus {
  return value === 'paused' || value === 'active' ? value : 'all'
}

export function createCommunicationFlowsCatalog(dependencies: FlowsCatalogDependencies) {
  const {
    api,
    canManage,
    initialQuery,
    navigate,
    sessionEpoch,
    toast
  } = dependencies

  const allItems = ref<Flow[]>([])
  const flowsEnabled = ref(false)
  const flagsConfirmed = ref(false)
  const loading = ref(false)
  const loadError = ref<string | null>(null)
  const hasLoaded = ref(false)
  const page = ref(Math.max(1, Number(initialQuery.page) || 1))
  const perPage = ref(allowedPerPage(initialQuery.per_page))
  const q = ref(String(initialQuery.q || ''))
  const statusFilter = ref<'all' | FlowStatus>(
    initialStatus(initialQuery.status)
  )
  let loadGeneration = 0

  const createOpen = ref(false)
  const createBusy = ref(false)
  const createError = ref<string | null>(null)
  const createName = ref('')

  const pauseOpen = ref(false)
  const pauseBusy = ref(false)
  const pauseTarget = ref<Flow | null>(null)

  const mutationBlocked = computed(() =>
    communicationFlowsMutationBlocked(
      flagsConfirmed.value && flowsEnabled.value,
      canManage.value
    )
  )

  const filteredItems = computed(() => filterCommunicationFlows(allItems.value, {
    q: q.value,
    status: statusFilter.value
  }))
  const pageSlice = computed(() => paginateCommunicationFlows(
    filteredItems.value,
    page.value,
    perPage.value
  ))
  const items = computed(() => pageSlice.value.rows)
  const total = computed(() => pageSlice.value.total)
  const emptyKind = computed(() => communicationFlowEmptyKind({
    q: q.value,
    status: statusFilter.value
  }))
  const initialLoading = computed(() =>
    loading.value && !hasLoaded.value && allItems.value.length === 0
  )
  const stale = computed(() =>
    hasLoaded.value
    && allItems.value.length > 0
    && (loading.value || Boolean(loadError.value))
  )

  const filterDefinitions = computed<DataTableFilterDefinition[]>(() => [{
    key: 'status',
    kind: 'option',
    label: 'Situação',
    emptyValue: 'all',
    items: [
      { label: 'Pausados', value: 'paused' },
      { label: 'Ativos', value: 'active' }
    ]
  }])

  const chipModels = computed<DataTableFilterModel[]>(() => {
    const definition = findDefinition(filterDefinitions.value, 'status')
    if (!definition || statusFilter.value === 'all') return []
    const model = createFilterModel(definition, statusFilter.value)
    return model ? [model] : []
  })

  async function load() {
    const epoch = sessionEpoch.value
    const generation = ++loadGeneration
    loading.value = true
    loadError.value = null
    flagsConfirmed.value = false
    flowsEnabled.value = false

    try {
      const response = await api.list()
      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      allItems.value = response.data
      flowsEnabled.value = response.meta?.flows_enabled === true
      flagsConfirmed.value = true
      hasLoaded.value = true
      const lastPage = Math.max(1, Math.ceil(filteredItems.value.length / perPage.value))
      if (page.value > lastPage) page.value = lastPage
    } catch (caught) {
      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      loadError.value = apiErrorMessage(caught, 'Falha ao listar fluxos.')
      toast(loadError.value, 'error')
    } finally {
      if (epoch === sessionEpoch.value && generation === loadGeneration) {
        loading.value = false
      }
    }
  }

  function onSearch(value: string) {
    q.value = value
    page.value = 1
  }

  function onStructuredFilters(models: DataTableFilterModel[]) {
    const statusModel = models.find(model => model.key === 'status')
    statusFilter.value = statusModel
      ? initialStatus(statusModel.value)
      : 'all'
    page.value = 1
  }

  function clearFilters() {
    statusFilter.value = 'all'
    q.value = ''
    page.value = 1
  }

  function setPerPage(next: number) {
    const target = allowedPerPage(next)
    if (perPage.value === target) return
    perPage.value = target
    page.value = 1
  }

  function openCreate() {
    const blocked = mutationBlocked.value
    if (blocked) {
      toast(blocked, 'warning')
      return
    }
    createName.value = ''
    createError.value = null
    createOpen.value = true
  }

  function openFlow(item: Flow) {
    void navigate(communicationFlowPath(item.id))
  }

  function openPause(item: Flow) {
    const blocked = mutationBlocked.value
    if (blocked) {
      toast(blocked, 'warning')
      return
    }
    if (item.status === 'paused') return
    pauseTarget.value = item
    pauseOpen.value = true
  }

  async function submitCreate() {
    const blocked = mutationBlocked.value
    if (blocked) {
      createError.value = blocked
      return
    }
    const name = createName.value.trim()
    if (name.length < 2) {
      createError.value = 'Informe um nome com pelo menos 2 caracteres.'
      return
    }
    createBusy.value = true
    createError.value = null
    try {
      const response = await api.create({ name })
      toast('Fluxo criado (pausado).', 'success')
      createOpen.value = false
      createName.value = ''
      await navigate(communicationFlowPath(response.data.id))
    } catch (caught) {
      if (apiErrorCode(caught) === 'communication_flows_disabled') {
        flowsEnabled.value = false
        flagsConfirmed.value = true
      }
      createError.value = apiErrorMessage(caught, 'Falha ao criar fluxo.')
      toast(createError.value, 'error')
    } finally {
      createBusy.value = false
    }
  }

  async function confirmPause() {
    const blocked = mutationBlocked.value
    const target = pauseTarget.value
    if (blocked || !target) {
      if (blocked) toast(blocked, 'warning')
      return
    }
    pauseBusy.value = true
    try {
      await api.update(target.id, {
        status: 'paused',
        lock_version: target.lock_version
      })
      toast('Fluxo pausado.', 'success')
      pauseOpen.value = false
      pauseTarget.value = null
      await load()
    } catch (caught) {
      if (apiErrorCode(caught) === 'communication_flows_disabled') {
        flowsEnabled.value = false
        flagsConfirmed.value = true
      }
      const conflict = apiErrorCode(caught) === 'version_conflict'
      toast(
        conflict
          ? 'Este fluxo foi alterado por outra pessoa. Atualize a lista e tente novamente.'
          : apiErrorMessage(caught, 'Falha ao pausar fluxo.'),
        conflict ? 'warning' : 'error'
      )
    } finally {
      pauseBusy.value = false
    }
  }

  watch(sessionEpoch, () => {
    loadGeneration += 1
    allItems.value = []
    flowsEnabled.value = false
    flagsConfirmed.value = false
    hasLoaded.value = false
    loadError.value = null
    page.value = 1
    perPage.value = 20
    q.value = ''
    statusFilter.value = 'all'
    void load()
  })

  return {
    canManage,
    allItems,
    items,
    total,
    flowsEnabled,
    flagsConfirmed,
    loading,
    loadError,
    hasLoaded,
    initialLoading,
    stale,
    page,
    perPage,
    q,
    statusFilter,
    mutationBlocked,
    emptyKind,
    filterDefinitions,
    chipModels,
    createOpen,
    createBusy,
    createError,
    createName,
    pauseOpen,
    pauseBusy,
    pauseTarget,
    load,
    onSearch,
    onStructuredFilters,
    clearFilters,
    setPerPage,
    openCreate,
    openFlow,
    openPause,
    submitCreate,
    confirmPause
  }
}

export type CommunicationFlowsCatalog = ReturnType<
  typeof createCommunicationFlowsCatalog
>

export function useCommunicationFlowsCatalog() {
  const api = useApi()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const canManage = computed(() => canManageCommunicationFlows(me.value))
  const surface = useSurfaceNavigationState(COMMUNICATION_SURFACES.flows, {
    page: 1, per_page: 20, q: '', status: 'all'
  }, { resetKey: () => `${me.value?.id ?? 'guest'}:${me.value?.current_tenant?.id ?? 'none'}:${sessionEpoch.value}` })
  const legacyIntent = consumeSurfaceNavigationIntent<Record<string, unknown>>(COMMUNICATION_SURFACES.flows)
  if (legacyIntent) surface.patch(legacyIntent)

  const catalog = createCommunicationFlowsCatalog({
    api: api.communication.flows,
    canManage,
    initialQuery: surface.state.value,
    navigate: async (path) => { await navigateTo(path) },
    sessionEpoch,
    toast: (title, color) => toast.add({ title, color })
  })

  watch([catalog.page, catalog.perPage, catalog.q, catalog.statusFilter], () => {
    surface.patch({ page: catalog.page.value, per_page: catalog.perPage.value, q: catalog.q.value, status: catalog.statusFilter.value })
  })

  onMounted(() => void catalog.load())
  return catalog
}

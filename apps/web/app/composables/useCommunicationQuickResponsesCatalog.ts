import {
  computed,
  onMounted,
  ref,
  watch,
  type ComputedRef,
  type Ref
} from 'vue'
import type { CannedResponse, CannedResponseListParams, CannedResponseWriteBody } from '~/types/communication/quick-responses'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { apiErrorCode, apiErrorMessage } from '~/utils/api-error'
import {
  buildCannedResponseListQuery,
  cannedResponseEmptyKind,
  isValidCannedShortcut,
  normalizeCannedShortcut
} from '~/utils/communication-quick-responses'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import { COMMUNICATION_SURFACES, consumeSurfaceNavigationIntent, useSurfaceNavigationState } from './useSurfaceNavigationState'

type ActiveFilter = 'all' | 'true' | 'false'
type EditorMode = 'create' | 'edit'

interface QuickResponsesCatalogApi {
  cannedResponses: (params?: { q?: string }) => Promise<{ data: CannedResponse[] }>
  listCannedResponses: (params?: CannedResponseListParams) => Promise<{
    data: CannedResponse[]
    meta: { current_page: number, last_page: number, total: number }
  }>
  createCannedResponse: (body: CannedResponseWriteBody) => Promise<unknown>
  updateCannedResponse: (
    id: number,
    body: CannedResponseWriteBody & { lock_version: number }
  ) => Promise<unknown>
  duplicateCannedResponse: (id: number, body: { shortcut: string }) => Promise<unknown>
  deactivateCannedResponse: (id: number) => Promise<unknown>
}

interface QuickResponsesCatalogDependencies {
  api: QuickResponsesCatalogApi
  canManage: ComputedRef<boolean> | Ref<boolean>
  initialQuery: Record<string, unknown>
  sessionEpoch: Ref<number>
  toast: (title: string, color: 'success' | 'error' | 'warning') => void
}

function allowedPerPage(value: unknown): number {
  const parsed = Number(value)
  return [10, 20, 50].includes(parsed) ? parsed : 20
}

function initialActiveFilter(value: unknown): ActiveFilter {
  return value === 'true' || value === 'false' ? value : 'all'
}

export function createCommunicationQuickResponsesCatalog(
  dependencies: QuickResponsesCatalogDependencies
) {
  const { api, canManage, initialQuery, sessionEpoch, toast } = dependencies
  const items = ref<CannedResponse[]>([])
  const loading = ref(false)
  const loadError = ref<string | null>(null)
  const hasLoaded = ref(false)
  const page = ref(Math.max(1, Number(initialQuery.page) || 1))
  const perPage = ref(allowedPerPage(initialQuery.per_page))
  const total = ref(0)
  const q = ref(String(initialQuery.q || ''))
  const isActive = ref<ActiveFilter>(initialActiveFilter(initialQuery.is_active))
  let loadGeneration = 0

  const editorOpen = ref(false)
  const editorBusy = ref(false)
  const editorError = ref<string | null>(null)
  const editorMode = ref<EditorMode>('create')
  const editorId = ref<number | null>(null)
  const editorLockVersion = ref(1)
  const editorTitle = ref('')
  const editorShortcut = ref('')
  const editorBody = ref('')
  const editorIsActive = ref(true)

  const duplicateOpen = ref(false)
  const duplicateBusy = ref(false)
  const duplicateError = ref<string | null>(null)
  const duplicateSource = ref<CannedResponse | null>(null)
  const duplicateShortcut = ref('')

  const deactivateOpen = ref(false)
  const deactivateBusy = ref(false)
  const deactivateTarget = ref<CannedResponse | null>(null)

  const filterDefinitions = computed<DataTableFilterDefinition[]>(() => [{
    key: 'is_active',
    kind: 'option',
    label: 'Situação',
    emptyValue: 'all',
    items: canManage.value
      ? [
          { label: 'Ativas', value: 'true' },
          { label: 'Inativas', value: 'false' }
        ]
      : [{ label: 'Ativas', value: 'true' }]
  }])

  const chipModels = computed<DataTableFilterModel[]>(() => {
    const definition = findDefinition(filterDefinitions.value, 'is_active')
    if (!definition || isActive.value === 'all') return []
    const model = createFilterModel(definition, isActive.value)
    return model ? [model] : []
  })
  const emptyKind = computed(() => cannedResponseEmptyKind({
    q: q.value,
    isActive: isActive.value
  }))
  const initialLoading = computed(() => loading.value && !hasLoaded.value && items.value.length === 0)
  const stale = computed(() => hasLoaded.value && items.value.length > 0 && (loading.value || Boolean(loadError.value)))

  async function load() {
    const epoch = sessionEpoch.value
    const generation = ++loadGeneration
    loading.value = true
    loadError.value = null

    try {
      const response = canManage.value
        ? await api.listCannedResponses(buildCannedResponseListQuery({
            q: q.value,
            isActive: isActive.value,
            page: page.value,
            perPage: perPage.value
          }))
        : await api.cannedResponses(q.value.trim() ? { q: q.value.trim() } : undefined)

      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      items.value = response.data
      total.value = 'meta' in response && response.meta && typeof response.meta === 'object' && 'total' in response.meta
        ? Number((response.meta as { total: unknown }).total) || 0
        : response.data.length
      hasLoaded.value = true
    } catch (caught) {
      if (epoch !== sessionEpoch.value || generation !== loadGeneration) return
      loadError.value = apiErrorMessage(caught, 'Falha ao listar respostas rápidas.')
      toast(loadError.value, 'error')
    } finally {
      if (epoch === sessionEpoch.value && generation === loadGeneration) loading.value = false
    }
  }

  function onSearch(value: string) {
    q.value = value
    page.value = 1
  }

  function onStructuredFilters(models: DataTableFilterModel[]) {
    const activeModel = models.find(model => model.key === 'is_active')
    const next = activeModel ? String(activeModel.value) as Exclude<ActiveFilter, 'all'> : 'all'
    isActive.value = !canManage.value && next === 'false' ? 'all' : next
    page.value = 1
  }

  function clearFilters() {
    isActive.value = 'all'
    q.value = ''
    page.value = 1
  }

  function setPerPage(next: number) {
    const target = allowedPerPage(next)
    if (perPage.value === target) return
    perPage.value = target
    if (page.value !== 1) page.value = 1
  }

  function resetEditor() {
    editorError.value = null
    editorId.value = null
    editorLockVersion.value = 1
    editorTitle.value = ''
    editorShortcut.value = ''
    editorBody.value = ''
    editorIsActive.value = true
  }

  function openCreate() {
    if (!canManage.value) return
    editorMode.value = 'create'
    resetEditor()
    editorOpen.value = true
  }

  function openEdit(item: CannedResponse) {
    if (!canManage.value) return
    editorMode.value = 'edit'
    editorError.value = null
    editorId.value = item.id
    editorLockVersion.value = item.lock_version
    editorTitle.value = item.title
    editorShortcut.value = item.shortcut
    editorBody.value = item.body
    editorIsActive.value = item.is_active
    editorOpen.value = true
  }

  function openDuplicate(item: CannedResponse) {
    if (!canManage.value) return
    duplicateSource.value = item
    duplicateShortcut.value = `${item.shortcut}-copia`
    duplicateError.value = null
    duplicateOpen.value = true
  }

  function openDeactivate(item: CannedResponse) {
    if (!canManage.value || !item.is_active) return
    deactivateTarget.value = item
    deactivateOpen.value = true
  }

  function insertVariable(token: string) {
    editorBody.value = `${editorBody.value}${token}`
  }

  async function submitEditor() {
    if (!canManage.value) return
    const title = editorTitle.value.trim()
    const shortcut = normalizeCannedShortcut(editorShortcut.value)
    const body = editorBody.value.trim()
    if (!title || !body) {
      editorError.value = 'Informe título e corpo.'
      return
    }
    if (!isValidCannedShortcut(shortcut)) {
      editorError.value = 'Atalho inválido. Use apenas a-z, 0-9, ponto, hífen e underscore.'
      return
    }

    editorBusy.value = true
    editorError.value = null
    try {
      if (editorMode.value === 'create') {
        await api.createCannedResponse({ title, shortcut, body, is_active: editorIsActive.value })
        toast('Resposta rápida criada.', 'success')
      } else if (editorId.value != null) {
        await api.updateCannedResponse(editorId.value, {
          title,
          shortcut,
          body,
          is_active: editorIsActive.value,
          lock_version: editorLockVersion.value
        })
        toast('Resposta rápida atualizada.', 'success')
      }
      editorOpen.value = false
      resetEditor()
      await load()
    } catch (caught) {
      editorError.value = apiErrorCode(caught) === 'version_conflict'
        ? 'Esta resposta foi alterada por outra pessoa. Atualize a lista e tente novamente.'
        : apiErrorMessage(caught, 'Falha ao salvar resposta rápida.')
      toast(editorError.value, apiErrorCode(caught) === 'version_conflict' ? 'warning' : 'error')
    } finally {
      editorBusy.value = false
    }
  }

  async function submitDuplicate() {
    if (!canManage.value || !duplicateSource.value) return
    const shortcut = normalizeCannedShortcut(duplicateShortcut.value)
    if (!isValidCannedShortcut(shortcut)) {
      duplicateError.value = 'Atalho inválido. Use apenas a-z, 0-9, ponto, hífen e underscore.'
      return
    }
    duplicateBusy.value = true
    duplicateError.value = null
    try {
      await api.duplicateCannedResponse(duplicateSource.value.id, { shortcut })
      toast('Resposta duplicada.', 'success')
      duplicateOpen.value = false
      duplicateSource.value = null
      await load()
    } catch (caught) {
      duplicateError.value = apiErrorMessage(caught, 'Falha ao duplicar resposta.')
      toast(duplicateError.value, 'error')
    } finally {
      duplicateBusy.value = false
    }
  }

  async function confirmDeactivate() {
    if (!canManage.value || !deactivateTarget.value) return
    deactivateBusy.value = true
    try {
      await api.deactivateCannedResponse(deactivateTarget.value.id)
      toast('Resposta desativada.', 'success')
      deactivateOpen.value = false
      deactivateTarget.value = null
      await load()
    } catch (caught) {
      toast(apiErrorMessage(caught, 'Falha ao desativar resposta.'), 'error')
    } finally {
      deactivateBusy.value = false
    }
  }

  watch([page, q, isActive, perPage], () => {
    void load()
  })

  watch(sessionEpoch, () => {
    loadGeneration += 1
    const queryWillChange = page.value !== 1 || q.value !== '' || isActive.value !== 'all'
    items.value = []
    hasLoaded.value = false
    loadError.value = null
    page.value = 1
    perPage.value = 20
    q.value = ''
    isActive.value = 'all'
    total.value = 0
    if (!queryWillChange) void load()
  })

  return {
    canManage,
    items,
    loading,
    loadError,
    hasLoaded,
    initialLoading,
    stale,
    page,
    perPage,
    total,
    q,
    isActive,
    filterDefinitions,
    chipModels,
    emptyKind,
    editorOpen,
    editorBusy,
    editorError,
    editorMode,
    editorTitle,
    editorShortcut,
    editorBody,
    editorIsActive,
    duplicateOpen,
    duplicateBusy,
    duplicateError,
    duplicateSource,
    duplicateShortcut,
    deactivateOpen,
    deactivateBusy,
    deactivateTarget,
    load,
    onSearch,
    onStructuredFilters,
    clearFilters,
    setPerPage,
    resetEditor,
    openCreate,
    openEdit,
    openDuplicate,
    openDeactivate,
    insertVariable,
    submitEditor,
    submitDuplicate,
    confirmDeactivate
  }
}

export type QuickResponsesCatalog = ReturnType<
  typeof createCommunicationQuickResponsesCatalog
>

export function useCommunicationQuickResponsesCatalog() {
  const api = useApi()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const canManage = computed(() => canManageCommunicationQuickReplies(me.value))
  const surface = useSurfaceNavigationState(COMMUNICATION_SURFACES.quickResponses, {
    page: 1, per_page: 20, q: '', is_active: 'all'
  }, { resetKey: () => `${me.value?.id ?? 'guest'}:${me.value?.current_tenant?.id ?? 'none'}:${sessionEpoch.value}` })
  const intent = consumeSurfaceNavigationIntent<Record<string, unknown>>(COMMUNICATION_SURFACES.quickResponses)
  if (intent) surface.patch(intent)
  const catalog = createCommunicationQuickResponsesCatalog({
    api: api.communication.catalog,
    canManage,
    initialQuery: surface.state.value,
    sessionEpoch,
    toast: (title, color) => toast.add({ title, color })
  })

  watch([catalog.page, catalog.perPage, catalog.q, catalog.isActive], () => {
    surface.patch({ page: catalog.page.value, per_page: catalog.perPage.value, q: catalog.q.value, is_active: catalog.isActive.value })
  })

  onMounted(() => void catalog.load())
  return catalog
}

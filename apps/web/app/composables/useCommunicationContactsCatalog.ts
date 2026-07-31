import {
  computed,
  onMounted,
  onScopeDispose,
  ref,
  watch,
  type Ref
} from 'vue'
import type {
  CommunicationContact,
  CommunicationContactListParams,
  CommunicationContactSortField
} from '~/types/communication'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { apiErrorMessage } from '~/utils/api-error'
import {
  buildCommunicationContactListQuery,
  communicationContactEmptyKind,
  isCommunicationContactSortField
} from '~/utils/communication-contacts'
import { communicationContactPath } from '~/utils/communication-routes'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import { canManageCommunicationContacts } from '~/utils/permissions'
import { COMMUNICATION_SURFACES, consumeSurfaceNavigationIntent, useSurfaceNavigationState } from './useSurfaceNavigationState'

type ContactListResponse = {
  data: CommunicationContact[]
  meta: {
    current_page: number
    last_page: number
    total: number
  }
}

type ContactCreateBody = {
  name?: string | null
  phone: string
  client_id?: number
  client_contact_id?: number
  is_primary?: boolean
  receives_automatic?: boolean
}

type ContactUpdateBody = {
  name?: string | null
  is_active?: boolean
}

const DEFAULT_PER_PAGE = 20

export type CommunicationContactsCatalogDependencies = {
  list: (query: CommunicationContactListParams) => Promise<ContactListResponse>
  create: (body: ContactCreateBody) => Promise<{ data: CommunicationContact }>
  update: (id: number, body: ContactUpdateBody) => Promise<{ data: CommunicationContact }>
  pushRoute: (location: { path: string }) => Promise<unknown> | unknown
  notify: (title: string, color: 'success' | 'error') => void
  sessionEpoch: Ref<number>
  canManage: Ref<boolean>
  initialQuery?: Record<string, unknown>
}

const filterDefinitions: DataTableFilterDefinition[] = [
  {
    key: 'is_active',
    kind: 'option',
    label: 'Situação',
    emptyValue: 'true',
    items: [
      { label: 'Todos', value: 'all' },
      { label: 'Inativos', value: 'false' }
    ]
  },
  {
    key: 'is_provisional',
    kind: 'option',
    label: 'Provisório',
    emptyValue: 'all',
    items: [
      { label: 'Somente provisórios', value: 'true' },
      { label: 'Somente definitivos', value: 'false' }
    ]
  },
  {
    key: 'linked',
    kind: 'option',
    label: 'Vínculo',
    emptyValue: 'all',
    items: [
      { label: 'Com cliente', value: 'true' },
      { label: 'Sem cliente', value: 'false' }
    ]
  }
]

function triState(
  value: unknown,
  fallback: 'all' | 'true' | 'false'
): 'all' | 'true' | 'false' {
  return value === 'all' || value === 'true' || value === 'false' ? value : fallback
}

function contactPageSize(value: unknown): number {
  const parsed = Number(value)
  return [10, DEFAULT_PER_PAGE, 50].includes(parsed) ? parsed : DEFAULT_PER_PAGE
}

export function createCommunicationContactsCatalog(
  dependencies: CommunicationContactsCatalogDependencies
) {
  const initialQuery = dependencies.initialQuery ?? {}
  const items = ref<CommunicationContact[]>([])
  const loading = ref(false)
  const hasLoaded = ref(false)
  const loadError = ref<string | null>(null)
  const page = ref(Math.max(1, Number(initialQuery.page) || 1))
  const perPage = ref(contactPageSize(initialQuery.per_page))
  const total = ref(0)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const q = ref(String(initialQuery.q || ''))
  const isActive = ref(triState(initialQuery.is_active, 'true'))
  const isProvisional = ref(triState(initialQuery.is_provisional, 'all'))
  const linked = ref(triState(initialQuery.linked, 'all'))
  const sort = ref<CommunicationContactSortField | null>(
    isCommunicationContactSortField(initialQuery.sort) ? initialQuery.sort : 'name'
  )
  const sortDirection = ref<'asc' | 'desc' | null>(
    initialQuery.sort_direction === 'desc' ? 'desc' : 'asc'
  )
  const createOpen = ref(false)
  const creating = ref(false)
  const createError = ref<string | null>(null)
  const updatingId = ref<number | null>(null)
  const updateError = ref<string | null>(null)

  let loadSequence = 0

  const chipModels = computed<DataTableFilterModel[]>(() => {
    const models: DataTableFilterModel[] = []
    const values: Array<[string, string, string]> = [
      ['is_active', isActive.value, 'true'],
      ['is_provisional', isProvisional.value, 'all'],
      ['linked', linked.value, 'all']
    ]
    for (const [key, value, defaultValue] of values) {
      if (value === defaultValue) continue
      const definition = findDefinition(filterDefinitions, key)
      const model = definition ? createFilterModel(definition, value) : null
      if (model) models.push(model)
    }
    return models
  })

  const emptyKind = computed(() => communicationContactEmptyKind({
    q: q.value,
    isActive: isActive.value,
    isProvisional: isProvisional.value,
    linked: linked.value
  }))
  const sortingState = computed(() =>
    sort.value ? [{ id: sort.value, desc: sortDirection.value === 'desc' }] : []
  )
  const initialLoading = computed(() => loading.value && !hasLoaded.value && !items.value.length)
  const stale = computed(() => loading.value && hasLoaded.value && items.value.length > 0)
  const empty = computed(() =>
    hasLoaded.value && !loading.value && !loadError.value && items.value.length === 0
  )

  function listQuery() {
    return buildCommunicationContactListQuery({
      q: q.value,
      isActive: isActive.value,
      isProvisional: isProvisional.value,
      linked: linked.value,
      sort: sort.value,
      sortDirection: sortDirection.value,
      page: page.value,
      perPage: perPage.value
    })
  }

  async function load() {
    const sequence = ++loadSequence
    const epoch = dependencies.sessionEpoch.value
    loading.value = true
    loadError.value = null
    try {
      const response = await dependencies.list(listQuery())
      if (sequence !== loadSequence || epoch !== dependencies.sessionEpoch.value) return
      items.value = response.data
      total.value = response.meta.total
      currentPage.value = response.meta.current_page
      lastPage.value = response.meta.last_page
      hasLoaded.value = true
    } catch (caught) {
      if (sequence !== loadSequence || epoch !== dependencies.sessionEpoch.value) return
      loadError.value = apiErrorMessage(caught, 'Falha ao listar contatos.')
      dependencies.notify(loadError.value, 'error')
    } finally {
      if (sequence === loadSequence && epoch === dependencies.sessionEpoch.value) {
        loading.value = false
      }
    }
  }

  function onSearch(value: string) {
    q.value = value
    page.value = 1
  }

  function onStructuredFilters(models: DataTableFilterModel[]) {
    const activeModel = models.find(model => model.key === 'is_active')
    const provisionalModel = models.find(model => model.key === 'is_provisional')
    const linkedModel = models.find(model => model.key === 'linked')
    isActive.value = activeModel ? triState(String(activeModel.value), 'true') : 'true'
    isProvisional.value = provisionalModel ? triState(String(provisionalModel.value), 'all') : 'all'
    linked.value = linkedModel ? triState(String(linkedModel.value), 'all') : 'all'
    page.value = 1
  }

  function clearFilters() {
    isActive.value = 'true'
    isProvisional.value = 'all'
    linked.value = 'all'
    q.value = ''
    page.value = 1
  }

  function setPerPage(next: number) {
    const target = contactPageSize(next)
    if (perPage.value === target) return
    perPage.value = target
    page.value = 1
  }

  function onSortingUpdate(next: { id: string, desc: boolean }[]) {
    const first = next[0]
    if (!first || !isCommunicationContactSortField(first.id)) {
      sort.value = 'name'
      sortDirection.value = 'asc'
    } else {
      sort.value = first.id
      sortDirection.value = first.desc ? 'desc' : 'asc'
    }
    page.value = 1
  }

  function openContact(contact: CommunicationContact) {
    return dependencies.pushRoute({ path: communicationContactPath(contact.id) })
  }

  async function createContact(body: ContactCreateBody) {
    if (!dependencies.canManage.value) return false
    creating.value = true
    createError.value = null
    try {
      const created = await dependencies.create(body)
      dependencies.notify('Contato criado.', 'success')
      createOpen.value = false
      await dependencies.pushRoute({
        path: communicationContactPath(created.data.id)
      })
      return true
    } catch (caught) {
      createError.value = apiErrorMessage(caught, 'Falha ao criar contato.')
      dependencies.notify(createError.value, 'error')
      return false
    } finally {
      creating.value = false
    }
  }

  async function updateContact(contact: CommunicationContact, body: ContactUpdateBody) {
    if (!dependencies.canManage.value || contact.purged_at || updatingId.value !== null) return false
    const epoch = dependencies.sessionEpoch.value
    updatingId.value = contact.id
    updateError.value = null
    try {
      const updated = await dependencies.update(contact.id, body)
      if (epoch !== dependencies.sessionEpoch.value) return false
      items.value = items.value.map(item => item.id === contact.id ? updated.data : item)
      await load()
      if (epoch !== dependencies.sessionEpoch.value) return false
      dependencies.notify('Contato atualizado.', 'success')
      return true
    } catch (caught) {
      if (epoch !== dependencies.sessionEpoch.value) return false
      updateError.value = apiErrorMessage(caught, 'Falha ao atualizar contato.')
      dependencies.notify(updateError.value, 'error')
      return false
    } finally {
      if (epoch === dependencies.sessionEpoch.value) updatingId.value = null
    }
  }

  function resetForSession() {
    ++loadSequence
    items.value = []
    page.value = 1
    perPage.value = DEFAULT_PER_PAGE
    q.value = ''
    isActive.value = 'true'
    isProvisional.value = 'all'
    linked.value = 'all'
    sort.value = 'name'
    sortDirection.value = 'asc'
    total.value = 0
    currentPage.value = 1
    lastPage.value = 1
    hasLoaded.value = false
    loadError.value = null
    createOpen.value = false
    createError.value = null
    updatingId.value = null
    updateError.value = null
    void load()
  }

  const stopQueryWatch = watch(
    [page, q, isActive, isProvisional, linked, sort, sortDirection, perPage],
    () => { void load() }
  )
  const stopSessionWatch = watch(dependencies.sessionEpoch, resetForSession)

  function dispose() {
    stopQueryWatch()
    stopSessionWatch()
    ++loadSequence
  }

  return {
    items,
    loading,
    initialLoading,
    stale,
    empty,
    hasLoaded,
    loadError,
    page,
    perPage,
    total,
    currentPage,
    lastPage,
    q,
    isActive,
    isProvisional,
    linked,
    sort,
    sortDirection,
    filterDefinitions,
    chipModels,
    emptyKind,
    sortingState,
    createOpen,
    creating,
    createError,
    updatingId,
    updateError,
    load,
    listQuery,
    onSearch,
    onStructuredFilters,
    clearFilters,
    setPerPage,
    onSortingUpdate,
    openContact,
    createContact,
    updateContact,
    dispose
  }
}

export function useCommunicationContactsCatalog() {
  const api = useApi()
  const router = useRouter()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const canManage = computed(() => canManageCommunicationContacts(me.value))
  const surface = useSurfaceNavigationState(COMMUNICATION_SURFACES.contacts, {
    page: 1, per_page: DEFAULT_PER_PAGE, q: '', is_active: 'true', is_provisional: 'all',
    linked: 'all', sort: 'name', sort_direction: 'asc'
  }, { resetKey: () => `${me.value?.id ?? 'guest'}:${me.value?.current_tenant?.id ?? 'none'}:${sessionEpoch.value}` })
  const legacyIntent = consumeSurfaceNavigationIntent<Record<string, unknown>>(COMMUNICATION_SURFACES.contacts)
  if (legacyIntent) surface.patch(legacyIntent)
  const catalog = createCommunicationContactsCatalog({
    list: query => api.communication.contacts.list(query),
    create: body => api.communication.contacts.create(body),
    update: (id, body) => api.communication.contacts.update(id, body),
    pushRoute: location => router.push(location),
    notify: (title, color) => toast.add({ title, color }),
    sessionEpoch,
    canManage,
    initialQuery: surface.state.value
  })

  watch([catalog.page, catalog.perPage, catalog.q, catalog.isActive, catalog.isProvisional, catalog.linked, catalog.sort, catalog.sortDirection], () => {
    surface.patch({
      page: catalog.page.value, per_page: catalog.perPage.value, q: catalog.q.value,
      is_active: catalog.isActive.value, is_provisional: catalog.isProvisional.value,
      linked: catalog.linked.value, sort: catalog.sort.value ?? 'name',
      sort_direction: catalog.sortDirection.value ?? 'asc'
    })
  })

  useCommunicationProfilePictureRealtime(catalog.load)

  onMounted(() => {
    void catalog.load()
  })
  onScopeDispose(catalog.dispose)

  return {
    ...catalog,
    canManage
  }
}

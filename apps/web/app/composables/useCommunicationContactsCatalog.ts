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
import { COMMUNICATION_CONTACTS_PATH, communicationContactPath } from '~/utils/communication-routes'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import { canManageCommunicationContacts } from '~/utils/permissions'

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
}

export type CommunicationContactsCatalogDependencies = {
  list: (query: CommunicationContactListParams) => Promise<ContactListResponse>
  create: (body: ContactCreateBody) => Promise<{ data: CommunicationContact }>
  replaceRoute: (location: { path: string, query: Record<string, string | undefined> }) => unknown
  pushRoute: (path: string) => Promise<unknown> | unknown
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

export function createCommunicationContactsCatalog(
  dependencies: CommunicationContactsCatalogDependencies
) {
  const initialQuery = dependencies.initialQuery ?? {}
  const items = ref<CommunicationContact[]>([])
  const loading = ref(false)
  const hasLoaded = ref(false)
  const loadError = ref<string | null>(null)
  const page = ref(Math.max(1, Number(initialQuery.page) || 1))
  const perPage = ref(20)
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

  function syncUrl() {
    void dependencies.replaceRoute({
      path: COMMUNICATION_CONTACTS_PATH,
      query: {
        page: page.value > 1 ? String(page.value) : undefined,
        q: q.value || undefined,
        is_active: isActive.value !== 'true' ? isActive.value : undefined,
        is_provisional: isProvisional.value !== 'all' ? isProvisional.value : undefined,
        linked: linked.value !== 'all' ? linked.value : undefined,
        sort: sort.value && sort.value !== 'name' ? sort.value : undefined,
        sort_direction: sort.value && sortDirection.value === 'desc' ? 'desc' : undefined,
        per_page: perPage.value !== 20 ? String(perPage.value) : undefined
      }
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
    const target = [10, 20, 50].includes(Number(next)) ? Number(next) : 20
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
    return dependencies.pushRoute(communicationContactPath(contact.id))
  }

  async function createContact(body: ContactCreateBody) {
    if (!dependencies.canManage.value) return false
    creating.value = true
    createError.value = null
    try {
      const created = await dependencies.create(body)
      dependencies.notify('Contato criado.', 'success')
      createOpen.value = false
      await dependencies.pushRoute(communicationContactPath(created.data.id))
      return true
    } catch (caught) {
      createError.value = apiErrorMessage(caught, 'Falha ao criar contato.')
      dependencies.notify(createError.value, 'error')
      return false
    } finally {
      creating.value = false
    }
  }

  function resetForSession() {
    ++loadSequence
    items.value = []
    page.value = 1
    perPage.value = 20
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
    void load()
  }

  const stopQueryWatch = watch(
    [page, q, isActive, isProvisional, linked, sort, sortDirection, perPage],
    () => {
      syncUrl()
      void load()
    }
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
    load,
    listQuery,
    onSearch,
    onStructuredFilters,
    clearFilters,
    setPerPage,
    onSortingUpdate,
    openContact,
    createContact,
    dispose
  }
}

export function useCommunicationContactsCatalog() {
  const api = useApi()
  const router = useRouter()
  const route = useRoute()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const canManage = computed(() => canManageCommunicationContacts(me.value))
  const catalog = createCommunicationContactsCatalog({
    list: query => api.communication.contacts.list(query),
    create: body => api.communication.contacts.create(body),
    replaceRoute: location => router.replace(location),
    pushRoute: path => router.push(path),
    notify: (title, color) => toast.add({ title, color }),
    sessionEpoch,
    canManage,
    initialQuery: route.query
  })

  onMounted(() => {
    void catalog.load()
  })
  onScopeDispose(catalog.dispose)

  return {
    ...catalog,
    canManage
  }
}

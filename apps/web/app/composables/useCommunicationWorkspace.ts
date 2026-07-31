import { createSharedComposable, useDebounceFn } from '@vueuse/core'
import type { TenantMember } from '~/types/api'
import type {
  CommunicationAutomationMeta,
  CommunicationAutomationPolicy,
  CommunicationBulkAction,
  CommunicationBulkOperation,
  CommunicationBulkOperationParams,
  CommunicationCannedResponse,
  CommunicationChatPresence,
  CommunicationChatPresenceSignal,
  CommunicationContactPresenceSignal,
  CommunicationComposerPayload,
  CommunicationConversation,
  CommunicationConversationListMeta,
  CommunicationConversationListPreferences,
  CommunicationConversationSignals,
  CommunicationConversationSortBy,
  CommunicationConversationStatus,
  CommunicationConversationTimelineMeta,
  CommunicationConversationTimelineState,
  CommunicationEvent,
  CommunicationFeatureMeta,
  CommunicationInbox,
  CommunicationLabel,
  CommunicationListPreferenceStatus,
  CommunicationMessage,
  CommunicationPairingState,
  CommunicationRecipientConfiguration,
  CommunicationRecipientMode,
  CommunicationRealtimeEvent,
  CommunicationSessionStatus
} from '~/types/communication'
import type { WorkDepartment } from '~/types/work'
import { apiErrorCode, apiErrorMessage } from '~/utils/api-error'
import {
  buildConversationBulkItems,
  communicationSelectionQueryKey,
  conversationSelectionState,
  mergeConversationListInApiOrder,
  pruneConversationSelection,
  selectLoadedConversationIds,
  toggleConversationSelection
} from '~/utils/communication-conversation-selection'
import {
  COMMUNICATION_DEFAULT_SORT_BY,
  normalizeCommunicationConversationSortBy
} from '~/utils/communication-conversation-sort'
import {
  communicationSignalFromEvent,
  isCommunicationEphemeralEvent,
  latestCommunicationCursor,
  mergeCommunicationConversations,
  mergeCommunicationEvents,
  mergeCommunicationMessages,
  normalizeCommunicationCursor
} from '~/utils/communication'
import {
  canRetryCommunicationReadAcknowledgement,
  isCommunicationReadStateVersionNewer,
  mergeCommunicationReadThroughMessageId,
  shouldMarkCommunicationTimelineRead
} from '~/utils/communication-timeline'
import {
  canManageCommunication as userCanManageCommunication,
  canManageCommunicationContacts as userCanManageCommunicationContacts,
  canReplyCommunication as userCanReplyCommunication,
  canViewCommunication as userCanViewCommunication
} from '~/utils/permissions'
import type {
  CommunicationConversationFilters,
  CommunicationPolicyBody
} from './api/createCommunicationApi'
import {
  COMMUNICATION_SURFACES,
  consumeSurfaceNavigationIntent,
  useSurfaceNavigationState
} from './useSurfaceNavigationState'

const EMPTY_FEATURE_META: CommunicationFeatureMeta = {
  global_enabled: false,
  gateway_enabled: false,
  tenant_enabled: false
}

const EMPTY_AUTOMATION_META: CommunicationAutomationMeta = {
  supported_scopes: [],
  inboxes: [],
  tenant_enabled: false,
  global_enabled: false
}

export const COMMUNICATION_CONVERSATION_PAGE_SIZE = 50
export const COMMUNICATION_TIMELINE_PAGE_SIZE = 50
export const COMMUNICATION_PREFETCH_CONCURRENCY = 2

type CommunicationWorkspaceNavigationState = {
  search: string
  inboxFilter: number | null
  statusFilter: CommunicationConversationStatus | null
  assigneeFilter: number | null
  departmentFilter: number | null
  unassignedOnly: boolean
  unreadOnly: boolean
  labelIdsFilter: number[]
  contactIdFilter: number | null
  sortBy: CommunicationConversationSortBy
}

type CommunicationLifecycleRequest = {
  lifecycleEpoch: number
  sessionEpoch: number
}

type CommunicationSynchronizationRequest = CommunicationLifecycleRequest & {
  generation: number
}

const communicationWorkspaceNavigationDefaults = (): CommunicationWorkspaceNavigationState => ({
  search: '',
  inboxFilter: null,
  statusFilter: 'OPEN',
  assigneeFilter: null,
  departmentFilter: null,
  unassignedOnly: false,
  unreadOnly: false,
  labelIdsFilter: [],
  contactIdFilter: null,
  sortBy: COMMUNICATION_DEFAULT_SORT_BY
})

function normalizeCommunicationWorkspaceNavigation(
  value: CommunicationWorkspaceNavigationState
): CommunicationWorkspaceNavigationState {
  const positiveId = (candidate: unknown): number | null =>
    typeof candidate === 'number' && Number.isSafeInteger(candidate) && candidate > 0
      ? candidate
      : null
  const status = ['OPEN', 'PENDING', 'SNOOZED', 'RESOLVED'].includes(String(value.statusFilter))
    ? value.statusFilter
    : null
  const unassignedOnly = value.unassignedOnly === true

  return {
    search: typeof value.search === 'string' ? value.search : '',
    inboxFilter: positiveId(value.inboxFilter),
    statusFilter: status,
    assigneeFilter: unassignedOnly ? null : positiveId(value.assigneeFilter),
    departmentFilter: positiveId(value.departmentFilter),
    unassignedOnly,
    unreadOnly: value.unreadOnly === true,
    labelIdsFilter: Array.from(new Set(
      Array.isArray(value.labelIdsFilter)
        ? value.labelIdsFilter.filter(id => Number.isSafeInteger(id) && id > 0)
        : []
    )),
    contactIdFilter: positiveId(value.contactIdFilter),
    sortBy: normalizeCommunicationConversationSortBy(value.sortBy)
  }
}

const EMPTY_TIMELINE_META: CommunicationConversationTimelineMeta = {
  older_cursor: null,
  newer_cursor: null,
  first_unread_message_id: null,
  snapshot_through_message_id: null,
  read_state_version: 0,
  unread_count: 0,
  limit: COMMUNICATION_TIMELINE_PAGE_SIZE
}

function emptyTimelineState(): CommunicationConversationTimelineState {
  return {
    meta: { ...EMPTY_TIMELINE_META },
    divider_message_id: null,
    initialized: false,
    initial_read_pending: false,
    manual_unread: false,
    loading: false,
    loading_older: false,
    loading_newer: false,
    error: null
  }
}

export function mergeCommunicationConversationPage(
  current: CommunicationConversation[],
  incoming: CommunicationConversation[],
  append: boolean
): CommunicationConversation[] {
  // Mantém a ordenação autoritativa da API (sort_by), sem reordenar no cliente.
  return mergeConversationListInApiOrder(current, incoming, append)
}

export function isCommunicationConversationRequestCurrent(
  request: { generation: number, sessionEpoch: number },
  current: { generation: number, sessionEpoch: number }
): boolean {
  return request.generation === current.generation
    && request.sessionEpoch === current.sessionEpoch
}

const _useCommunicationWorkspace = () => {
  const api = useApi()
  const toast = useToast()
  const { me, sessionEpoch } = useDashboard()
  const navigationState = useSurfaceNavigationState<CommunicationWorkspaceNavigationState>(
    COMMUNICATION_SURFACES.workspace,
    communicationWorkspaceNavigationDefaults,
    {
      // A identidade, o tenant efetivo e o epoch compõem o isolamento lógico.
      resetKey: () => `${me.value?.id ?? 'guest'}:${me.value?.current_tenant?.id ?? 'none'}:${sessionEpoch.value}`,
      normalize: normalizeCommunicationWorkspaceNavigation
    }
  )
  const realtime = useNuxtApp().$communicationRealtime

  const inboxes = ref<CommunicationInbox[]>([])
  const featureMeta = ref<CommunicationFeatureMeta>({ ...EMPTY_FEATURE_META })
  const conversations = ref<CommunicationConversation[]>([])
  const conversationDetails = ref<Record<number, CommunicationConversation>>({})
  const conversationTimelines = ref<Record<number, CommunicationConversationTimelineState>>({})
  const selectedConversationId = ref<number | null>(null)
  const openingConversationId = ref<number | null>(null)
  /** Seleção operacional (bulk) — independente do detalhe aberto. */
  const selectedConversationIds = ref<Set<number>>(new Set())
  const labels = ref<CommunicationLabel[]>([])
  const cannedResponses = ref<CommunicationCannedResponse[]>([])
  const events = ref<CommunicationEvent[]>([])
  const cursor = ref(0)
  const policies = ref<CommunicationAutomationPolicy[]>([])
  const automationMeta = ref<CommunicationAutomationMeta>({ ...EMPTY_AUTOMATION_META })
  const tenantMembers = ref<TenantMember[]>([])
  const departments = ref<WorkDepartment[]>([])
  const chatPresenceByConversation = ref<Record<number, CommunicationChatPresenceSignal>>({})
  const contactPresenceByConversation = ref<Record<number, CommunicationContactPresenceSignal>>({})
  const pendingBulkOperation = ref<CommunicationBulkOperation | null>(null)
  const bulkSubmitting = ref(false)
  const preferencesLoaded = ref(false)
  const preferencesUnavailable = ref(false)

  const loading = ref(false)
  const conversationsLoading = ref(false)
  const conversationsRefreshing = ref(false)
  const conversationsLoadingMore = ref(false)
  const conversationsLoaded = ref(false)
  const conversationsPage = ref(0)
  const conversationsLastPage = ref(1)
  const conversationsTotal = ref(0)
  const conversationsLoadMoreError = ref<string | null>(null)
  const sending = ref(false)
  const syncing = ref(false)
  const adminLoading = ref(false)
  const messageActionLoadingId = ref<number | null>(null)
  const error = ref<string | null>(null)
  const syncError = ref<string | null>(null)
  const initialized = ref(false)

  const search = computed({
    get: () => navigationState.state.value.search,
    set: (value: string) => navigationState.patch({ search: value })
  })
  const inboxFilter = computed({
    get: () => navigationState.state.value.inboxFilter,
    set: (value: number | null) => navigationState.patch({ inboxFilter: value })
  })
  const statusFilter = computed({
    get: () => navigationState.state.value.statusFilter,
    set: (value: CommunicationConversationStatus | null) => navigationState.patch({ statusFilter: value })
  })
  const assigneeFilter = computed({
    get: () => navigationState.state.value.assigneeFilter,
    set: (value: number | null) => navigationState.patch({ assigneeFilter: value })
  })
  const departmentFilter = computed({
    get: () => navigationState.state.value.departmentFilter,
    set: (value: number | null) => navigationState.patch({ departmentFilter: value })
  })
  const unassignedOnly = computed({
    get: () => navigationState.state.value.unassignedOnly,
    set: (value: boolean) => navigationState.patch({ unassignedOnly: value })
  })
  const unreadOnly = computed({
    get: () => navigationState.state.value.unreadOnly,
    set: (value: boolean) => navigationState.patch({ unreadOnly: value })
  })
  const labelIdsFilter = computed({
    get: () => navigationState.state.value.labelIdsFilter,
    set: (value: number[]) => navigationState.patch({ labelIdsFilter: value })
  })
  const contactIdFilter = computed({
    get: () => navigationState.state.value.contactIdFilter,
    set: (value: number | null) => navigationState.patch({ contactIdFilter: value })
  })
  const sortBy = computed({
    get: () => navigationState.state.value.sortBy,
    set: (value: CommunicationConversationSortBy) => navigationState.patch({ sortBy: value })
  })
  let preferencesSaveGeneration = 0
  let bulkPollTimer: ReturnType<typeof setTimeout> | null = null
  let lastSelectionQueryKey = ''
  let bulkIdempotency: { fingerprint: string, key: string } | null = null
  let suppressPreferenceSave = false
  const conversationsHasMore = computed(() =>
    conversationsPage.value > 0 && conversationsPage.value < conversationsLastPage.value)
  const conversationsInitialLoading = computed(() =>
    conversationsLoading.value && !conversationsLoaded.value && conversations.value.length === 0)
  const conversationsEmpty = computed(() =>
    conversationsLoaded.value && !conversationsLoading.value && conversations.value.length === 0)
  const selectionMeta = computed(() =>
    conversationSelectionState(selectedConversationIds.value, conversations.value))
  const selectedConversationCount = computed(() => selectionMeta.value.selectedCount)
  const allLoadedSelected = computed(() => selectionMeta.value.allLoadedSelected)
  const selectionIndeterminate = computed(() => selectionMeta.value.indeterminate)

  const canView = computed(() => userCanViewCommunication(me.value))
  const canReply = computed(() => userCanReplyCommunication(me.value))
  const canManage = computed(() => userCanManageCommunication(me.value))
  const canManageContacts = computed(() => userCanManageCommunicationContacts(me.value))
  const selectedConversation = computed(() => {
    const id = selectedConversationId.value
    if (id === null) return null
    return conversationDetails.value[id]
      ?? conversations.value.find(item => item.id === id)
      ?? null
  })
  const selectedTimeline = computed<CommunicationConversationTimelineState | null>(() => {
    const id = selectedConversationId.value
    return id === null ? null : conversationTimelines.value[id] ?? null
  })
  const selectedInbox = computed(() =>
    inboxes.value.find(item => item.id === selectedConversation.value?.inbox_id) ?? null)
  const selectedSignals = computed<CommunicationConversationSignals>(() => {
    const conversationId = selectedConversationId.value
    if (conversationId === null) return {}
    return {
      chat: chatPresenceByConversation.value[conversationId] ?? null,
      contact: contactPresenceByConversation.value[conversationId] ?? null
    }
  })
  const communicationOperational = computed(() => Boolean(
    featureMeta.value.global_enabled
    && featureMeta.value.gateway_enabled
    && featureMeta.value.tenant_enabled
    && selectedInbox.value?.is_enabled
  ))
  const outboundOperational = computed(() =>
    communicationOperational.value && selectedInbox.value?.status === 'CONNECTED')
  const communicationBlockReason = computed(() => {
    if (!featureMeta.value.global_enabled) {
      return 'O ambiente está com a comunicação desativada pelo switch global.'
    }
    if (!featureMeta.value.gateway_enabled) {
      return 'O gateway do WhatsApp está desativado neste ambiente.'
    }
    if (!featureMeta.value.tenant_enabled) {
      return 'A comunicação deste escritório está desativada.'
    }
    if (!selectedInbox.value) return 'Selecione uma sessão WhatsApp para continuar.'
    if (!selectedInbox.value.is_enabled) {
      return 'Este canal está desativado. Habilite-o na administração da comunicação.'
    }
    if (selectedInbox.value.status === 'CONNECTING') {
      return 'A sessão WhatsApp está conectando. Aguarde a conclusão ou confira o QR na administração.'
    }
    if (selectedInbox.value.status === 'DISCONNECTED') {
      return 'A sessão WhatsApp está desconectada. Use “Conectar” na administração da comunicação.'
    }
    return ''
  })

  const subscriptions = new Map<number, () => void>()
  const signalTimers = new Map<string, ReturnType<typeof setTimeout>>()
  const presenceSubscriptions = new Set<number>()
  const detailRequests = new Map<number, Promise<boolean>>()
  const initialTimelineRequests = new Map<string, Promise<boolean>>()
  const queuedConversationPrefetchIds = new Set<number>()
  const activeConversationPrefetchIds = new Set<number>()
  const conversationPrefetchQueue: number[] = []
  const conversationTimelineGenerations = new Map<number, number>()
  let tenantSubscription: (() => void) | null = null
  let synchronizeAgain = false
  let selectionEpoch = 0
  let conversationQueryGeneration = 0
  let conversationQueryController: AbortController | null = null
  let lastPresenceState: CommunicationChatPresence | null = null
  let lastPresenceSentAt = 0
  let lifecycleEpoch = 0
  let synchronizeGeneration = 0

  function beginConversationTimelineRequest(id: number) {
    const generation = (conversationTimelineGenerations.get(id) ?? 0) + 1
    conversationTimelineGenerations.set(id, generation)
    return { generation, lifecycleEpoch, sessionEpoch: sessionEpoch.value }
  }

  function isConversationTimelineRequestCurrent(
    id: number,
    request: { generation: number, lifecycleEpoch: number, sessionEpoch: number }
  ): boolean {
    return request.sessionEpoch === sessionEpoch.value
      && request.lifecycleEpoch === lifecycleEpoch
      && request.generation === conversationTimelineGenerations.get(id)
  }

  function currentLifecycleRequest(): CommunicationLifecycleRequest {
    return { lifecycleEpoch, sessionEpoch: sessionEpoch.value }
  }

  function isLifecycleRequestCurrent(request: CommunicationLifecycleRequest): boolean {
    return request.lifecycleEpoch === lifecycleEpoch
      && request.sessionEpoch === sessionEpoch.value
  }

  function isSynchronizationRequestCurrent(
    request: CommunicationSynchronizationRequest
  ): boolean {
    return request.generation === synchronizeGeneration
      && request.lifecycleEpoch === lifecycleEpoch
      && request.sessionEpoch === sessionEpoch.value
  }

  function listFilters(page = 1): CommunicationConversationFilters {
    return {
      q: search.value || undefined,
      inbox_id: inboxFilter.value || undefined,
      status: statusFilter.value || undefined,
      assignee_membership_id: assigneeFilter.value || undefined,
      work_department_id: departmentFilter.value || undefined,
      unassigned: unassignedOnly.value || undefined,
      unread: unreadOnly.value || undefined,
      label_ids: labelIdsFilter.value.length ? labelIdsFilter.value : undefined,
      contact_id: contactIdFilter.value || undefined,
      sort_by: normalizeCommunicationConversationSortBy(sortBy.value),
      page,
      per_page: COMMUNICATION_CONVERSATION_PAGE_SIZE
    }
  }

  function preferenceStatusValue(): CommunicationListPreferenceStatus {
    return statusFilter.value ?? 'ALL'
  }

  function clearOperationalSelection(): void {
    if (selectedConversationIds.value.size === 0) return
    selectedConversationIds.value = new Set()
    bulkIdempotency = null
  }

  function setConversationSelected(conversationId: number, selected: boolean): void {
    const next = toggleConversationSelection(
      selectedConversationIds.value,
      conversationId,
      selected
    )
    if (next.size !== selectedConversationIds.value.size || next.has(conversationId) !== selectedConversationIds.value.has(conversationId)) {
      bulkIdempotency = null
    }
    selectedConversationIds.value = next
  }

  function selectAllLoadedConversations(): void {
    const next = selectLoadedConversationIds(conversations.value)
    const current = selectedConversationIds.value
    const selectionChanged = next.size !== current.size
      || [...next].some(id => !current.has(id))
    if (selectionChanged) {
      bulkIdempotency = null
    }
    selectedConversationIds.value = next
  }

  function toggleSelectAllLoaded(selected: boolean): void {
    if (selected) selectAllLoadedConversations()
    else clearOperationalSelection()
  }

  function isConversationSelected(conversationId: number): boolean {
    return selectedConversationIds.value.has(conversationId)
  }

  function selectionQueryContext() {
    return {
      q: search.value,
      inboxId: inboxFilter.value,
      status: statusFilter.value,
      assigneeMembershipId: assigneeFilter.value,
      workDepartmentId: departmentFilter.value,
      unassignedOnly: unassignedOnly.value,
      unreadOnly: unreadOnly.value,
      labelIds: labelIdsFilter.value,
      contactId: contactIdFilter.value,
      sortBy: sortBy.value
    }
  }

  function stopBulkPoll(): void {
    if (bulkPollTimer === null) return
    clearTimeout(bulkPollTimer)
    bulkPollTimer = null
  }

  function toastBulkTerminal(operation: CommunicationBulkOperation): void {
    const succeeded = operation.succeeded_count
    const failed = operation.failed_count
    const skipped = operation.skipped_count
    if (operation.status === 'COMPLETED' && failed === 0) {
      toast.add({
        title: 'Ação em lote concluída',
        description: `${succeeded} conversa(s) atualizada(s).`,
        color: 'success'
      })
      return
    }
    if (operation.status === 'COMPLETED_WITH_ERRORS' || (succeeded > 0 && failed > 0)) {
      toast.add({
        title: 'Ação em lote parcial',
        description: `${succeeded} ok · ${failed} falha(s)${skipped ? ` · ${skipped} ignorada(s)` : ''}.`,
        color: 'warning'
      })
      return
    }
    toast.add({
      title: 'Ação em lote falhou',
      description: operation.error_message
        || `${failed || operation.item_count} item(ns) sem sucesso.`,
      color: 'error'
    })
  }

  async function pollBulkOperation(
    operationId: string,
    attempt = 0,
    transientFailures = 0
  ): Promise<void> {
    stopBulkPoll()
    const epoch = sessionEpoch.value
    try {
      const response = await api.communication.conversationBulkOperations.get(operationId)
      if (epoch !== sessionEpoch.value) return
      const operation = response.data
      pendingBulkOperation.value = operation
      if (!operation.is_terminal) {
        if (attempt >= 40) {
          toast.add({
            title: 'Não foi possível confirmar a conclusão da ação em lote.',
            color: 'warning'
          })
          await reloadConversations().catch(() => undefined)
          if (epoch !== sessionEpoch.value) return
          pendingBulkOperation.value = null
          return
        }
        bulkPollTimer = setTimeout(() => {
          if (epoch !== sessionEpoch.value) return
          void pollBulkOperation(operationId, attempt + 1, 0)
        }, 1_500)
        return
      }
      toastBulkTerminal(operation)
      pendingBulkOperation.value = null
      await reloadConversations().catch(() => undefined)
    } catch (caught) {
      if (epoch !== sessionEpoch.value) return
      if (transientFailures < 3) {
        bulkPollTimer = setTimeout(() => {
          if (epoch !== sessionEpoch.value) return
          void pollBulkOperation(operationId, attempt, transientFailures + 1)
        }, 3_000)
        return
      }
      toast.add({
        title: apiErrorMessage(caught, 'Não foi possível acompanhar a ação em lote.'),
        color: 'error'
      })
      await reloadConversations().catch(() => undefined)
      if (epoch !== sessionEpoch.value) return
      pendingBulkOperation.value = null
    }
  }

  async function submitBulkOperation(
    action: CommunicationBulkAction,
    params?: CommunicationBulkOperationParams
  ): Promise<boolean> {
    if (
      bulkSubmitting.value
      || pendingBulkOperation.value !== null
      || selectedConversationIds.value.size === 0
    ) {
      return false
    }
    const items = buildConversationBulkItems(
      conversations.value,
      selectedConversationIds.value,
      action
    )
    if (!items.length) {
      toast.add({
        title: 'Nenhuma conversa elegível',
        description: 'As conversas selecionadas não possuem snapshot válido para esta ação.',
        color: 'warning'
      })
      return false
    }
    bulkSubmitting.value = true
    const epoch = sessionEpoch.value
    try {
      const fingerprint = JSON.stringify({ action, params: params ?? null, items })
      if (bulkIdempotency?.fingerprint !== fingerprint) {
        bulkIdempotency = { fingerprint, key: crypto.randomUUID() }
      }
      const response = await api.communication.conversationBulkOperations.create(
        { action, params, items },
        bulkIdempotency.key
      )
      if (epoch !== sessionEpoch.value) return false
      clearOperationalSelection()
      pendingBulkOperation.value = response.data
      toast.add({
        title: 'Ação agendada',
        description: `${items.length} conversa(s) enfileirada(s).`,
        color: 'success'
      })
      void pollBulkOperation(response.data.id)
      return true
    } catch (caught) {
      if (epoch !== sessionEpoch.value) return false
      // Mantém seleção em erro de submissão.
      toast.add({
        title: apiErrorMessage(caught, 'Falha ao agendar a ação em lote.'),
        color: 'error'
      })
      return false
    } finally {
      bulkSubmitting.value = false
    }
  }

  async function loadListPreferences(): Promise<void> {
    const request = currentLifecycleRequest()
    try {
      const response = await api.communication.conversationListPreferences.get()
      if (!isLifecycleRequestCurrent(request)) return
      preferencesUnavailable.value = false
      applyListPreferences(response.data)
    } catch {
      if (!isLifecycleRequestCurrent(request)) return
      preferencesUnavailable.value = true
      // Defaults locais já estão aplicados (OPEN / last_activity_desc).
      // Não tenta regravar preferência até o endpoint voltar.
    } finally {
      if (isLifecycleRequestCurrent(request)) preferencesLoaded.value = true
    }
  }

  function applyListPreferences(prefs: CommunicationConversationListPreferences): void {
    suppressPreferenceSave = true
    if (prefs.status === 'ALL') statusFilter.value = null
    else statusFilter.value = prefs.status
    sortBy.value = normalizeCommunicationConversationSortBy(prefs.sort_by)
    nextTick(() => {
      suppressPreferenceSave = false
    })
  }

  const persistListPreferences = useDebounceFn(async () => {
    if (
      !initialized.value
      || !preferencesLoaded.value
      || preferencesUnavailable.value
      || suppressPreferenceSave
    ) return
    const generation = ++preferencesSaveGeneration
    const payload = {
      status: preferenceStatusValue(),
      sort_by: normalizeCommunicationConversationSortBy(sortBy.value)
    }
    // Garante que o estado local espelha o valor allowlisted enviado.
    if (sortBy.value !== payload.sort_by) {
      sortBy.value = payload.sort_by
    }
    try {
      await api.communication.conversationListPreferences.update(payload)
    } catch (caught) {
      if (generation !== preferencesSaveGeneration) return
      toast.add({
        title: apiErrorMessage(caught, 'Não foi possível salvar preferências da lista.'),
        description: 'Os filtros da sessão permanecem; a preferência não foi persistida.',
        color: 'error'
      })
    }
  }, 400)

  function resolveThroughMessageId(conversationId: number, preferred?: number | null): number | null {
    if (preferred != null && preferred > 0) return preferred
    const snapshot = conversationTimelines.value[conversationId]?.meta.snapshot_through_message_id
    if (snapshot != null && snapshot > 0) return snapshot
    const detail = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    if (!detail) return null
    const messages = detail.messages
    if (Array.isArray(messages) && messages.length > 0) {
      return Math.max(...messages.map(message => message.id))
    }
    return detail.last_message?.id ?? null
  }

  async function markConversationRead(
    conversationId: number,
    upToMessageId?: number | null
  ): Promise<boolean> {
    const throughMessageId = resolveThroughMessageId(conversationId, upToMessageId)
    if (throughMessageId == null) return false
    try {
      const response = await api.communication.conversations.markRead(conversationId, {
        through_message_id: throughMessageId
      })
      patchConversationReadState(response.data)
      const timeline = conversationTimelines.value[conversationId]
      if (timeline) {
        conversationTimelines.value = {
          ...conversationTimelines.value,
          [conversationId]: {
            ...timeline,
            meta: {
              ...timeline.meta,
              unread_count: response.data.unread_count ?? 0,
              first_unread_message_id: response.data.first_unread_message_id ?? null,
              read_state_version: response.data.read_state?.version ?? timeline.meta.read_state_version
            },
            initial_read_pending: false,
            manual_unread: false
          }
        }
      }
      return true
    } catch {
      // Fail closed: keep last known unread without inventing local counts.
      return false
    }
  }

  async function markConversationUnread(conversationId: number): Promise<boolean> {
    const detail = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    const expectedVersion = detail?.read_state?.version ?? 0
    const timeline = conversationTimelines.value[conversationId]
    const previousManualUnread = timeline?.manual_unread ?? false
    if (timeline) {
      conversationTimelines.value = {
        ...conversationTimelines.value,
        [conversationId]: {
          ...timeline,
          manual_unread: true
        }
      }
    }
    try {
      const response = await api.communication.conversations.markUnread(conversationId, {
        expected_version: expectedVersion
      })
      patchConversationReadState(response.data)
      const current = conversationTimelines.value[conversationId]
      if (current) {
        conversationTimelines.value = {
          ...conversationTimelines.value,
          [conversationId]: {
            ...current,
            divider_message_id: current.divider_message_id
              ?? response.data.first_unread_message_id
              ?? null,
            meta: {
              ...current.meta,
              unread_count: response.data.unread_count ?? current.meta.unread_count,
              first_unread_message_id: response.data.first_unread_message_id ?? null,
              read_state_version: response.data.read_state?.version ?? current.meta.read_state_version
            },
            manual_unread: true
          }
        }
      }
      return true
    } catch (caught) {
      if (apiErrorCode(caught) === 'READ_STATE_VERSION_CONFLICT') {
        await Promise.all([
          refreshConversationDetail(conversationId, { reportError: false }),
          loadConversations({ silent: true }).catch(() => undefined)
        ])
      }
      const current = conversationTimelines.value[conversationId]
      if (current) {
        updateConversationTimeline(conversationId, {
          manual_unread: previousManualUnread
        })
      }
      // Fail closed: keep last known unread without inventing local counts.
      return false
    }
  }

  function patchConversationReadState(conversation: CommunicationConversation): void {
    const apply = (item: CommunicationConversation): CommunicationConversation => ({
      ...item,
      unread_count: conversation.unread_count ?? item.unread_count ?? 0,
      first_unread_message_id: conversation.first_unread_message_id ?? null,
      last_read_message_id: conversation.last_read_message_id
        ?? conversation.read_state?.last_read_through_message_id
        ?? item.last_read_message_id
        ?? null,
      last_read_at: conversation.last_read_at ?? item.last_read_at ?? null,
      read_state: conversation.read_state ?? item.read_state ?? null,
      lock_version: conversation.lock_version ?? item.lock_version
    })
    conversations.value = conversations.value.map(item =>
      item.id === conversation.id ? apply(item) : item
    )
    const detail = conversationDetails.value[conversation.id]
    if (detail) {
      conversationDetails.value = {
        ...conversationDetails.value,
        [conversation.id]: apply(detail)
      }
    }
  }

  async function loadInboxes(): Promise<void> {
    const request = currentLifecycleRequest()
    const response = await api.communication.inboxes.list()
    if (!isLifecycleRequestCurrent(request)) return
    inboxes.value = response.data
    featureMeta.value = response.meta
    departments.value = response.meta.departments ?? departments.value
    reconcileSubscriptions()
  }

  function resetConversationPagination(): void {
    conversationsPage.value = 0
    conversationsLastPage.value = 1
    conversationsTotal.value = 0
    conversationsLoaded.value = false
    conversationsLoadMoreError.value = null
  }

  function applyConversationMeta(meta: CommunicationConversationListMeta): void {
    conversationsPage.value = meta.current_page
    conversationsLastPage.value = Math.max(meta.current_page, meta.last_page)
    conversationsTotal.value = meta.total
  }

  async function loadConversations(options?: {
    silent?: boolean
    append?: boolean
  }): Promise<void> {
    const append = options?.append === true
    if (append && (
      !conversationsHasMore.value
      || conversationsLoading.value
      || conversationsLoadingMore.value
    )) return

    const page = append ? conversationsPage.value + 1 : 1
    if (!append) conversationQueryGeneration++
    const request = {
      generation: conversationQueryGeneration,
      sessionEpoch: sessionEpoch.value
    }
    conversationQueryController?.abort()
    const controller = new AbortController()
    conversationQueryController = controller
    if (append) {
      conversationsLoadingMore.value = true
      conversationsLoadMoreError.value = null
    } else {
      conversationsLoading.value = true
      conversationsRefreshing.value = conversationsLoaded.value && conversations.value.length > 0
      error.value = null
    }
    try {
      const response = await api.communication.conversations.list(
        listFilters(page),
        { signal: controller.signal }
      )
      if (!isCommunicationConversationRequestCurrent(request, {
        generation: conversationQueryGeneration,
        sessionEpoch: sessionEpoch.value
      })) return
      const nextDetails = { ...conversationDetails.value }
      const visible = response.data.map((summary) => {
        const cached = nextDetails[summary.id]
        if (!cached) return summary
        const merged = mergeCommunicationConversations([cached], [summary])[0] ?? summary
        nextDetails[summary.id] = merged
        return merged
      })
      const pinnedId = !append && unreadOnly.value ? selectedConversationId.value : null
      const pinned = pinnedId !== null ? nextDetails[pinnedId] : null
      if (pinned && !visible.some(item => item.id === pinned.id)) {
        visible.unshift(pinned)
      }
      conversationDetails.value = nextDetails
      conversations.value = mergeCommunicationConversationPage(
        conversations.value,
        visible,
        append
      )
      // Load more / refresh: preserva IDs ainda presentes; não auto-seleciona novos.
      const prunedSelection = pruneConversationSelection(
        selectedConversationIds.value,
        conversations.value
      )
      if (prunedSelection.size !== selectedConversationIds.value.size) {
        bulkIdempotency = null
      }
      selectedConversationIds.value = prunedSelection
      applyConversationMeta(response.meta)
      conversationsLoaded.value = true
    } catch (caught) {
      if (controller.signal.aborted) return
      const message = apiErrorMessage(
        caught,
        append ? 'Falha ao carregar mais conversas.' : 'Falha ao carregar conversas.'
      )
      if (append) {
        conversationsLoadMoreError.value = message
        return
      }
      error.value = message
      if (!options?.silent) {
        toast.add({ title: error.value, color: 'error' })
      }
      throw caught
    } finally {
      if (conversationQueryController === controller) {
        conversationQueryController = null
      }
      if (isCommunicationConversationRequestCurrent(request, {
        generation: conversationQueryGeneration,
        sessionEpoch: sessionEpoch.value
      })) {
        if (append) conversationsLoadingMore.value = false
        else {
          conversationsLoading.value = false
          conversationsRefreshing.value = false
        }
      }
    }
  }

  async function loadMoreConversations(): Promise<void> {
    await loadConversations({ append: true, silent: true })
  }

  async function reloadConversations(): Promise<void> {
    await loadConversations()
  }

  async function loadCatalog(): Promise<void> {
    const request = currentLifecycleRequest()
    const [labelsResponse, cannedResponse] = await Promise.all([
      api.communication.catalog.labels(),
      api.communication.catalog.cannedResponses()
    ])
    if (!isLifecycleRequestCurrent(request)) return
    labels.value = labelsResponse.data
    cannedResponses.value = cannedResponse.data
  }

  function hasConversationDetail(id: number): boolean {
    return conversationDetails.value[id] !== undefined
  }

  function storeConversationDetail(incoming: CommunicationConversation): void {
    const cached = conversationDetails.value[incoming.id]
    const detail = mergeCommunicationConversations(cached ? [cached] : [], [incoming])[0] ?? incoming
    conversationDetails.value = {
      ...conversationDetails.value,
      [incoming.id]: detail
    }
    // Atualiza a linha in-place sem reordenar a fila (ordem vem do sort_by da API).
    if (selectedConversationId.value === incoming.id
      || conversations.value.some(item => item.id === incoming.id)) {
      conversations.value = conversations.value.map(item =>
        item.id === detail.id ? detail : item
      )
    }
  }

  function storeConversationMessages(
    conversationId: number,
    incoming: CommunicationMessage[],
    replace: boolean
  ): void {
    const base = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    if (!base) return
    const detail: CommunicationConversation = {
      ...base,
      messages: replace
        ? [...incoming]
        : mergeCommunicationMessages(base.messages ?? [], incoming)
    }
    conversationDetails.value = {
      ...conversationDetails.value,
      [conversationId]: detail
    }
    conversations.value = conversations.value.map(item =>
      item.id === conversationId ? detail : item
    )
  }

  function patchConversationTimelineProjection(
    conversationId: number,
    meta: CommunicationConversationTimelineMeta
  ): void {
    const known = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    const knownVersion = known?.read_state?.version
    if (knownVersion !== undefined
      && !isCommunicationReadStateVersionNewer(meta.read_state_version, knownVersion)) {
      return
    }
    const apply = (item: CommunicationConversation): CommunicationConversation => ({
      ...item,
      unread_count: meta.unread_count,
      first_unread_message_id: meta.first_unread_message_id,
      read_state: {
        version: meta.read_state_version,
        last_read_through_message_id: item.read_state?.last_read_through_message_id ?? null
      }
    })
    conversations.value = conversations.value.map(item =>
      item.id === conversationId ? apply(item) : item
    )
    const detail = conversationDetails.value[conversationId]
    if (detail) {
      conversationDetails.value = {
        ...conversationDetails.value,
        [conversationId]: apply(detail)
      }
    }
  }

  function updateConversationTimeline(
    conversationId: number,
    patch: Partial<CommunicationConversationTimelineState>
  ): void {
    const current = conversationTimelines.value[conversationId] ?? emptyTimelineState()
    conversationTimelines.value = {
      ...conversationTimelines.value,
      [conversationId]: {
        ...current,
        ...patch
      }
    }
  }

  function requestConversationDetail(id: number): Promise<boolean> {
    const active = detailRequests.get(id)
    if (active) return active
    const epoch = sessionEpoch.value
    const requestLifecycleEpoch = lifecycleEpoch
    const request = (async () => {
      const response = await api.communication.conversations.get(id, {
        include_messages: false
      })
      if (epoch !== sessionEpoch.value || requestLifecycleEpoch !== lifecycleEpoch) return false
      storeConversationDetail(response.data)
      void ensurePresenceSubscription(id)
      return true
    })()
    detailRequests.set(id, request)
    void request.finally(() => {
      if (detailRequests.get(id) === request) detailRequests.delete(id)
    }).catch(() => undefined)
    return request
  }

  async function refreshConversationDetail(
    id: number,
    options: { reportError?: boolean } = {}
  ): Promise<boolean> {
    try {
      return await requestConversationDetail(id)
    } catch (caught) {
      if (options.reportError !== false) {
        const message = apiErrorMessage(caught, 'Falha ao abrir a conversa.')
        toast.add({ title: message, color: 'error' })
      }
      return false
    }
  }

  async function prefetchConversation(id: number): Promise<boolean> {
    const detailReady = hasConversationDetail(id)
      || await refreshConversationDetail(id, { reportError: false })
    if (!detailReady) return false
    return loadInitialConversationTimeline(id)
  }

  function initialTimelineRequestKey(id: number, messageId?: number | null): string {
    return `${id}:${messageId ?? 'default'}`
  }

  function latestInitialTimelineRequest(id: number): Promise<boolean> | null {
    const prefix = `${id}:`
    let latest: Promise<boolean> | null = null
    for (const [key, request] of initialTimelineRequests) {
      if (key.startsWith(prefix)) latest = request
    }
    return latest
  }

  function trackInitialTimelineRequest(
    key: string,
    request: Promise<boolean>
  ): Promise<boolean> {
    initialTimelineRequests.set(key, request)
    void request.finally(() => {
      if (initialTimelineRequests.get(key) === request) initialTimelineRequests.delete(key)
    }).catch(() => undefined)
    return request
  }

  function hasInitializedConversationTimeline(id: number): boolean {
    return conversationTimelines.value[id]?.initialized === true
  }

  async function fetchInitialConversationTimeline(id: number, messageId?: number | null): Promise<boolean> {
    const request = beginConversationTimelineRequest(id)
    const summary = conversationDetails.value[id]
      ?? conversations.value.find(item => item.id === id)
    const anchor = messageId ? 'message' : ((summary?.unread_count ?? 0) > 0 ? 'first_unread' : 'latest')
    const previousTimeline = conversationTimelines.value[id]
    timelineReadAcknowledgementFailures.delete(id)
    updateConversationTimeline(id, {
      loading: true,
      error: null,
      loading_older: false,
      loading_newer: false,
      ...(previousTimeline?.initialized
        ? {}
        : {
            initialized: false,
            initial_read_pending: false,
            manual_unread: false,
            divider_message_id: null
          })
    })
    try {
      const response = await api.communication.conversations.messages(id, {
        limit: COMMUNICATION_TIMELINE_PAGE_SIZE,
        anchor,
        ...(messageId ? { message_id: messageId } : {})
      })
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      storeConversationMessages(id, response.data, true)
      conversationTimelines.value = {
        ...conversationTimelines.value,
        [id]: {
          ...emptyTimelineState(),
          meta: response.meta,
          divider_message_id: response.meta.first_unread_message_id,
          initialized: true,
          initial_read_pending: response.meta.unread_count > 0,
          loading: false
        }
      }
      patchConversationTimelineProjection(id, response.meta)
      return true
    } catch (caught) {
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      updateConversationTimeline(id, {
        loading: false,
        initialized: previousTimeline?.initialized ?? false,
        error: apiErrorMessage(caught, 'Falha ao carregar a timeline.')
      })
      return false
    }
  }

  function loadInitialConversationTimeline(id: number, messageId?: number | null): Promise<boolean> {
    const request = currentLifecycleRequest()
    const key = initialTimelineRequestKey(id, messageId)
    const active = initialTimelineRequests.get(key)
    if (active) return active
    if (!messageId && hasInitializedConversationTimeline(id)) return Promise.resolve(true)

    const pending = latestInitialTimelineRequest(id)
    if (pending) {
      if (!messageId) return pending
      return trackInitialTimelineRequest(
        key,
        pending.then(() => isLifecycleRequestCurrent(request)
          ? fetchInitialConversationTimeline(id, messageId)
          : false)
      )
    }

    return trackInitialTimelineRequest(key, fetchInitialConversationTimeline(id, messageId))
  }

  function clearConversationPrefetchQueue(): void {
    conversationPrefetchQueue.splice(0)
    queuedConversationPrefetchIds.clear()
    activeConversationPrefetchIds.clear()
  }

  function drainConversationPrefetchQueue(): void {
    while (
      activeConversationPrefetchIds.size < COMMUNICATION_PREFETCH_CONCURRENCY
      && conversationPrefetchQueue.length > 0
    ) {
      const id = conversationPrefetchQueue.shift()
      if (id === undefined) return
      queuedConversationPrefetchIds.delete(id)
      if (hasInitializedConversationTimeline(id)) continue

      const requestLifecycleEpoch = lifecycleEpoch
      activeConversationPrefetchIds.add(id)
      void prefetchConversation(id).finally(() => {
        if (requestLifecycleEpoch !== lifecycleEpoch) return
        activeConversationPrefetchIds.delete(id)
        drainConversationPrefetchQueue()
      }).catch(() => undefined)
    }
  }

  function queueConversationPrefetch(ids: number[]): void {
    const visibleIds = new Set(ids.filter(id => Number.isSafeInteger(id) && id > 0))
    const retainedIds = conversationPrefetchQueue.filter(id => visibleIds.has(id))
    conversationPrefetchQueue.splice(0, conversationPrefetchQueue.length, ...retainedIds)
    queuedConversationPrefetchIds.clear()
    for (const id of retainedIds) queuedConversationPrefetchIds.add(id)

    for (const id of ids) {
      if (
        !Number.isSafeInteger(id)
        || id <= 0
        || hasInitializedConversationTimeline(id)
        || queuedConversationPrefetchIds.has(id)
        || activeConversationPrefetchIds.has(id)
      ) continue

      queuedConversationPrefetchIds.add(id)
      conversationPrefetchQueue.push(id)
    }
    drainConversationPrefetchQueue()
  }

  async function loadConversationTimelinePage(
    id: number,
    direction: 'older' | 'newer'
  ): Promise<boolean> {
    const timeline = conversationTimelines.value[id]
    if (!timeline?.initialized) return false
    if (direction === 'older' ? timeline.loading_older : timeline.loading_newer) return false
    const cursor = direction === 'older'
      ? timeline.meta.older_cursor
      : timeline.meta.newer_cursor
    if (!cursor) return true
    const request = beginConversationTimelineRequest(id)
    const loadingKey = direction === 'older' ? 'loading_older' : 'loading_newer'
    updateConversationTimeline(id, {
      loading: false,
      loading_older: false,
      loading_newer: false,
      [loadingKey]: true,
      error: null
    })
    try {
      const response = await api.communication.conversations.messages(id, {
        limit: COMMUNICATION_TIMELINE_PAGE_SIZE,
        cursor
      })
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      storeConversationMessages(id, response.data, false)
      const current = conversationTimelines.value[id] ?? timeline
      updateConversationTimeline(id, {
        meta: {
          ...current.meta,
          ...response.meta,
          older_cursor: direction === 'older'
            ? response.meta.older_cursor
            : current.meta.older_cursor,
          newer_cursor: direction === 'newer'
            ? response.meta.newer_cursor
            : current.meta.newer_cursor
        }
      })
      patchConversationTimelineProjection(id, response.meta)
      return true
    } catch (caught) {
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      updateConversationTimeline(id, {
        error: apiErrorMessage(caught, 'Falha ao carregar mais mensagens.')
      })
      return false
    } finally {
      if (isConversationTimelineRequestCurrent(id, request)) {
        updateConversationTimeline(id, {
          [loadingKey]: false
        })
      }
    }
  }

  async function loadOlderConversationMessages(id: number): Promise<boolean> {
    return loadConversationTimelinePage(id, 'older')
  }

  async function loadNewerConversationMessages(id: number): Promise<boolean> {
    return loadConversationTimelinePage(id, 'newer')
  }

  async function refreshConversationTimeline(id: number): Promise<boolean> {
    const timeline = conversationTimelines.value[id]
    if (!timeline?.initialized) return loadInitialConversationTimeline(id)
    if (timeline.meta.newer_cursor) {
      return loadConversationTimelinePage(id, 'newer')
    }
    const request = beginConversationTimelineRequest(id)
    try {
      const response = await api.communication.conversations.messages(id, {
        limit: COMMUNICATION_TIMELINE_PAGE_SIZE,
        anchor: 'latest'
      })
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      storeConversationMessages(id, response.data, false)
      const current = conversationTimelines.value[id] ?? timeline
      updateConversationTimeline(id, {
        meta: {
          ...response.meta,
          older_cursor: current.meta.older_cursor ?? response.meta.older_cursor,
          newer_cursor: response.meta.newer_cursor
        },
        error: null
      })
      patchConversationTimelineProjection(id, response.meta)
      return true
    } catch (caught) {
      if (!isConversationTimelineRequestCurrent(id, request)) return false
      updateConversationTimeline(id, {
        error: apiErrorMessage(caught, 'Falha ao atualizar a timeline.')
      })
      return false
    }
  }

  const timelineReadAcknowledgements = new Set<number>()
  const timelineReadAcknowledgementFailures = new Map<number, number>()

  async function acknowledgeConversationTimeline(input: {
    conversationId: number
    rendered: boolean
    visible: boolean
    atEnd: boolean
  }): Promise<boolean> {
    const timeline = conversationTimelines.value[input.conversationId]
    if (
      !timeline
      || timelineReadAcknowledgements.has(input.conversationId)
    ) return false
    const snapshotMessageId = timeline.meta.snapshot_through_message_id
    if (!canRetryCommunicationReadAcknowledgement(
      timelineReadAcknowledgementFailures.get(input.conversationId),
      snapshotMessageId
    )) return false
    if (!shouldMarkCommunicationTimelineRead({
      rendered: input.rendered,
      visible: input.visible,
      atEnd: input.atEnd,
      initialReadPending: timeline.initial_read_pending,
      manualUnread: timeline.manual_unread,
      unreadCount: timeline.meta.unread_count,
      snapshotThroughMessageId: timeline.meta.snapshot_through_message_id
    })) {
      return false
    }

    timelineReadAcknowledgements.add(input.conversationId)
    try {
      const acknowledged = await markConversationRead(
        input.conversationId,
        timeline.meta.snapshot_through_message_id
      )
      if (acknowledged) timelineReadAcknowledgementFailures.delete(input.conversationId)
      else if (snapshotMessageId !== null) {
        timelineReadAcknowledgementFailures.set(input.conversationId, snapshotMessageId)
      }
      return acknowledged
    } finally {
      timelineReadAcknowledgements.delete(input.conversationId)
    }
  }

  async function selectConversation(id: number | null): Promise<boolean> {
    const epoch = ++selectionEpoch
    if (id === null) {
      openingConversationId.value = null
      selectedConversationId.value = null
      if (unreadOnly.value) {
        void loadConversations({ silent: true }).catch(() => undefined)
      }
      return true
    }
    openingConversationId.value = id
    const cached = hasConversationDetail(id)
    const detailReady = cached
      || await refreshConversationDetail(id)
    if (epoch !== selectionEpoch) return false
    if (!detailReady) {
      openingConversationId.value = null
      return false
    }
    if (cached) void refreshConversationDetail(id, { reportError: false })
    const timelineWasReady = hasInitializedConversationTimeline(id)
    const timelineReady = await loadInitialConversationTimeline(id)
    if (epoch !== selectionEpoch) return false
    if (!timelineReady) {
      openingConversationId.value = null
      const timelineError = conversationTimelines.value[id]?.error
      if (timelineError) toast.add({ title: timelineError, color: 'error' })
      return false
    }
    selectedConversationId.value = id
    openingConversationId.value = null
    if (timelineWasReady) void refreshConversationTimeline(id)
    if (unreadOnly.value) {
      void loadConversations({ silent: true }).catch(() => undefined)
    }
    return true
  }

  async function selectConversationAtMessage(id: number, messageId: number): Promise<boolean> {
    const epoch = ++selectionEpoch
    openingConversationId.value = id
    const detailReady = hasConversationDetail(id) || await refreshConversationDetail(id)
    if (epoch !== selectionEpoch || !detailReady) {
      openingConversationId.value = null
      return false
    }
    const timelineReady = await loadInitialConversationTimeline(id, messageId)
    if (epoch !== selectionEpoch || !timelineReady) {
      openingConversationId.value = null
      return false
    }
    selectedConversationId.value = id
    openingConversationId.value = null
    return true
  }

  function clearSignal(kind: 'chat' | 'contact', conversationId: number): void {
    const key = `${kind}:${conversationId}`
    const timer = signalTimers.get(key)
    if (timer) clearTimeout(timer)
    signalTimers.delete(key)
    if (kind === 'chat') {
      const { [conversationId]: _removed, ...next } = chatPresenceByConversation.value
      chatPresenceByConversation.value = next
      return
    }
    const { [conversationId]: _removed, ...next } = contactPresenceByConversation.value
    contactPresenceByConversation.value = next
  }

  function storeSignal(signal: CommunicationChatPresenceSignal | CommunicationContactPresenceSignal): void {
    const kind = signal.kind
    const conversationId = signal.conversation_id
    clearSignal(kind, conversationId)
    if (kind === 'chat') {
      chatPresenceByConversation.value = {
        ...chatPresenceByConversation.value,
        [conversationId]: signal
      }
    } else {
      contactPresenceByConversation.value = {
        ...contactPresenceByConversation.value,
        [conversationId]: signal
      }
    }
    if (!import.meta.client) return
    const delay = Math.max(0, signal.expires_at - Date.now())
    signalTimers.set(`${kind}:${conversationId}`, setTimeout(() => {
      clearSignal(kind, conversationId)
    }, delay))
  }

  function applyEphemeralSignals(incoming: CommunicationEvent[]): void {
    for (const event of incoming) {
      if (!isCommunicationEphemeralEvent(event) || event.conversation_id == null) continue
      if (event.type === 'CHAT_PRESENCE_CHANGED' && event.payload.presence === 'PAUSED') {
        clearSignal('chat', event.conversation_id)
        continue
      }
      const signal = communicationSignalFromEvent(event)
      if (signal) storeSignal(signal)
    }
  }

  function applyReadStateEvent(event: CommunicationEvent): void {
    if (event.type !== 'conversation.read_state.updated') return
    const conversationId = event.conversation_id
    const version = event.payload.version
    const unreadCount = event.payload.unread_count
    if (
      conversationId == null
      || typeof version !== 'number'
      || !Number.isInteger(version)
      || typeof unreadCount !== 'number'
      || !Number.isInteger(unreadCount)
    ) {
      return
    }
    const incomingFirstUnread = typeof event.payload.first_unread_message_id === 'number'
      ? event.payload.first_unread_message_id
      : undefined
    const known = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    const knownVersion = Math.max(
      known?.read_state?.version ?? 0,
      conversationTimelines.value[conversationId]?.meta.read_state_version ?? 0
    )
    if (!isCommunicationReadStateVersionNewer(version, knownVersion)) return
    const apply = (item: CommunicationConversation): CommunicationConversation => ({
      ...item,
      unread_count: unreadCount,
      first_unread_message_id: incomingFirstUnread
        ?? (unreadCount === 0 ? null : item.first_unread_message_id ?? null),
      read_state: {
        version,
        last_read_through_message_id: mergeCommunicationReadThroughMessageId(
          item.read_state?.last_read_through_message_id,
          event.payload.last_read_through_message_id
        )
      }
    })
    conversations.value = conversations.value.map(item =>
      item.id === conversationId ? apply(item) : item
    )
    const detail = conversationDetails.value[conversationId]
    if (detail) {
      conversationDetails.value = {
        ...conversationDetails.value,
        [conversationId]: apply(detail)
      }
    }
    const timeline = conversationTimelines.value[conversationId]
    if (timeline) {
      updateConversationTimeline(conversationId, {
        meta: {
          ...timeline.meta,
          unread_count: unreadCount,
          first_unread_message_id: incomingFirstUnread
            ?? (unreadCount === 0 ? null : timeline.meta.first_unread_message_id),
          read_state_version: version
        }
      })
    }
  }

  async function hydrateFromEvents(
    incoming: CommunicationEvent[],
    request: CommunicationSynchronizationRequest
  ): Promise<void> {
    if (!isSynchronizationRequestCurrent(request)) return
    applyEphemeralSignals(incoming)
    const durable = incoming.filter(event => !isCommunicationEphemeralEvent(event))
    if (!durable.length) return
    for (const event of durable) applyReadStateEvent(event)
    const selectedId = selectedConversationId.value
    const selected = selectedConversation.value
    const selectedInboxId = selected?.inbox_id ?? null
    const touchesSelected = selectedId !== null && durable.some(event =>
      event.conversation_id === selectedId
      || (event.conversation_id == null
        && selectedInboxId !== null
        && event.inbox_id === selectedInboxId)
    )
    // Recarrega o detalhe antes da lista para o merge preservar a seleção
    // mesmo quando o filtro (ex.: OPEN) excluiria a conversa.
    if (touchesSelected && selectedId !== null) {
      await refreshConversationDetail(selectedId, { reportError: false })
      if (!isSynchronizationRequestCurrent(request)) return
      const touchesTimeline = durable.some(event =>
        event.conversation_id === selectedId && event.message_id != null
      )
      if (touchesTimeline) {
        await refreshConversationTimeline(selectedId)
        if (!isSynchronizationRequestCurrent(request)) return
      }
    }
    await loadConversations({ silent: true }).catch(() => undefined)
    if (!isSynchronizationRequestCurrent(request)) return
    if (selectedId !== null && !conversations.value.some(item => item.id === selectedId)) {
      await refreshConversationDetail(selectedId, { reportError: false })
      if (!isSynchronizationRequestCurrent(request)) return
    }
    await loadInboxes().catch(() => undefined)
  }

  async function synchronize(): Promise<void> {
    if (!canView.value) return
    if (syncing.value) {
      synchronizeAgain = true
      return
    }
    const request: CommunicationSynchronizationRequest = {
      generation: ++synchronizeGeneration,
      lifecycleEpoch,
      sessionEpoch: sessionEpoch.value
    }
    syncing.value = true
    syncError.value = null
    const received: CommunicationEvent[] = []
    try {
      do {
        if (!isSynchronizationRequestCurrent(request)) return
        synchronizeAgain = false
        let hasMore = true
        let after = cursor.value
        while (hasMore) {
          const response = await api.communication.events.sync(after)
          if (!isSynchronizationRequestCurrent(request)) return
          received.push(...response.data)
          events.value = mergeCommunicationEvents(events.value, response.data).slice(-500)
          const next = Math.max(
            response.meta.next_cursor,
            latestCommunicationCursor(response.data, after)
          )
          if (next <= after) break
          after = next
          cursor.value = Math.max(cursor.value, next)
          hasMore = response.meta.has_more
        }
        await hydrateFromEvents(received.splice(0), request)
        if (!isSynchronizationRequestCurrent(request)) return
      } while (synchronizeAgain)
    } catch (caught) {
      if (isSynchronizationRequestCurrent(request)) {
        syncError.value = apiErrorMessage(caught, 'Sincronização temporariamente indisponível.')
      }
    } finally {
      if (isSynchronizationRequestCurrent(request)) syncing.value = false
    }
  }

  function onRealtimeEvent(event: CommunicationRealtimeEvent): void {
    const nextCursor = normalizeCommunicationCursor(event.cursor)
    if (nextCursor === null || nextCursor <= cursor.value) return
    const normalized: CommunicationRealtimeEvent = { ...event, cursor: nextCursor }
    applyReadStateEvent(normalized)
    events.value = mergeCommunicationEvents(events.value, [normalized]).slice(-500)
    // Não avança o cursor aqui: o sync usa `after` exclusivo e precisa buscar o evento.
    void synchronize()
  }

  function reconcileSubscriptions(options?: { force?: boolean }): void {
    const force = options?.force === true
    const visibleIds = new Set(inboxes.value.map(inbox => inbox.id))
    for (const [inboxId, unsubscribe] of subscriptions) {
      if (!force && visibleIds.has(inboxId)) continue
      unsubscribe()
      subscriptions.delete(inboxId)
    }
    if (force && tenantSubscription !== null) {
      tenantSubscription()
      tenantSubscription = null
    }
    if (!realtime.enabled) return
    for (const inboxId of visibleIds) {
      if (subscriptions.has(inboxId)) continue
      subscriptions.set(inboxId, realtime.subscribeInbox(inboxId, onRealtimeEvent))
    }
    const tenantId = me.value?.current_tenant?.id
    if (canManage.value && tenantId && tenantSubscription === null) {
      tenantSubscription = realtime.subscribeTenant(tenantId, onRealtimeEvent)
    }
  }

  async function initialize(): Promise<void> {
    const intent = consumeSurfaceNavigationIntent<Partial<CommunicationWorkspaceNavigationState>>(
      COMMUNICATION_SURFACES.workspace
    )
    if (intent) navigationState.patch(intent)
    if (!canView.value || loading.value) return
    const request = currentLifecycleRequest()
    loading.value = true
    error.value = null
    try {
      await Promise.all([loadInboxes(), loadCatalog(), loadListPreferences()])
      if (!isLifecycleRequestCurrent(request)) return
      // Membros para filtro/bulk de responsável (soft-fail se sem permissão).
      try {
        const members = await api.tenant.members.list()
        if (!isLifecycleRequestCurrent(request)) return
        tenantMembers.value = members.data.filter(member => member.is_active)
      } catch {
        if (!isLifecycleRequestCurrent(request)) return
        // Filtro de responsável fica sem nomes se a listagem for negada.
      }
      lastSelectionQueryKey = communicationSelectionQueryKey(selectionQueryContext())
      await loadConversations({ silent: true })
      if (!isLifecycleRequestCurrent(request)) return
      initialized.value = true
      void synchronize()
    } catch (caught) {
      if (!isLifecycleRequestCurrent(request)) return
      error.value = apiErrorMessage(caught, 'Não foi possível abrir o atendimento.')
      toast.add({ title: error.value, color: 'error' })
    } finally {
      if (isLifecycleRequestCurrent(request)) loading.value = false
    }
  }

  async function sendMessage(input: CommunicationComposerPayload): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation || !canReply.value || sending.value) return false
    sending.value = true
    try {
      const receiptMessageId = input.internalNote
        ? undefined
        : [...(conversation.messages ?? [])]
            .reverse()
            .find(message => message.direction === 'INBOUND' && !message.metadata?.revoked)
            ?.id
      const response = await api.communication.conversations.send(conversation.id, {
        body: input.body,
        internal_note: input.internalNote,
        reply_to_message_id: input.replyToMessageId,
        idempotency_key: input.internalNote
          ? undefined
          : `web-${Date.now()}-${crypto.randomUUID()}`,
        file: input.file,
        kind: input.internalNote ? undefined : input.kind,
        ptt: input.internalNote ? undefined : input.ptt,
        receipt_message_id: receiptMessageId
      })
      storeConversationMessages(conversation.id, [response.data], false)
      await Promise.all([
        refreshConversationDetail(conversation.id, { reportError: false }),
        loadConversations({ silent: true })
      ])
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao enviar mensagem.'), color: 'error' })
      return false
    } finally {
      sending.value = false
    }
  }

  async function queueMessageAction(
    messageId: number,
    action: () => Promise<unknown>,
    successTitle: string
  ): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation || !canReply.value || !outboundOperational.value || messageActionLoadingId.value !== null) {
      return false
    }
    messageActionLoadingId.value = messageId
    try {
      await action()
      toast.add({
        title: successTitle,
        description: 'A atualização aparecerá quando o WhatsApp confirmar a ação.',
        color: 'success'
      })
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao enfileirar a ação.'), color: 'error' })
      return false
    } finally {
      messageActionLoadingId.value = null
    }
  }

  async function editMessage(messageId: number, text: string): Promise<boolean> {
    const conversation = selectedConversation.value
    const normalized = text.trim()
    if (!conversation || !normalized) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.editMessage(conversation.id, messageId, normalized),
      'Edição enfileirada'
    )
  }

  async function revokeMessage(messageId: number): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.revokeMessage(conversation.id, messageId),
      'Revogação enfileirada'
    )
  }

  async function reactMessage(messageId: number, emoji: string | null): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.reactMessage(conversation.id, messageId, emoji),
      emoji ? 'Reação enfileirada' : 'Remoção da reação enfileirada'
    )
  }

  async function votePoll(messageId: number, optionNames: string[]): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation || !optionNames.length) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.votePoll(conversation.id, messageId, optionNames),
      'Voto enfileirado'
    )
  }

  async function sendReceipt(messageId: number, receipt: 'READ' | 'PLAYED'): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.receipt(conversation.id, messageId, receipt),
      receipt === 'PLAYED' ? 'Confirmação de reprodução enfileirada' : 'Confirmação de leitura enfileirada'
    )
  }

  async function recoverMessage(
    messageId: number,
    operation: 'UNAVAILABLE' | 'MEDIA_RETRY'
  ): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation) return false
    return queueMessageAction(
      messageId,
      () => api.communication.conversations.recoverMessage(conversation.id, messageId, operation),
      operation === 'MEDIA_RETRY' ? 'Recuperação da mídia enfileirada' : 'Solicitação da mensagem enfileirada'
    )
  }

  async function ensurePresenceSubscription(conversationId: number): Promise<void> {
    if (!canReply.value || !outboundOperational.value || presenceSubscriptions.has(conversationId)) return
    presenceSubscriptions.add(conversationId)
    try {
      await api.communication.conversations.subscribePresence(conversationId)
    } catch {
      presenceSubscriptions.delete(conversationId)
    }
  }

  async function setChatPresence(presence: CommunicationChatPresence): Promise<void> {
    const conversation = selectedConversation.value
    if (!conversation || !canReply.value || !outboundOperational.value) return
    const now = Date.now()
    const throttleWindow = presence === 'PAUSED' ? 1_000 : 10_000
    if (lastPresenceState === presence && now - lastPresenceSentAt < throttleWindow) return
    lastPresenceState = presence
    lastPresenceSentAt = now
    try {
      await api.communication.conversations.setPresence(
        conversation.id,
        presence,
        presence === 'RECORDING' ? 'AUDIO' : 'TEXT'
      )
    } catch {
      lastPresenceState = null
    }
  }

  async function setDisappearingTimer(seconds: 0 | 86400 | 604800 | 7776000): Promise<boolean> {
    const conversation = selectedConversation.value
    if (!conversation || !canReply.value || !outboundOperational.value) return false
    try {
      await api.communication.conversations.setDisappearing(conversation.id, seconds)
      toast.add({
        title: seconds === 0 ? 'Mensagens temporárias desativadas' : 'Temporizador enfileirado',
        color: 'success'
      })
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao alterar mensagens temporárias.'), color: 'error' })
      return false
    }
  }

  async function updateConversation(
    patch: Partial<Pick<CommunicationConversation,
      'status' | 'assignee_membership_id' | 'work_department_id' | 'priority' | 'snoozed_until'>>,
    target: CommunicationConversation | null = selectedConversation.value
  ): Promise<boolean> {
    const conversation = target
    if (!conversation || !canReply.value) return false
    try {
      const response = await api.communication.conversations.update(conversation.id, {
        lock_version: conversation.lock_version,
        ...patch
      })
      storeConversationDetail(response.data)
      return true
    } catch (caught) {
      if ((caught as { data?: { code?: string } })?.data?.code === 'version_conflict') {
        await refreshConversationDetail(conversation.id, { reportError: false })
      }
      toast.add({ title: apiErrorMessage(caught, 'Falha ao atualizar conversa.'), color: 'error' })
      return false
    }
  }

  async function setConversationLabel(
    conversation: CommunicationConversation,
    labelId: number,
    assigned: boolean
  ): Promise<boolean> {
    if (!canReply.value) return false
    try {
      if (assigned) {
        await api.communication.conversations.addLabel(conversation.id, labelId)
      } else {
        await api.communication.conversations.removeLabel(conversation.id, labelId)
      }
      await refreshConversationDetail(conversation.id, { reportError: false })
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao atualizar marcador.'), color: 'error' })
      return false
    }
  }

  async function toggleLabel(label: CommunicationLabel): Promise<void> {
    const conversation = selectedConversation.value
    if (!conversation) return
    const assigned = conversation.labels?.some(item => item.id === label.id) ?? false
    await setConversationLabel(conversation, label.id, !assigned)
  }

  async function loadAdministration(): Promise<void> {
    if (!canManage.value || adminLoading.value) return
    adminLoading.value = true
    try {
      const [automation, members, departmentResponse] = await Promise.all([
        api.communication.automation.list(),
        api.tenant.members.list(),
        api.work.departments.list({ per_page: 100, is_active: true })
      ])
      policies.value = automation.data
      automationMeta.value = automation.meta
      tenantMembers.value = members.data.filter(member => member.is_active)
      departments.value = departmentResponse.data
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao carregar administração.'), color: 'error' })
    } finally {
      adminLoading.value = false
    }
  }

  async function createInbox(body: {
    name: string
    is_enabled?: boolean
    is_default?: boolean
    work_department_id?: number | null
  }): Promise<CommunicationInbox | null> {
    try {
      const response = await api.communication.inboxes.create(body)
      await loadInboxes()
      return response.data
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao criar inbox.'), color: 'error' })
      return null
    }
  }

  async function updateInbox(
    inbox: CommunicationInbox,
    patch: Partial<Pick<CommunicationInbox,
      'name' | 'is_enabled' | 'is_default' | 'work_department_id'>>
  ): Promise<boolean> {
    try {
      await api.communication.inboxes.update(inbox.id, {
        ...patch,
        lock_version: inbox.lock_version
      })
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao atualizar inbox.'), color: 'error' })
      return false
    }
  }

  async function deleteInbox(inboxId: number): Promise<boolean> {
    try {
      await api.communication.inboxes.remove(inboxId)
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao excluir a sessão.'), color: 'error' })
      return false
    }
  }

  async function replaceInboxMembers(inboxId: number, membershipIds: number[]): Promise<boolean> {
    try {
      await api.communication.inboxes.replaceMembers(inboxId, membershipIds)
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao atualizar membros.'), color: 'error' })
      return false
    }
  }

  async function connectInbox(inboxId: number): Promise<CommunicationPairingState | null> {
    try {
      const response = await api.communication.inboxes.connect(inboxId)
      await loadInboxes()
      return response.data
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao conectar a sessão.'), color: 'error' })
      return null
    }
  }

  async function disconnectInbox(inboxId: number): Promise<boolean> {
    try {
      await api.communication.inboxes.disconnect(inboxId)
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao desconectar a sessão.'), color: 'error' })
      return false
    }
  }

  async function getSessionStatus(inboxId: number): Promise<CommunicationSessionStatus | null> {
    try {
      return (await api.communication.inboxes.sessionStatus(inboxId)).data
    } catch {
      return null
    }
  }

  async function getPairing(inboxId: number): Promise<CommunicationPairingState | null> {
    try {
      return (await api.communication.inboxes.sessionStatus(inboxId)).data.pairing ?? null
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao consultar pareamento.'), color: 'error' })
      return null
    }
  }

  async function logoutInbox(inboxId: number): Promise<boolean> {
    try {
      await api.communication.inboxes.logout(inboxId)
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao fazer logout da sessão.'), color: 'error' })
      return false
    }
  }

  async function updateTenantEnabled(enabled: boolean): Promise<boolean> {
    try {
      await api.communication.inboxes.updateTenantSettings(enabled)
      await loadInboxes()
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao alterar o switch do escritório.'), color: 'error' })
      return false
    }
  }

  async function savePolicy(body: CommunicationPolicyBody): Promise<boolean> {
    try {
      const response = await api.communication.automation.upsert(body)
      policies.value = [
        ...policies.value.filter(item => item.id !== response.data.id),
        response.data
      ].sort((a, b) => `${a.module_key}:${a.submodule_key}`.localeCompare(`${b.module_key}:${b.submodule_key}`))
      return true
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar política.'), color: 'error' })
      return false
    }
  }

  async function loadRecipients(
    clientId: number,
    moduleKey: string,
    submoduleKey: string
  ): Promise<CommunicationRecipientConfiguration | null> {
    try {
      return (await api.communication.automation.recipients(clientId, moduleKey, submoduleKey)).data
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao carregar destinatários.'), color: 'error' })
      return null
    }
  }

  async function saveRecipients(
    configuration: CommunicationRecipientConfiguration,
    moduleKey: string,
    submoduleKey: string,
    recipientMode: CommunicationRecipientMode,
    identityIds: number[]
  ): Promise<CommunicationRecipientConfiguration | null> {
    try {
      return (await api.communication.automation.updateRecipients(configuration.client_id, {
        module_key: moduleKey,
        submodule_key: submoduleKey,
        recipient_mode: recipientMode,
        identity_ids: identityIds,
        lock_version: configuration.lock_version
      })).data
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar destinatários.'), color: 'error' })
      return null
    }
  }

  const reloadForFilters = useDebounceFn(() => {
    if (initialized.value) void reloadConversations()
  }, 250)

  let pollTimer: ReturnType<typeof setInterval> | null = null

  function stopCursorPoll(): void {
    if (pollTimer === null) return
    clearInterval(pollTimer)
    pollTimer = null
  }

  function ensureCursorPoll(): void {
    if (!initialized.value || !canView.value) {
      stopCursorPoll()
      return
    }
    if (realtime.channelsReady.value) {
      stopCursorPoll()
      return
    }
    if (pollTimer !== null) return
    pollTimer = setInterval(() => {
      if (!initialized.value || !canView.value || realtime.channelsReady.value) {
        stopCursorPoll()
        return
      }
      void synchronize()
    }, 5_000)
  }

  watch(
    [
      search,
      inboxFilter,
      statusFilter,
      assigneeFilter,
      departmentFilter,
      unassignedOnly,
      unreadOnly,
      labelIdsFilter,
      contactIdFilter,
      sortBy
    ],
    () => {
      if (!initialized.value) return
      const nextKey = communicationSelectionQueryKey(selectionQueryContext())
      if (nextKey !== lastSelectionQueryKey) {
        lastSelectionQueryKey = nextKey
        clearOperationalSelection()
      }
      reloadForFilters()
    },
    { deep: true }
  )
  watch([statusFilter, sortBy], () => {
    if (!initialized.value || !preferencesLoaded.value || suppressPreferenceSave) return
    void persistListPreferences()
  })
  watch(realtime.state, (next, previous) => {
    // Transporte voltou: re-assina canais (subscriptions Map podia estar stale).
    if (previous === 'unavailable' && (next === 'connecting' || next === 'connected')) {
      reconcileSubscriptions({ force: true })
    }
    if (next === 'connected' && previous !== 'connected') {
      void synchronize()
    }
    ensureCursorPoll()
  })
  watch(() => realtime.channelsReady.value, () => {
    ensureCursorPoll()
  })
  watch(canView, (allowed) => {
    if (allowed && !initialized.value && !loading.value) void initialize()
    ensureCursorPoll()
  }, { immediate: true })
  watch(initialized, () => {
    ensureCursorPoll()
  })
  watch(sessionEpoch, () => {
    navigationState.reset()
    dispose()
    inboxes.value = []
    conversations.value = []
    resetConversationPagination()
    conversationDetails.value = {}
    conversationTimelines.value = {}
    selectedConversationId.value = null
    openingConversationId.value = null
    selectedConversationIds.value = new Set()
    bulkIdempotency = null
    pendingBulkOperation.value = null
    preferencesLoaded.value = false
    preferencesUnavailable.value = false
    conversationTimelineGenerations.clear()
    labels.value = []
    cannedResponses.value = []
    events.value = []
    chatPresenceByConversation.value = {}
    contactPresenceByConversation.value = {}
    cursor.value = 0
    policies.value = []
    featureMeta.value = { ...EMPTY_FEATURE_META }
    lastSelectionQueryKey = ''
    initialized.value = false
    if (canView.value) void initialize()
  })

  function dispose(): void {
    lifecycleEpoch++
    selectionEpoch++
    synchronizeGeneration++
    synchronizeAgain = false
    loading.value = false
    syncing.value = false
    detailRequests.clear()
    initialTimelineRequests.clear()
    clearConversationPrefetchQueue()
    conversationQueryGeneration++
    conversationQueryController?.abort()
    conversationQueryController = null
    stopCursorPoll()
    stopBulkPoll()
    for (const unsubscribe of subscriptions.values()) unsubscribe()
    subscriptions.clear()
    for (const timer of signalTimers.values()) clearTimeout(timer)
    signalTimers.clear()
    presenceSubscriptions.clear()
    timelineReadAcknowledgements.clear()
    timelineReadAcknowledgementFailures.clear()
    conversationTimelineGenerations.clear()
    chatPresenceByConversation.value = {}
    contactPresenceByConversation.value = {}
    tenantSubscription?.()
    tenantSubscription = null
    initialized.value = false
  }

  return {
    adminLoading: readonly(adminLoading),
    acknowledgeConversationTimeline,
    allLoadedSelected,
    assigneeFilter,
    automationMeta,
    bulkSubmitting: readonly(bulkSubmitting),
    cannedResponses,
    canManage,
    canManageContacts,
    canReply,
    canView,
    clearOperationalSelection,
    communicationBlockReason,
    communicationOperational,
    connectInbox,
    conversations,
    conversationsEmpty,
    conversationsHasMore,
    conversationsInitialLoading,
    conversationsLastPage: readonly(conversationsLastPage),
    conversationsLoaded: readonly(conversationsLoaded),
    conversationsLoadingMore: readonly(conversationsLoadingMore),
    conversationsLoadMoreError: readonly(conversationsLoadMoreError),
    conversationsLoading: readonly(conversationsLoading),
    conversationsPage: readonly(conversationsPage),
    conversationsRefreshing: readonly(conversationsRefreshing),
    conversationsTotal: readonly(conversationsTotal),
    createInbox,
    deleteInbox,
    cursor: readonly(cursor),
    departments,
    departmentFilter,
    disconnectInbox,
    dispose,
    editMessage,
    error: readonly(error),
    events,
    featureMeta,
    getPairing,
    getSessionStatus,
    inboxes,
    inboxFilter,
    initialize,
    initialized: readonly(initialized),
    isConversationSelected,
    labelIdsFilter,
    contactIdFilter,
    labels,
    loadAdministration,
    loadCatalog,
    loadMoreConversations,
    loadNewerConversationMessages,
    loadOlderConversationMessages,
    loadInboxes,
    loadRecipients,
    loading: readonly(loading),
    messageActionLoadingId: readonly(messageActionLoadingId),
    tenantMembers,
    openingConversationId: readonly(openingConversationId),
    outboundOperational,
    pendingBulkOperation: readonly(pendingBulkOperation),
    policies,
    prefetchConversation,
    queueConversationPrefetch,
    preferencesLoaded: readonly(preferencesLoaded),
    realtimeState: realtime.state,
    reloadConversations,
    reactMessage,
    recoverMessage,
    replaceInboxMembers,
    logoutInbox,
    savePolicy,
    saveRecipients,
    search,
    selectAllLoadedConversations,
    selectedConversation,
    selectedConversationCount,
    selectedConversationId: readonly(selectedConversationId),
    selectedConversationIds: readonly(selectedConversationIds),
    selectedInbox,
    selectedSignals,
    selectedTimeline,
    selectionIndeterminate,
    selectConversation,
    selectConversationAtMessage,
    sendReceipt,
    sendMessage,
    sending: readonly(sending),
    setConversationLabel,
    setConversationSelected,
    sortBy,
    statusFilter,
    setChatPresence,
    setDisappearingTimer,
    submitBulkOperation,
    syncError: readonly(syncError),
    syncing: readonly(syncing),
    synchronize,
    toggleLabel,
    toggleSelectAllLoaded,
    unassignedOnly,
    unreadOnly,
    markConversationRead,
    markConversationUnread,
    updateConversation,
    updateInbox,
    updateTenantEnabled,
    revokeMessage,
    votePoll
  }
}

export const useCommunicationWorkspace = createSharedComposable(_useCommunicationWorkspace)

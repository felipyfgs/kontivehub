import { createSharedComposable, useDebounceFn } from '@vueuse/core'
import type { TenantMember } from '~/types/api'
import type {
  CommunicationAutomationMeta,
  CommunicationAutomationPolicy,
  CommunicationCannedResponse,
  CommunicationChatPresence,
  CommunicationChatPresenceSignal,
  CommunicationContactPresenceSignal,
  CommunicationComposerPayload,
  CommunicationConversation,
  CommunicationConversationListMeta,
  CommunicationConversationSignals,
  CommunicationConversationStatus,
  CommunicationEvent,
  CommunicationFeatureMeta,
  CommunicationInbox,
  CommunicationLabel,
  CommunicationPairingState,
  CommunicationRecipientConfiguration,
  CommunicationRecipientMode,
  CommunicationRealtimeEvent,
  CommunicationSessionStatus
} from '~/types/communication'
import type { WorkDepartment } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
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
  canManageCommunication as userCanManageCommunication,
  canReplyCommunication as userCanReplyCommunication,
  canViewCommunication as userCanViewCommunication
} from '~/utils/permissions'
import type {
  CommunicationConversationFilters,
  CommunicationPolicyBody
} from './api/createCommunicationApi'

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

export function mergeCommunicationConversationPage(
  current: CommunicationConversation[],
  incoming: CommunicationConversation[],
  append: boolean
): CommunicationConversation[] {
  return mergeCommunicationConversations(append ? current : [], incoming)
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
  const realtime = useNuxtApp().$communicationRealtime

  const inboxes = ref<CommunicationInbox[]>([])
  const featureMeta = ref<CommunicationFeatureMeta>({ ...EMPTY_FEATURE_META })
  const conversations = ref<CommunicationConversation[]>([])
  const conversationDetails = ref<Record<number, CommunicationConversation>>({})
  const selectedConversationId = ref<number | null>(null)
  const openingConversationId = ref<number | null>(null)
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

  const search = ref('')
  const inboxFilter = ref<number | null>(null)
  const statusFilter = ref<CommunicationConversationStatus | null>('OPEN')
  const assigneeFilter = ref<number | null>(null)
  const departmentFilter = ref<number | null>(null)
  const unassignedOnly = ref(false)
  const unreadOnly = ref(false)
  const conversationsHasMore = computed(() =>
    conversationsPage.value > 0 && conversationsPage.value < conversationsLastPage.value)
  const conversationsInitialLoading = computed(() =>
    conversationsLoading.value && !conversationsLoaded.value && conversations.value.length === 0)
  const conversationsEmpty = computed(() =>
    conversationsLoaded.value && !conversationsLoading.value && conversations.value.length === 0)

  const canView = computed(() => userCanViewCommunication(me.value))
  const canReply = computed(() => userCanReplyCommunication(me.value))
  const canManage = computed(() => userCanManageCommunication(me.value))
  const selectedConversation = computed(() => {
    const id = selectedConversationId.value
    if (id === null) return null
    return conversationDetails.value[id]
      ?? conversations.value.find(item => item.id === id)
      ?? null
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
  let tenantSubscription: (() => void) | null = null
  let synchronizeAgain = false
  let selectionEpoch = 0
  let conversationQueryGeneration = 0
  let conversationQueryController: AbortController | null = null
  let lastPresenceState: CommunicationChatPresence | null = null
  let lastPresenceSentAt = 0

  function listFilters(page = 1): CommunicationConversationFilters {
    return {
      q: search.value || undefined,
      inbox_id: inboxFilter.value || undefined,
      status: statusFilter.value || undefined,
      assignee_membership_id: assigneeFilter.value || undefined,
      work_department_id: departmentFilter.value || undefined,
      unassigned: unassignedOnly.value || undefined,
      unread: unreadOnly.value || undefined,
      page,
      per_page: COMMUNICATION_CONVERSATION_PAGE_SIZE
    }
  }

  function resolveThroughMessageId(conversationId: number, preferred?: number | null): number | null {
    if (preferred != null && preferred > 0) return preferred
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
  ): Promise<void> {
    const throughMessageId = resolveThroughMessageId(conversationId, upToMessageId)
    if (throughMessageId == null) return
    try {
      const response = await api.communication.conversations.markRead(conversationId, {
        through_message_id: throughMessageId
      })
      patchConversationReadState(response.data)
    } catch {
      // Fail closed: keep last known unread without inventing local counts.
    }
  }

  async function markConversationUnread(conversationId: number): Promise<void> {
    const detail = conversationDetails.value[conversationId]
      ?? conversations.value.find(item => item.id === conversationId)
    const expectedVersion = detail?.read_state?.version ?? 0
    try {
      const response = await api.communication.conversations.markUnread(conversationId, {
        expected_version: expectedVersion
      })
      patchConversationReadState(response.data)
    } catch {
      // Fail closed: keep last known unread without inventing local counts.
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
    const response = await api.communication.inboxes.list()
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
      conversationDetails.value = nextDetails
      conversations.value = mergeCommunicationConversationPage(
        conversations.value,
        visible,
        append
      )
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
    const [labelsResponse, cannedResponse] = await Promise.all([
      api.communication.catalog.labels(),
      api.communication.catalog.cannedResponses()
    ])
    labels.value = labelsResponse.data
    cannedResponses.value = cannedResponse.data
  }

  function hasConversationDetail(id: number): boolean {
    return Array.isArray(conversationDetails.value[id]?.messages)
  }

  function storeConversationDetail(incoming: CommunicationConversation): void {
    const cached = conversationDetails.value[incoming.id]
    const detail = mergeCommunicationConversations(cached ? [cached] : [], [incoming])[0] ?? incoming
    conversationDetails.value = {
      ...conversationDetails.value,
      [incoming.id]: detail
    }
    if (selectedConversationId.value === incoming.id
      || conversations.value.some(item => item.id === incoming.id)) {
      conversations.value = mergeCommunicationConversations(conversations.value, [detail])
    }
  }

  function requestConversationDetail(id: number): Promise<boolean> {
    const active = detailRequests.get(id)
    if (active) return active
    const epoch = sessionEpoch.value
    const request = (async () => {
      const response = await api.communication.conversations.get(id)
      if (epoch !== sessionEpoch.value) return false
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
    if (hasConversationDetail(id)) return true
    return refreshConversationDetail(id, { reportError: false })
  }

  async function selectConversation(id: number | null): Promise<boolean> {
    const epoch = ++selectionEpoch
    if (id === null) {
      openingConversationId.value = null
      selectedConversationId.value = null
      return true
    }
    if (hasConversationDetail(id)) {
      openingConversationId.value = null
      selectedConversationId.value = id
      void refreshConversationDetail(id, { reportError: false })
      void markConversationRead(id)
      return true
    }
    openingConversationId.value = id
    const ok = await refreshConversationDetail(id)
    if (epoch !== selectionEpoch) return false
    openingConversationId.value = null
    if (!ok) return false
    selectedConversationId.value = id
    void markConversationRead(id)
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

  async function hydrateFromEvents(incoming: CommunicationEvent[]): Promise<void> {
    applyEphemeralSignals(incoming)
    const durable = incoming.filter(event => !isCommunicationEphemeralEvent(event))
    if (!durable.length) return
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
    }
    await loadConversations({ silent: true }).catch(() => undefined)
    if (selectedId !== null && !conversations.value.some(item => item.id === selectedId)) {
      await refreshConversationDetail(selectedId, { reportError: false })
    }
    await loadInboxes().catch(() => undefined)
  }

  async function synchronize(): Promise<void> {
    if (!canView.value) return
    if (syncing.value) {
      synchronizeAgain = true
      return
    }
    syncing.value = true
    syncError.value = null
    const received: CommunicationEvent[] = []
    try {
      do {
        synchronizeAgain = false
        let hasMore = true
        let after = cursor.value
        while (hasMore) {
          const response = await api.communication.events.sync(after)
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
        await hydrateFromEvents(received.splice(0))
      } while (synchronizeAgain)
    } catch (caught) {
      syncError.value = apiErrorMessage(caught, 'Sincronização temporariamente indisponível.')
    } finally {
      syncing.value = false
    }
  }

  function onRealtimeEvent(event: CommunicationRealtimeEvent): void {
    const nextCursor = normalizeCommunicationCursor(event.cursor)
    if (nextCursor === null || nextCursor <= cursor.value) return
    const normalized: CommunicationRealtimeEvent = { ...event, cursor: nextCursor }
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
    if (!canView.value || loading.value) return
    loading.value = true
    error.value = null
    try {
      await Promise.all([loadInboxes(), loadCatalog()])
      await loadConversations({ silent: true })
      await synchronize()
      initialized.value = true
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível abrir o atendimento.')
      toast.add({ title: error.value, color: 'error' })
    } finally {
      loading.value = false
    }
  }

  async function sendMessage(input: CommunicationComposerPayload): Promise<boolean> {
    const conversation = selectedConversation.value
    console.log('[Workspace] sendMessage called', { hasConv: !!conversation, canReply: canReply.value, sending: sending.value, body: input.body })
    if (!conversation || !canReply.value || sending.value) return false
    sending.value = true
    try {
      console.log('[Workspace] calling API...')
      const response = await api.communication.conversations.send(conversation.id, {
        body: input.body,
        internal_note: input.internalNote,
        reply_to_message_id: input.replyToMessageId,
        idempotency_key: input.internalNote
          ? undefined
          : `web-${Date.now()}-${crypto.randomUUID()}`,
        file: input.file,
        kind: input.internalNote ? undefined : input.kind,
        ptt: input.internalNote ? undefined : input.ptt
      })
      conversation.messages = mergeCommunicationMessages(conversation.messages ?? [], [response.data])
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
      'status' | 'assignee_membership_id' | 'work_department_id' | 'priority' | 'snoozed_until'>>
  ): Promise<boolean> {
    const conversation = selectedConversation.value
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

  async function toggleLabel(label: CommunicationLabel): Promise<void> {
    const conversation = selectedConversation.value
    if (!conversation || !canReply.value) return
    const assigned = conversation.labels?.some(item => item.id === label.id) ?? false
    try {
      if (assigned) {
        await api.communication.conversations.removeLabel(conversation.id, label.id)
      } else {
        await api.communication.conversations.addLabel(conversation.id, label.id)
      }
      await refreshConversationDetail(conversation.id, { reportError: false })
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao atualizar marcador.'), color: 'error' })
    }
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

  watch([search, inboxFilter, statusFilter, assigneeFilter, departmentFilter, unassignedOnly, unreadOnly], reloadForFilters)
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
    selectionEpoch++
    conversationQueryGeneration++
    conversationQueryController?.abort()
    conversationQueryController = null
    detailRequests.clear()
    dispose()
    inboxes.value = []
    conversations.value = []
    resetConversationPagination()
    conversationDetails.value = {}
    selectedConversationId.value = null
    openingConversationId.value = null
    labels.value = []
    cannedResponses.value = []
    events.value = []
    chatPresenceByConversation.value = {}
    contactPresenceByConversation.value = {}
    cursor.value = 0
    policies.value = []
    featureMeta.value = { ...EMPTY_FEATURE_META }
    initialized.value = false
    if (canView.value) void initialize()
  })

  function dispose(): void {
    conversationQueryGeneration++
    conversationQueryController?.abort()
    conversationQueryController = null
    stopCursorPoll()
    for (const unsubscribe of subscriptions.values()) unsubscribe()
    subscriptions.clear()
    for (const timer of signalTimers.values()) clearTimeout(timer)
    signalTimers.clear()
    presenceSubscriptions.clear()
    chatPresenceByConversation.value = {}
    contactPresenceByConversation.value = {}
    tenantSubscription?.()
    tenantSubscription = null
    initialized.value = false
  }

  return {
    adminLoading: readonly(adminLoading),
    assigneeFilter,
    automationMeta,
    cannedResponses,
    canManage,
    canReply,
    canView,
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
    labels,
    loadAdministration,
    loadCatalog,
    loadMoreConversations,
    loadInboxes,
    loadRecipients,
    loading: readonly(loading),
    messageActionLoadingId: readonly(messageActionLoadingId),
    tenantMembers,
    openingConversationId: readonly(openingConversationId),
    outboundOperational,
    policies,
    prefetchConversation,
    realtimeState: realtime.state,
    reloadConversations,
    reactMessage,
    recoverMessage,
    replaceInboxMembers,
    logoutInbox,
    savePolicy,
    saveRecipients,
    search,
    selectedConversation,
    selectedConversationId: readonly(selectedConversationId),
    selectedInbox,
    selectedSignals,
    selectConversation,
    sendReceipt,
    sendMessage,
    sending: readonly(sending),
    statusFilter,
    setChatPresence,
    setDisappearingTimer,
    syncError: readonly(syncError),
    syncing: readonly(syncing),
    synchronize,
    toggleLabel,
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

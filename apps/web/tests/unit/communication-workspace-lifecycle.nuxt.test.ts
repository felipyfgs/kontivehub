import { computed, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Conversation, ConversationTimelineMeta } from '~/types/communication/conversations'
import type { Event } from '~/types/communication/realtime'
import type { Message } from '~/types/communication/messages'
import { useCommunicationWorkspace } from '~/composables/useCommunicationWorkspace'

type Deferred<T> = {
  promise: Promise<T>
  resolve: (value: T) => void
  reject: (reason?: unknown) => void
}

type TimelineResponse = {
  data: Message[]
  meta: ConversationTimelineMeta
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((promiseResolve, promiseReject) => {
    resolve = promiseResolve
    reject = promiseReject
  })
  return { promise, resolve, reject }
}

function conversation(id: number): Conversation {
  return {
    id,
    inbox_id: 7,
    status: 'OPEN',
    priority: 0,
    lock_version: 1,
    unread_count: 0,
    last_message_at: '2026-07-31T12:00:00-03:00'
  }
}

function timelineResponse(conversationId: number, messageId: number): TimelineResponse {
  return {
    data: [{
      id: messageId,
      conversation_id: conversationId,
      direction: 'INBOUND',
      kind: 'TEXT',
      source: 'HUMAN',
      status: 'DELIVERED',
      body: `Mensagem ${messageId}`
    }],
    meta: {
      older_cursor: null,
      newer_cursor: null,
      first_unread_message_id: null,
      snapshot_through_message_id: messageId,
      read_state_version: 0,
      unread_count: 0,
      limit: 50
    }
  }
}

describe('ciclo de vida do workspace de comunicação', () => {
  let workspace: ReturnType<typeof useCommunicationWorkspace> | null = null

  afterEach(() => {
    workspace?.dispose()
    workspace = null
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('serializa timelines e ignora callbacks de ciclos descartados', async () => {
    const sessionEpoch = ref(1)
    const eventSyncRequest = deferred<{
      data: Event[]
      meta: { next_cursor: number, has_more: boolean }
    }>()
    const detailCalls: Array<{
      id: number
      request: Deferred<{ data: Conversation }>
    }> = []
    const timelineCalls: Array<{
      id: number
      params: { anchor: string, message_id?: number }
      request: Deferred<TimelineResponse>
    }> = []

    const inboxesList = vi.fn().mockResolvedValue({
      data: [{
        id: 7,
        name: 'Atendimento',
        status: 'CONNECTED',
        is_enabled: true,
        is_default: true,
        lock_version: 1
      }],
      meta: {
        global_enabled: true,
        gateway_enabled: true,
        tenant_enabled: true
      }
    })
    const conversationsList = vi.fn().mockResolvedValue({
      data: [conversation(1), conversation(2), conversation(3), conversation(4)],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 4 }
    })
    const conversationGet = vi.fn((id: number) => {
      const request = deferred<{ data: Conversation }>()
      detailCalls.push({ id, request })
      return request.promise
    })
    const conversationMessages = vi.fn((
      id: number,
      params: { anchor: string, message_id?: number }
    ) => {
      const request = deferred<TimelineResponse>()
      timelineCalls.push({ id, params, request })
      return request.promise
    })
    const eventsSync = vi.fn(() => eventSyncRequest.promise)
    const addToast = vi.fn()
    const realtime = {
      enabled: false,
      state: ref('disabled'),
      channelsReady: computed(() => false),
      subscribeInbox: vi.fn(() => () => undefined),
      subscribeTenant: vi.fn(() => () => undefined)
    }

    vi.stubGlobal('useDashboard', () => ({
      me: ref({
        id: 1,
        effective_permissions: ['communication.view'],
        current_tenant: { id: 1 }
      }),
      sessionEpoch
    }))
    Object.defineProperty(useNuxtApp(), '$communicationRealtime', {
      configurable: true,
      value: realtime
    })
    vi.stubGlobal('useToast', () => ({ add: addToast }))
    vi.stubGlobal('useApi', () => ({
      communication: {
        inboxes: { list: inboxesList },
        catalog: {
          labels: vi.fn().mockResolvedValue({ data: [] }),
          cannedResponses: vi.fn().mockResolvedValue({ data: [] })
        },
        conversationListPreferences: {
          get: vi.fn().mockResolvedValue({
            data: { status: 'OPEN', sort_by: 'last_activity_desc' }
          }),
          update: vi.fn()
        },
        conversations: {
          list: conversationsList,
          get: conversationGet,
          messages: conversationMessages
        },
        events: { sync: eventsSync }
      },
      tenant: {
        members: { list: vi.fn().mockResolvedValue({ data: [] }) }
      }
    }))

    workspace = useCommunicationWorkspace()
    await vi.waitFor(() => expect(workspace?.initialized.value).toBe(true))
    await vi.waitFor(() => expect(eventsSync).toHaveBeenCalledTimes(1))

    const anchoredSelection = workspace.selectConversationAtMessage(1, 101)
    await vi.waitFor(() => expect(detailCalls).toHaveLength(1))
    detailCalls[0]?.request.resolve({ data: conversation(1) })
    await vi.waitFor(() => expect(timelineCalls).toHaveLength(1))
    expect(timelineCalls[0]?.params).toMatchObject({ anchor: 'message', message_id: 101 })

    workspace.queueConversationPrefetch([1])
    await Promise.resolve()
    expect(timelineCalls).toHaveLength(1)
    timelineCalls[0]?.request.resolve(timelineResponse(1, 101))
    await expect(anchoredSelection).resolves.toBe(true)
    expect(workspace.selectedConversationId.value).toBe(1)

    workspace.queueConversationPrefetch([2, 3])
    await vi.waitFor(() => expect(detailCalls).toHaveLength(3))
    const stalePrefetches = detailCalls.slice(1, 3)

    workspace.dispose()
    workspace.queueConversationPrefetch([2, 3, 4])
    await vi.waitFor(() => expect(detailCalls).toHaveLength(5))
    const currentPrefetches = detailCalls.slice(3, 5)

    for (const call of stalePrefetches) {
      call.request.resolve({ data: conversation(call.id) })
    }
    await new Promise(resolve => setTimeout(resolve, 0))
    expect(detailCalls.map(call => call.id)).toEqual([1, 2, 3, 2, 3])

    for (const call of currentPrefetches) {
      call.request.resolve({ data: conversation(call.id) })
    }
    await vi.waitFor(() => expect(timelineCalls).toHaveLength(3))
    expect(timelineCalls.slice(1).map(call => call.id)).toEqual([2, 3])

    timelineCalls.find(call => call.id === 2)?.request.resolve(timelineResponse(2, 201))
    await vi.waitFor(() => expect(detailCalls.some(call => call.id === 4)).toBe(true))
    const conversationFour = detailCalls.find(call => call.id === 4)
    conversationFour?.request.resolve({ data: conversation(4) })
    await vi.waitFor(() => expect(timelineCalls.some(call => call.id === 4)).toBe(true))
    timelineCalls.find(call => call.id === 3)?.request.resolve(timelineResponse(3, 301))
    timelineCalls.find(call => call.id === 4)?.request.resolve(timelineResponse(4, 401))

    workspace.queueConversationPrefetch([5])
    await vi.waitFor(() => expect(detailCalls.some(call => call.id === 5)).toBe(true))
    const conversationFive = detailCalls.find(call => call.id === 5)
    conversationFive?.request.resolve({ data: conversation(5) })
    await vi.waitFor(() => expect(timelineCalls.some(call => call.id === 5)).toBe(true))
    const defaultTimelineFive = timelineCalls.find(call => call.id === 5)
    const anchoredAfterDefault = workspace.selectConversationAtMessage(5, 501)
    await Promise.resolve()
    const timelineCallCountBeforeDispose = timelineCalls.length
    workspace.dispose()
    defaultTimelineFive?.request.resolve(timelineResponse(5, 500))
    await expect(anchoredAfterDefault).resolves.toBe(false)
    expect(timelineCalls).toHaveLength(timelineCallCountBeforeDispose)

    const inboxCallsBeforeStaleSync = inboxesList.mock.calls.length
    const listCallsBeforeStaleSync = conversationsList.mock.calls.length
    eventSyncRequest.resolve({
      data: [{
        cursor: 10,
        type: 'conversation.updated',
        conversation_id: 99,
        payload: {},
        occurred_at: '2026-07-31T12:01:00-03:00'
      }],
      meta: { next_cursor: 10, has_more: false }
    })
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(workspace.events.value).toEqual([])
    expect(workspace.syncing.value).toBe(false)
    expect(inboxesList).toHaveBeenCalledTimes(inboxCallsBeforeStaleSync)
    expect(conversationsList).toHaveBeenCalledTimes(listCallsBeforeStaleSync)
    expect(addToast).not.toHaveBeenCalled()

    const staleInboxesRequest = deferred<{
      data: Array<{
        id: number
        name: string
        status: 'CONNECTED'
        is_enabled: boolean
        is_default: boolean
        lock_version: number
      }>
      meta: { global_enabled: boolean, gateway_enabled: boolean, tenant_enabled: boolean }
    }>()
    const inboxCallsBeforeStaleInitialize = inboxesList.mock.calls.length
    inboxesList.mockImplementationOnce(() => staleInboxesRequest.promise)
    const staleInitialization = workspace.initialize()
    await vi.waitFor(() => expect(inboxesList).toHaveBeenCalledTimes(
      inboxCallsBeforeStaleInitialize + 1
    ))
    sessionEpoch.value = 2
    await vi.waitFor(() => expect(inboxesList.mock.calls.length).toBeGreaterThanOrEqual(
      inboxCallsBeforeStaleInitialize + 2
    ))
    await vi.waitFor(() => expect(workspace.initialized.value).toBe(true))
    expect(workspace.inboxes.value[0]?.id).toBe(7)
    expect(workspace.loading.value).toBe(false)

    staleInboxesRequest.resolve({
      data: [{
        id: 99,
        name: 'Atendimento antigo',
        status: 'CONNECTED',
        is_enabled: true,
        is_default: true,
        lock_version: 1
      }],
      meta: {
        global_enabled: true,
        gateway_enabled: true,
        tenant_enabled: true
      }
    })
    await staleInitialization

    expect(workspace.inboxes.value[0]?.id).toBe(7)
    expect(workspace.initialized.value).toBe(true)
    expect(addToast).not.toHaveBeenCalled()
  }, 15_000)
})

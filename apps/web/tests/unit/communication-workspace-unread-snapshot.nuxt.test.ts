import { computed, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Conversation } from '~/types/communication/conversations'
import { useCommunicationWorkspace } from '~/composables/useCommunicationWorkspace'

function conversation(id: number, unreadCount = 1): Conversation {
  return {
    id,
    inbox_id: 7,
    status: 'OPEN',
    priority: 0,
    lock_version: 1,
    unread_count: unreadCount,
    first_unread_message_id: unreadCount > 0 ? 100 + id : null,
    read_state: {
      version: unreadCount > 0 ? 0 : 1,
      last_read_through_message_id: unreadCount > 0 ? null : 100 + id
    }
  }
}

describe('workspace de comunicação — snapshot de Não lidas', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('reutiliza o token, preserva linhas lidas e falha fechado até renovação explícita', async () => {
    const sessionEpoch = ref(1)
    let snapshotCreation = 0
    let failNextSnapshotRead = false
    const readIds = new Set<number>()
    const conversationsList = vi.fn(async (params?: {
      unread?: boolean
      snapshot?: boolean
      snapshot_token?: string
      page?: number
      q?: string
    }) => {
      if (!params?.unread) {
        return {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 }
        }
      }
      if (failNextSnapshotRead && params.snapshot_token) {
        failNextSnapshotRead = false
        throw {
          statusCode: 410,
          data: {
            code: 'CONVERSATION_LIST_SNAPSHOT_EXPIRED',
            message: 'A visão de não lidas expirou. Reaplique “Não lidas”.'
          }
        }
      }

      if (params.snapshot) snapshotCreation += 1
      const token = params.snapshot_token ?? `snapshot-${snapshotCreation}`
      if (token === 'snapshot-1' && params.page === 2) {
        return {
          data: [conversation(3)],
          meta: {
            current_page: 2,
            last_page: 2,
            per_page: 50,
            total: 3,
            snapshot_token: token,
            snapshot_expires_at: '2026-08-02T20:00:00Z'
          }
        }
      }

      const data = token === 'snapshot-1'
        ? [conversation(1, readIds.has(1) ? 0 : 1), conversation(2)]
        : token === 'snapshot-2'
          ? [conversation(2), conversation(3)]
          : [conversation(2)]
      return {
        data,
        meta: {
          current_page: 1,
          last_page: token === 'snapshot-1' ? 2 : 1,
          per_page: 50,
          total: token === 'snapshot-1' ? 3 : data.length,
          snapshot_token: token,
          snapshot_expires_at: '2026-08-02T20:00:00Z'
        }
      }
    })
    const markRead = vi.fn(async (id: number) => {
      readIds.add(id)
      return { data: conversation(id, 0) }
    })
    const markUnread = vi.fn(async (id: number) => {
      readIds.delete(id)
      return { data: conversation(id) }
    })
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
    vi.stubGlobal('useToast', () => ({ add: vi.fn() }))
    vi.stubGlobal('useApi', () => ({
      communication: {
        inboxes: {
          list: vi.fn().mockResolvedValue({
            data: [],
            meta: { global_enabled: false, gateway_enabled: false, tenant_enabled: false }
          })
        },
        catalog: {
          labels: vi.fn().mockResolvedValue({ data: [] }),
          cannedResponses: vi.fn().mockResolvedValue({ data: [] })
        },
        conversationListPreferences: {
          get: vi.fn().mockResolvedValue({
            data: { status: 'OPEN', sort_by: 'last_activity_desc' }
          }),
          update: vi.fn().mockResolvedValue({
            data: { status: 'OPEN', sort_by: 'last_activity_desc' }
          })
        },
        conversations: {
          list: conversationsList,
          get: vi.fn(async (id: number) => ({ data: conversation(id) })),
          messages: vi.fn(async (id: number) => ({
            data: [{
              id: 100 + id,
              conversation_id: id,
              direction: 'INBOUND',
              kind: 'TEXT',
              source: 'HUMAN',
              status: 'DELIVERED',
              body: `Mensagem ${id}`
            }],
            meta: {
              older_cursor: null,
              newer_cursor: null,
              first_unread_message_id: 100 + id,
              snapshot_through_message_id: 100 + id,
              read_state_version: 0,
              unread_count: 1,
              limit: 50
            }
          })),
          markRead,
          markUnread
        },
        events: {
          sync: vi.fn().mockResolvedValue({
            data: [],
            meta: { next_cursor: 0, has_more: false }
          })
        }
      },
      tenant: {
        members: { list: vi.fn().mockResolvedValue({ data: [] }) }
      }
    }))

    const workspace = useCommunicationWorkspace()
    await vi.waitFor(() => expect(workspace.initialized.value).toBe(true))
    conversationsList.mockClear()

    workspace.unreadOnly.value = true
    await vi.waitFor(() => expect(conversationsList).toHaveBeenCalledTimes(1))
    expect(conversationsList.mock.calls[0]?.[0]).toEqual(expect.objectContaining({
      status: 'OPEN',
      unread: true,
      snapshot: true
    }))
    expect(conversationsList.mock.calls[0]?.[0]).not.toHaveProperty('snapshot_token')
    expect(workspace.conversations.value.map(item => item.id)).toEqual([1, 2])
    expect(workspace.conversationsTotal.value).toBe(3)

    await workspace.loadMoreConversations()
    expect(conversationsList.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      page: 2,
      snapshot_token: 'snapshot-1'
    }))
    expect(conversationsList.mock.calls.at(-1)?.[0]).not.toHaveProperty('snapshot')
    expect(workspace.conversations.value.map(item => item.id)).toEqual([1, 2, 3])

    await workspace.markConversationRead(1, 101)
    expect(workspace.conversations.value.map(item => item.id)).toEqual([1, 2, 3])
    expect(workspace.conversations.value[0]?.unread_count).toBe(0)
    expect(workspace.conversationsTotal.value).toBe(3)

    await workspace.reloadConversations()
    expect(conversationsList.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      page: 1,
      snapshot_token: 'snapshot-1'
    }))
    expect(workspace.conversations.value.map(item => item.id)).toEqual([1, 2, 3])
    expect(workspace.conversationsPage.value).toBe(2)

    workspace.search.value = 'fiscal'
    await vi.waitFor(() => expect(snapshotCreation).toBe(2))
    expect(conversationsList.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      q: 'fiscal',
      snapshot: true
    }))
    expect(conversationsList.mock.calls.at(-1)?.[0]).not.toHaveProperty('snapshot_token')
    expect(workspace.conversations.value.map(item => item.id)).toEqual([2, 3])

    failNextSnapshotRead = true
    const callsBeforeExpiration = conversationsList.mock.calls.length
    await workspace.reloadConversations()
    expect(workspace.conversations.value.map(item => item.id)).toEqual([2, 3])
    expect(workspace.conversationsTotal.value).toBe(2)
    expect(workspace.unreadSnapshotExpired.value).toBe(true)
    expect(workspace.error.value).toContain('Reaplique “Não lidas”')

    await workspace.reloadConversations()
    expect(conversationsList).toHaveBeenCalledTimes(callsBeforeExpiration + 1)

    await workspace.refreshUnreadSnapshot()
    expect(conversationsList.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      q: 'fiscal',
      snapshot: true
    }))
    expect(conversationsList.mock.calls.at(-1)?.[0]).not.toHaveProperty('snapshot_token')
    expect(workspace.conversations.value.map(item => item.id)).toEqual([2])
    expect(workspace.unreadSnapshotExpired.value).toBe(false)
    expect(workspace.error.value).toBeNull()

    await workspace.selectConversation(2)
    await workspace.markConversationUnread(2)
    expect(workspace.selectedTimeline.value?.manual_unread).toBe(true)
    await workspace.selectConversation(null)
    await workspace.selectConversation(2)
    expect(workspace.selectedTimeline.value).toEqual(expect.objectContaining({
      manual_unread: false,
      initial_read_pending: true
    }))
    await workspace.acknowledgeConversationTimeline({
      conversationId: 2,
      rendered: true,
      visible: true,
      atEnd: true
    })
    expect(markRead).toHaveBeenLastCalledWith(2, {
      through_message_id: 102
    })

    workspace.dispose()
  })
})

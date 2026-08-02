import { computed, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useCommunicationWorkspace } from '~/composables/useCommunicationWorkspace'

describe('preferências da lista de conversas', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('mantém defaults quando GET falha e preserva filtros locais quando PUT rejeita', async () => {
    const sessionEpoch = ref(1)
    const preferencesGet = vi.fn().mockRejectedValueOnce(new Error('ausente'))
    const preferencesUpdate = vi.fn().mockRejectedValue(new Error('offline'))
    const conversationsList = vi.fn().mockImplementation((params?: { unread?: boolean }) => Promise.resolve({
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
        ...(params?.unread
          ? {
              snapshot_token: 'snapshot-preferences',
              snapshot_expires_at: '2026-08-02T20:00:00Z'
            }
          : {})
      }
    }))
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
          get: preferencesGet,
          update: preferencesUpdate
        },
        conversations: {
          list: conversationsList
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
    expect(workspace.statusFilter.value).toBe('OPEN')
    expect(workspace.sortBy.value).toBe('last_activity_desc')

    conversationsList.mockClear()
    workspace.inboxFilter.value = 7
    workspace.assigneeFilter.value = 4
    workspace.departmentFilter.value = 3
    workspace.labelIdsFilter.value = [9, 10]
    workspace.unreadOnly.value = true
    // Contrato integrado do change: alterações no mesmo tick geram uma única recarga consolidada.
    await vi.waitFor(() => expect(conversationsList).toHaveBeenCalledTimes(1), { timeout: 1_000 })
    await new Promise(resolve => setTimeout(resolve, 200))
    expect(conversationsList).toHaveBeenCalledTimes(1)
    expect(conversationsList.mock.calls[0]?.[0]).toEqual(expect.objectContaining({
      inbox_id: 7,
      assignee_membership_id: 4,
      work_department_id: 3,
      label_ids: [9, 10],
      unread: true
    }))

    workspace.statusFilter.value = 'PENDING'
    workspace.sortBy.value = 'priority_desc'
    await new Promise(resolve => setTimeout(resolve, 500))
    expect(preferencesUpdate).not.toHaveBeenCalled()

    preferencesGet.mockResolvedValue({
      data: { status: 'OPEN', sort_by: 'last_activity_desc' }
    })
    sessionEpoch.value += 1
    await vi.waitFor(() => expect(preferencesGet).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => {
      expect(workspace.initialized.value).toBe(true)
      expect(workspace.statusFilter.value).toBe('OPEN')
      expect(workspace.sortBy.value).toBe('last_activity_desc')
    })

    preferencesUpdate.mockClear()
    workspace.statusFilter.value = 'RESOLVED'
    workspace.sortBy.value = 'created_desc'
    await new Promise(resolve => setTimeout(resolve, 50))
    sessionEpoch.value += 1
    await vi.waitFor(() => expect(preferencesGet).toHaveBeenCalledTimes(3))
    await vi.waitFor(() => {
      expect(workspace.initialized.value).toBe(true)
      expect(workspace.statusFilter.value).toBe('OPEN')
      expect(workspace.sortBy.value).toBe('last_activity_desc')
    })
    await new Promise(resolve => setTimeout(resolve, 500))
    expect(preferencesUpdate).not.toHaveBeenCalled()

    workspace.search.value = 'texto transitório'
    workspace.contactIdFilter.value = 42
    workspace.inboxFilter.value = 7
    await vi.waitFor(() => expect(conversationsList).toHaveBeenCalled())
    conversationsList.mockClear()
    preferencesUpdate.mockClear()

    workspace.statusFilter.value = 'RESOLVED'
    workspace.sortBy.value = 'created_desc'
    await new Promise(resolve => setTimeout(resolve, 50))

    await workspace.applyConversationSavedView({
      status: 'PENDING',
      sort_by: 'priority_desc',
      work_department_id: 3,
      label_ids: [9],
      unread: true
    })
    await vi.waitFor(() => expect(conversationsList).toHaveBeenCalledTimes(1), { timeout: 1_000 })
    expect(conversationsList.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      q: undefined,
      inbox_id: undefined,
      status: 'PENDING',
      work_department_id: 3,
      unread: true,
      label_ids: [9],
      contact_id: undefined,
      sort_by: 'priority_desc'
    }))
    expect(workspace.search.value).toBe('')
    expect(workspace.contactIdFilter.value).toBeNull()
    expect(workspace.inboxFilter.value).toBeNull()
    expect(workspace.assigneeFilter.value).toBeNull()
    expect(workspace.departmentFilter.value).toBe(3)
    expect(workspace.labelIdsFilter.value).toEqual([9])
    expect(workspace.unreadOnly.value).toBe(true)
    expect(workspace.statusFilter.value).toBe('PENDING')
    expect(workspace.sortBy.value).toBe('priority_desc')
    await new Promise(resolve => setTimeout(resolve, 500))
    expect(preferencesUpdate).not.toHaveBeenCalled()

    workspace.statusFilter.value = 'RESOLVED'
    workspace.sortBy.value = 'created_desc'
    await vi.waitFor(() => expect(preferencesUpdate).toHaveBeenCalledWith({
      status: 'RESOLVED',
      sort_by: 'created_desc'
    }))
    expect(workspace.statusFilter.value).toBe('RESOLVED')
    expect(workspace.sortBy.value).toBe('created_desc')
    expect(addToast).toHaveBeenCalledWith(expect.objectContaining({
      description: 'Os filtros da sessão permanecem; a preferência não foi persistida.',
      color: 'error'
    }))
    workspace.dispose()
  })
})

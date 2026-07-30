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
          list: vi.fn().mockResolvedValue({
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 }
          })
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

    workspace.statusFilter.value = 'PENDING'
    workspace.sortBy.value = 'priority_desc'
    await new Promise(resolve => setTimeout(resolve, 500))
    expect(preferencesUpdate).not.toHaveBeenCalled()

    preferencesGet.mockResolvedValueOnce({
      data: { status: 'OPEN', sort_by: 'last_activity_desc' }
    })
    sessionEpoch.value += 1
    await vi.waitFor(() => expect(preferencesGet).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => expect(workspace.initialized.value).toBe(true))

    workspace.statusFilter.value = 'PENDING'
    workspace.sortBy.value = 'priority_desc'
    await vi.waitFor(() => expect(preferencesUpdate).toHaveBeenCalledWith({
      status: 'PENDING',
      sort_by: 'priority_desc'
    }))
    expect(workspace.statusFilter.value).toBe('PENDING')
    expect(workspace.sortBy.value).toBe('priority_desc')
    expect(addToast).toHaveBeenCalledWith(expect.objectContaining({
      description: 'Os filtros da sessão permanecem; a preferência não foi persistida.',
      color: 'error'
    }))
    workspace.dispose()
  })
})

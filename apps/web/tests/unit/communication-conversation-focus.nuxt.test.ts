import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import ConversationList from '../../app/components/communication/ConversationList.vue'
import type { CommunicationConversation, CommunicationInbox } from '../../app/types/communication'

let wrapper: VueWrapper | null = null

const InteractiveCheckboxStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: {
      type: [Boolean, String],
      default: false
    }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('input', {
      ...attrs,
      type: 'checkbox',
      checked: props.modelValue === true,
      onChange: (event: Event) => emit(
        'update:modelValue',
        (event.target as HTMLInputElement).checked
      )
    })
  }
})

afterEach(() => {
  vi.restoreAllMocks()
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
  document.documentElement.style.fontSize = ''
})

describe('ConversationList — foco acessível', () => {
  it('foca e mantém visível a conversa pedida por id estável', async () => {
    const conversations: CommunicationConversation[] = [{
      id: 42,
      inbox_id: 7,
      status: 'OPEN',
      priority: 0,
      lock_version: 1,
      last_message_at: '2026-07-23T12:00:00-03:00',
      contact: {
        id: 9,
        name: 'Maria Silva',
        address_masked: '+55 ••••• 1234',
        phone: '+5511999991234'
      }
    }]
    const inboxes: CommunicationInbox[] = [{
      id: 7,
      name: 'Atendimento',
      status: 'CONNECTED',
      is_enabled: true,
      is_default: true,
      lock_version: 1
    }]

    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: { conversations, inboxes },
      global: {
        stubs: {
          UAlert: true,
          UAvatar: true,
          UBadge: true,
          UIcon: true,
          UCheckbox: true,
          UButton: true,
          UDropdownMenu: true,
          USkeleton: true,
          ShellInfiniteTableLoader: true
        }
      }
    })

    const button = wrapper.get('[data-conversation-id="42"]').element as HTMLButtonElement
    button.scrollIntoView = () => {}

    const focused = await (wrapper.vm as unknown as {
      focusConversation: (conversationId: number) => Promise<boolean>
    }).focusConversation(42)

    expect(focused).toBe(true)
    expect(document.activeElement).toBe(button)
    expect(button.id).toBe('communication-conversation-42')
  }, 15_000)

  it('retorna false quando a conversa não está montada', async () => {
    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: {
        conversations: [],
        inboxes: []
      },
      global: {
        stubs: {
          UAlert: true,
          UAvatar: true,
          UBadge: true,
          UIcon: true,
          UCheckbox: true,
          UButton: true,
          UDropdownMenu: true,
          USkeleton: true,
          ShellInfiniteTableLoader: true
        }
      }
    })

    await expect((wrapper.vm as unknown as {
      focusConversation: (conversationId: number) => Promise<boolean>
    }).focusConversation(999)).resolves.toBe(false)
  })

  it('oferece o contêiner da lista como fallback de foco', async () => {
    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: {
        conversations: [],
        inboxes: []
      },
      global: {
        stubs: {
          UAlert: true,
          UAvatar: true,
          UBadge: true,
          UIcon: true,
          UCheckbox: true,
          UButton: true,
          UDropdownMenu: true,
          USkeleton: true,
          ShellInfiniteTableLoader: true
        }
      }
    })

    const list = wrapper.get('[data-testid="communication-conversation-list"]')
    const focused = await (wrapper.vm as unknown as {
      focusList: () => Promise<boolean>
    }).focusList()

    expect(focused).toBe(true)
    expect(document.activeElement).toBe(list.element)
    expect(list.attributes('tabindex')).toBe('-1')
    expect(list.attributes('role')).toBe('region')
  })

  it('acompanha a fonte em rem e materializa uma conversa distante antes de focar', async () => {
    document.documentElement.style.fontSize = '20px'
    const conversations: CommunicationConversation[] = Array.from({ length: 200 }, (_, index) => ({
      id: index + 1,
      inbox_id: 7,
      status: 'OPEN',
      priority: 0,
      lock_version: 1,
      last_message_at: '2026-07-23T12:00:00-03:00',
      contact: {
        id: index + 1,
        name: `Contato ${index + 1}`
      }
    }))
    const inboxes: CommunicationInbox[] = [{
      id: 7,
      name: 'Atendimento',
      status: 'CONNECTED',
      is_enabled: true,
      is_default: true,
      lock_version: 1
    }]

    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: { conversations, inboxes },
      global: {
        stubs: {
          UAlert: true,
          UAvatar: true,
          UBadge: true,
          UIcon: true,
          UCheckbox: true,
          UButton: true,
          UDropdownMenu: true,
          USkeleton: true,
          ShellInfiniteTableLoader: true
        }
      }
    })

    const list = wrapper.get('[data-testid="communication-conversation-list"]').element as HTMLElement
    Object.defineProperty(list, 'clientHeight', { configurable: true, value: 368 })
    list.scrollTo = vi.fn((options?: ScrollToOptions | number) => {
      list.scrollTop = typeof options === 'number' ? options : (options?.top ?? 0)
    })
    vi.spyOn(HTMLElement.prototype, 'scrollIntoView').mockImplementation(() => {})

    expect(wrapper.find('[data-conversation-id="180"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="communication-conversation-virtual"]').attributes('style'))
      .toContain('height: 23000px')

    const focused = await (wrapper.vm as unknown as {
      focusConversation: (conversationId: number) => Promise<boolean>
    }).focusConversation(180)

    expect(focused).toBe(true)
    expect(list.scrollTop).toBeGreaterThan(0)
    expect(wrapper.get('[data-conversation-id="180"]').element).toBe(document.activeElement)
    expect(wrapper.get('[data-testid="communication-conversation-row-180"]').attributes('style'))
      .toContain('height: 115px')
    expect(wrapper.findAll('[data-conversation-id]')).toHaveLength(18)
  })

  it('alterna somente a seleção pelo checkbox com clique, Space ou Enter', async () => {
    const conversations: CommunicationConversation[] = [{
      id: 42,
      inbox_id: 7,
      status: 'OPEN',
      priority: 0,
      lock_version: 1,
      last_message_at: '2026-07-23T12:00:00-03:00',
      contact: {
        id: 9,
        name: 'Maria Silva',
        profile_picture_url: '/api/v1/communication/profile-pictures/15/3'
      }
    }]

    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: {
        conversations,
        inboxes: [],
        selectedIds: new Set<number>()
      },
      global: {
        stubs: {
          UAlert: true,
          UAvatar: true,
          UBadge: true,
          UIcon: true,
          UCheckbox: InteractiveCheckboxStub,
          UButton: true,
          UDropdownMenu: true,
          USkeleton: true,
          ShellInfiniteTableLoader: true
        }
      }
    })

    const checkbox = wrapper.get('[data-testid="communication-conversation-check-42"]')
    const bubbledKeydown = vi.fn()
    document.addEventListener('keydown', bubbledKeydown)

    await checkbox.setValue(true)
    expect(wrapper.emitted('toggle-select')?.at(-1)).toEqual([42, true])
    expect(wrapper.emitted('select')).toBeUndefined()

    await checkbox.trigger('keydown', { key: ' ' })
    expect(bubbledKeydown).not.toHaveBeenCalled()
    expect(wrapper.emitted('select')).toBeUndefined()

    await wrapper.setProps({ selectedIds: new Set([42]) })
    await checkbox.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('toggle-select')?.at(-1)).toEqual([42, false])
    expect(wrapper.emitted('select')).toBeUndefined()
    document.removeEventListener('keydown', bubbledKeydown)
  })
})

import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import ConversationList from '../../app/components/communication/ConversationList.vue'
import type { CommunicationConversation, CommunicationInbox } from '../../app/types/communication'

let wrapper: VueWrapper | null = null

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
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
        address_masked: '+55 ••••• 1234'
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
          UAvatar: true,
          UBadge: true,
          UIcon: true
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
  })

  it('retorna false quando a conversa não está montada', async () => {
    wrapper = await mountSuspended(ConversationList, {
      attachTo: document.body,
      props: {
        conversations: [],
        inboxes: []
      },
      global: {
        stubs: {
          UAvatar: true,
          UBadge: true,
          UIcon: true
        }
      }
    })

    await expect((wrapper.vm as unknown as {
      focusConversation: (conversationId: number) => Promise<boolean>
    }).focusConversation(999)).resolves.toBe(false)
  })
})

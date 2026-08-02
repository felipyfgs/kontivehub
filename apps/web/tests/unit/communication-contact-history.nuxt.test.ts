import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import ConversationHistory from '~/components/communication/contacts/ConversationHistory.vue'

let wrapper: VueWrapper | null = null

const NuxtLinkStub = defineComponent({
  props: { to: { type: String, required: true } },
  setup(props, { slots }) {
    return () => h('a', { href: props.to }, slots.default?.())
  }
})

const LabelStub = defineComponent({
  inheritAttrs: false,
  props: {
    label: { type: [String, Number], default: '' },
    title: { type: String, default: '' },
    description: { type: String, default: '' }
  },
  setup(props, { attrs, slots }) {
    return () => h('div', attrs, [
      props.label,
      props.title,
      props.description,
      slots.default?.()
    ])
  }
})

const LoadErrorStub = defineComponent({
  name: 'ShellLoadError',
  inheritAttrs: false,
  props: { title: { type: String, default: '' } },
  emits: ['retry'],
  setup(props, { attrs, emit }) {
    return () => h('button', {
      ...attrs,
      type: 'button',
      onClick: () => emit('retry')
    }, props.title)
  }
})

function conversation(id: number, contactId: number) {
  return {
    id,
    inbox_id: 1,
    status: 'OPEN' as const,
    priority: 0,
    lock_version: 1,
    last_message_at: '2026-08-01T12:00:00Z',
    preview: { kind: 'TEXT', text: `Mensagem ${id}` },
    contact: { id: contactId, phone: '+5511999999999' }
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function stubApi(list: ReturnType<typeof vi.fn>) {
  vi.stubGlobal('useApi', () => ({
    communication: {
      conversations: { list },
      inboxes: {
        list: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'Atendimento' }] })
      }
    }
  }))
}

async function mountHistory(props: {
  contactId: number
  compact?: boolean
  excludeConversationId?: number | null
  limit?: number
}) {
  wrapper = await mountSuspended(ConversationHistory, {
    props,
    global: {
      stubs: {
        NuxtLink: NuxtLinkStub,
        ShellLoadError: LoadErrorStub,
        UBadge: LabelStub,
        UButton: LabelStub,
        UEmpty: LabelStub,
        USkeleton: LabelStub
      }
    }
  })
  return wrapper
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('histórico recente do contato', () => {
  it('exclui a conversa aberta e limita a apresentação compacta', async () => {
    const list = vi.fn().mockResolvedValue({
      data: [
        conversation(12, 5),
        conversation(11, 5),
        conversation(10, 5),
        conversation(9, 5),
        conversation(8, 5)
      ]
    })
    stubApi(list)

    wrapper = await mountHistory({
      contactId: 5,
      compact: true,
      excludeConversationId: 12,
      limit: 3
    })
    await vi.waitFor(() => expect(wrapper?.findAll(
      '[data-testid^="communication-contact-conversation-"]'
    )).toHaveLength(3))

    expect(list).toHaveBeenCalledWith(expect.objectContaining({
      contact_id: 5,
      per_page: 4,
      sort_by: 'last_activity_desc'
    }))
    expect(wrapper.find('[data-testid="communication-contact-conversation-12"]').exists())
      .toBe(false)
    expect(wrapper.find('[data-testid="communication-contact-conversation-11"]').exists())
      .toBe(true)
    expect(wrapper.text()).toContain('Histórico recente')
    expect(wrapper.text()).toContain('Ver todas')
  })

  it('descarta resposta obsoleta quando o contato muda durante a carga', async () => {
    const first = deferred<{ data: ReturnType<typeof conversation>[] }>()
    const second = deferred<{ data: ReturnType<typeof conversation>[] }>()
    const list = vi.fn((filters: { contact_id: number }) =>
      filters.contact_id === 1 ? first.promise : second.promise)
    stubApi(list)

    wrapper = await mountHistory({
      contactId: 1,
      compact: true,
      excludeConversationId: 10,
      limit: 3
    })
    await wrapper.setProps({ contactId: 2, excludeConversationId: 20 })
    second.resolve({ data: [conversation(21, 2)] })
    await vi.waitFor(() => expect(wrapper?.text()).toContain('Conversa #21'))

    first.resolve({ data: [conversation(11, 1)] })
    await new Promise(resolve => setTimeout(resolve, 0))
    expect(wrapper.text()).not.toContain('Conversa #11')
    expect(wrapper.text()).toContain('Conversa #21')
  })

  it('mantém erro real e permite retry', async () => {
    const list = vi.fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce({ data: [conversation(31, 3)] })
    stubApi(list)

    wrapper = await mountHistory({
      contactId: 3,
      compact: true,
      limit: 3
    })
    await vi.waitFor(() => expect(wrapper?.text()).toContain(
      'Não foi possível carregar as conversas deste contato.'
    ))

    wrapper.findComponent({ name: 'ShellLoadError' }).vm.$emit('retry')
    await vi.waitFor(() => expect(wrapper?.text()).toContain('Conversa #31'))
    expect(list).toHaveBeenCalledTimes(2)
  })
})

import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import MessageContent from '../../app/components/communication/MessageContent.vue'
import type { Message } from '../../app/types/communication/messages'

let wrapper: VueWrapper | null = null

const UButtonStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs, slots }) {
    return () => h('button', attrs, slots.default?.())
  }
})

function message(overrides: Partial<Message> = {}): Message {
  return {
    id: 1,
    conversation_id: 1,
    direction: 'OUTBOUND',
    kind: 'IMAGE',
    source: 'GATEWAY',
    status: 'SENT',
    body: null,
    ...overrides
  }
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('MessageContent — disponibilidade de mídia', () => {
  it.each(['INBOUND', 'OUTBOUND'] as const)(
    'apresenta caption e permite recovery para mídia recuperável %s',
    async (direction) => {
      wrapper = await mountSuspended(MessageContent, {
        attachTo: document.body,
        props: {
          message: message({
            direction,
            content: { caption: 'Documento enviado' },
            availability: { state: 'MEDIA_RETRY_AVAILABLE', recoverable: true }
          }),
          canReply: true
        },
        global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
      })

      expect(wrapper.text()).toContain('Documento enviado')
      expect(wrapper.get('[data-testid="communication-message-availability-MEDIA_RETRY_AVAILABLE"]').text())
        .toContain('Esta mídia histórica pode ser recuperada.')

      await wrapper.get('button').trigger('click')
      expect(wrapper.emitted('recover')).toEqual([[expect.any(Object), 'MEDIA_RETRY']])
    }
  )

  it('não oferece recovery quando a API não declara a mensagem recuperável', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({ availability: { state: 'MEDIA_FAILED', recoverable: false } }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.text()).toContain('Não foi possível recuperar esta mídia.')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('bloqueia recovery sem a permissão composta, inclusive quando a inbox não está operacional', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({ availability: { state: 'MEDIA_RETRY_AVAILABLE', recoverable: true } }),
        // TimelinePanel compõe membership e inbox operacional em `canReply && outboundOperational`.
        canReply: false
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it.each([
    ['UNSUPPORTED', 'Este tipo de mensagem ainda não é compatível.'],
    ['MEDIA_RETRY_AVAILABLE', 'Esta mídia histórica pode ser recuperada.'],
    ['MEDIA_REQUESTED', 'A recuperação desta mídia foi solicitada.'],
    ['MEDIA_FAILED', 'Não foi possível recuperar esta mídia.'],
    ['UNAVAILABLE', 'Conteúdo indisponível.']
  ] as const)('renderiza placeholder explícito para %s', async (state, label) => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({ availability: { state, recoverable: false } }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.get(`[data-testid="communication-message-availability-${state}"]`).text()).toContain(label)
  })

  it('não deixa balão vazio quando o payload não contém corpo, conteúdo ou anexo', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: { message: message(), canReply: false },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.get('[data-testid="communication-message-availability-UNAVAILABLE"]').text())
      .toContain('Conteúdo indisponível.')
  })

  it('renderiza contatos múltiplos a partir de content sem colapsar a lista', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({
          kind: 'CONTACT',
          content: {
            contacts: [
              { display_name: 'Ana', phones: [{ label: 'CELULAR', phone: '+5511999991111' }] },
              { display_name: 'Bruno', phones: [{ label: 'TRABALHO', phone: '+5511999992222' }] }
            ]
          },
          availability: { state: 'AVAILABLE', recoverable: false }
        }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.findAll('[data-testid="communication-contact-card"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Ana')
    expect(wrapper.text()).toContain('Bruno')
  })

  it.each([
    ['https://example.test/item', 'A'],
    ['javascript:alert(document.domain)', 'DIV']
  ] as const)('permite somente link preview http ou https para %s', async (url, tagName) => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({
          kind: 'TEXT',
          content: { text: 'Confira', link_preview: { url, title: 'Destino' } },
          availability: { state: 'AVAILABLE', recoverable: false }
        }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    const preview = wrapper.get('[data-testid="communication-link-preview"]')
    expect(preview.element.tagName).toBe(tagName)
    expect(preview.attributes('href')).toBe(tagName === 'A' ? 'https://example.test/item' : undefined)
    expect(preview.text()).toContain('Destino')
  })

  it.each([
    ['LOCATION', { location: { latitude: -23.55, longitude: -46.63, name: 'Escritório' } }, 'communication-location-card'],
    ['POLL', { poll: { name: 'Escolha', options: ['A', 'B'], selectable_options: 1 } }, 'communication-poll-card'],
    ['INTERACTIVE', { rich_card: { category: 'ORDER', title: 'Pedido recebido', facts: [{ label: 'Itens', value: '2' }] } }, 'communication-rich-card']
  ] as const)('renderiza %s a partir do conteúdo semântico', async (kind, content, testId) => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({ kind, content, availability: { state: 'AVAILABLE', recoverable: false } }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    expect(wrapper.get(`[data-testid="${testId}"]`).exists()).toBe(true)
  })

  it('abre mídia disponível no viewer da timeline', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({
          attachments: [{
            id: 9,
            filename: 'foto.jpg',
            mime_type: 'image/jpeg',
            size_bytes: 10,
            sha256: 'a'.repeat(64),
            download_url: '/download',
            preview_url: '/preview'
          }],
          availability: { state: 'AVAILABLE', recoverable: false }
        }),
        canReply: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    await wrapper.get('button[aria-label="Abrir foto.jpg"]').trigger('click')
    expect(wrapper.emitted('openMedia')).toEqual([[expect.any(Object), 9]])
  })

  it('salva contato único enviando somente os índices da mensagem', async () => {
    const saveSharedContact = vi.fn().mockResolvedValue({
      data: { outcome: 'created', contact: { id: 7 } }
    })
    const add = vi.fn()
    vi.stubGlobal('useApi', () => ({
      communication: { conversations: { saveSharedContact } }
    }))
    vi.stubGlobal('useToast', () => ({ add }))
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: {
        message: message({
          id: 91,
          conversation_id: 42,
          kind: 'CONTACT',
          content: {
            contacts: [{
              display_name: 'Ana',
              phones: [{ label: 'CELULAR', phone: '+5511999991111' }]
            }]
          },
          availability: { state: 'AVAILABLE', recoverable: false }
        }),
        canReply: true,
        canManageContacts: true
      },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true, UModal: true } }
    })

    await wrapper.get('button[aria-label="Salvar Ana"]').trigger('click')
    await flushPromises()
    expect(saveSharedContact).toHaveBeenCalledWith(42, 91, 0, 0)
    expect(add).toHaveBeenCalledWith(expect.objectContaining({ title: 'Contato salvo' }))
  })
})

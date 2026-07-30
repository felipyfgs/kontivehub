import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import MessageContent from '../../app/components/communication/MessageContent.vue'
import type { CommunicationMessage } from '../../app/types/communication'

let wrapper: VueWrapper | null = null

const UButtonStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs, slots }) {
    return () => h('button', attrs, slots.default?.())
  }
})

function message(overrides: Partial<CommunicationMessage> = {}): CommunicationMessage {
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
        global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true } }
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
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true } }
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
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true } }
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
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true } }
    })

    expect(wrapper.get(`[data-testid="communication-message-availability-${state}"]`).text()).toContain(label)
  })

  it('não deixa balão vazio quando o payload legado não contém corpo, conteúdo ou anexo', async () => {
    wrapper = await mountSuspended(MessageContent, {
      attachTo: document.body,
      props: { message: message(), canReply: false },
      global: { stubs: { UIcon: true, UAvatar: true, UButton: UButtonStub, UBadge: true } }
    })

    expect(wrapper.get('[data-testid="communication-message-availability-UNAVAILABLE"]').text())
      .toContain('Conteúdo indisponível.')
  })
})

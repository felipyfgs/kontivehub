import { mountSuspended } from '@nuxt/test-utils/runtime'
import type { VueWrapper } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import NewConversationModal from '../../app/components/communication/NewConversationModal.vue'
import SharedContent from '../../app/components/communication/SharedContent.vue'
import type {
  CommunicationContact,
  CommunicationInbox,
  CommunicationSharedContentItem
} from '../../app/types/communication'

const mocks = vi.hoisted(() => ({
  addToast: vi.fn(),
  createConversation: vi.fn(),
  listContacts: vi.fn(),
  download: vi.fn(),
  outboundCapabilities: vi.fn(),
  sharedContent: vi.fn()
}))

let wrapper: VueWrapper | null = null

const SlotStub = defineComponent({
  inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    description: { type: String, default: '' }
  },
  setup(props, { attrs, slots }) {
    return () => h('div', attrs, [
      props.title,
      props.description,
      slots.default?.(),
      slots.body?.(),
      slots.actions?.()
    ])
  }
})

const ButtonStub = defineComponent({
  inheritAttrs: false,
  props: {
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    type: { type: String, default: 'button' }
  },
  setup(props, { attrs, slots }) {
    return () => h('button', {
      ...attrs,
      type: props.type,
      disabled: props.disabled || props.loading
    }, slots.default?.() ?? props.label)
  }
})

const TextareaStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('textarea', {
      ...attrs,
      value: props.modelValue,
      disabled: props.disabled,
      onInput: (event: Event) => emit(
        'update:modelValue',
        (event.target as HTMLTextAreaElement).value
      )
    })
  }
})

const CheckboxStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('label', [
      h('input', {
        ...attrs,
        type: 'checkbox',
        checked: props.modelValue,
        onChange: (event: Event) => emit(
          'update:modelValue',
          (event.target as HTMLInputElement).checked
        )
      }),
      props.label
    ])
  }
})

const globalStubs = {
  UAlert: SlotStub,
  UButton: ButtonStub,
  UCheckbox: CheckboxStub,
  UFormField: SlotStub,
  UIcon: true,
  UInput: true,
  UModal: SlotStub,
  USelectMenu: true,
  USkeleton: true,
  UTabs: true,
  UTextarea: TextareaStub
}

function mediaItem(
  id: string,
  messageId: number,
  filename: string,
  mimeType: string
): CommunicationSharedContentItem {
  return {
    id,
    type: 'attachment',
    category: 'media',
    conversation_id: 42,
    message_id: messageId,
    attachment: {
      id: messageId,
      filename,
      mime_type: mimeType,
      size_bytes: 2048,
      preview_url: `/api/v1/communication/attachments/${messageId}/preview`,
      download_url: `/api/v1/communication/attachments/${messageId}/download`
    }
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('useApi', () => ({
    communication: {
      catalog: {
        outboundCapabilities: mocks.outboundCapabilities
      },
      contacts: {
        list: mocks.listContacts,
        sharedContent: mocks.sharedContent
      },
      conversations: {
        create: mocks.createConversation,
        sharedContent: mocks.sharedContent
      }
    }
  }))
  vi.stubGlobal('useAuthenticatedDownload', () => ({ download: mocks.download }))
  vi.stubGlobal('useToast', () => ({ add: mocks.addToast }))
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('Communication shared content — comportamento', () => {
  it('pagina, navega no viewer, transforma a imagem, baixa e salta para a origem', async () => {
    const first = mediaItem('media-1', 101, 'foto.jpg', 'image/jpeg')
    const second = mediaItem('media-2', 102, 'video.mp4', 'video/mp4')
    const third = mediaItem('media-3', 103, 'outra.jpg', 'image/jpeg')
    mocks.sharedContent
      .mockResolvedValueOnce({
        data: [first, second],
        meta: { next_cursor: 'cursor-2' }
      })
      .mockResolvedValueOnce({
        data: [third],
        meta: { next_cursor: null }
      })

    wrapper = await mountSuspended(SharedContent, {
      attachTo: document.body,
      props: { conversationId: 42 },
      global: { stubs: globalStubs }
    })
    await flushPromises()

    expect(mocks.sharedContent).toHaveBeenNthCalledWith(1, 42, {
      category: 'media',
      limit: 30
    })
    await wrapper.get('button[aria-label="Abrir foto.jpg"]').trigger('click')
    const viewerImage = () => wrapper!.get('[data-testid="communication-shared-content-viewer-image"]')
    expect(viewerImage().attributes('style')).toContain('scale(1) rotate(0deg)')

    await wrapper.get('button[aria-label="Ampliar imagem"]').trigger('click')
    await wrapper.get('button[aria-label="Girar imagem"]').trigger('click')
    expect(viewerImage().attributes('style')).toContain('scale(1.25) rotate(90deg)')

    await wrapper.get('button[aria-label="Próxima mídia"]').trigger('click')
    const video = wrapper.get('video')
    expect(video.attributes('src')).toContain('/attachments/102/download')
    expect(video.attributes('poster')).toContain('/attachments/102/preview')
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    await flushPromises()
    expect(viewerImage().exists()).toBe(true)

    const viewerButtons = wrapper.findAll('button')
    await viewerButtons.find(button => button.text() === 'Baixar')!.trigger('click')
    expect(mocks.download).toHaveBeenCalledWith(
      '/api/v1/communication/attachments/101/download',
      'foto.jpg'
    )
    await viewerButtons.find(button => button.text() === 'Ir para mensagem')!.trigger('click')
    expect(wrapper.emitted('jump')).toEqual([[{ conversationId: 42, messageId: 101 }]])

    await wrapper.findAll('button').find(button => button.text() === 'Carregar mais')!.trigger('click')
    await flushPromises()
    expect(mocks.sharedContent).toHaveBeenNthCalledWith(2, 42, {
      category: 'media',
      limit: 30,
      cursor: 'cursor-2'
    })
    expect(wrapper.find('button[aria-label="Abrir outra.jpg"]').exists()).toBe(true)
  })

  it('mantém retry explícito após falha da API', async () => {
    mocks.sharedContent
      .mockRejectedValueOnce(new Error('indisponível'))
      .mockResolvedValueOnce({ data: [], meta: { next_cursor: null } })

    wrapper = await mountSuspended(SharedContent, {
      attachTo: document.body,
      props: { conversationId: 42 },
      global: { stubs: globalStubs }
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Não foi possível carregar o conteúdo compartilhado.')
    await wrapper.findAll('button').find(button => button.text() === 'Tentar novamente')!.trigger('click')
    await flushPromises()
    expect(mocks.sharedContent).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Nenhum item visível nesta categoria.')
  })

  it('preserva itens carregados quando a paginação incremental falha', async () => {
    const first = mediaItem('media-1', 101, 'foto.jpg', 'image/jpeg')
    mocks.sharedContent
      .mockResolvedValueOnce({ data: [first], meta: { next_cursor: 'cursor-2' } })
      .mockRejectedValueOnce(new Error('indisponível'))

    wrapper = await mountSuspended(SharedContent, {
      attachTo: document.body,
      props: { conversationId: 42 },
      global: { stubs: globalStubs }
    })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'Carregar mais')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Não foi possível carregar o conteúdo compartilhado.')
    expect(wrapper.find('button[aria-label="Abrir foto.jpg"]').exists()).toBe(true)
  })
})

describe('Nova conversa — comportamento', () => {
  const contact: CommunicationContact = {
    id: 9,
    name: 'Maria Silva',
    is_provisional: false,
    is_active: true,
    identities: [{
      id: 19,
      channel: 'WHATSAPP',
      address_masked: '***1234',
      phone: '+5511999991234',
      is_active: true,
      links: []
    }]
  }
  const inboxes: CommunicationInbox[] = [{
    id: 7,
    name: 'Atendimento',
    status: 'CONNECTED',
    is_enabled: true,
    is_default: true,
    lock_version: 1
  }]

  it('reutiliza a chave idempotente no retry do mesmo áudio/PTT', async () => {
    mocks.outboundCapabilities.mockResolvedValue({
      data: {
        conversation_initiation: {
          enabled: true,
          reason: null
        }
      }
    })
    mocks.createConversation
      .mockRejectedValueOnce(new Error('timeout'))
      .mockResolvedValueOnce({
        data: {
          reused_conversation: false,
          conversation: { id: 77 }
        }
      })

    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: {
        open: false,
        contact,
        inboxes,
        canReply: true
      },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()

    const audio = new File(['audio'], 'voz.ogg', { type: 'audio/ogg' })
    const input = wrapper.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', { configurable: true, value: [audio] })
    await input.trigger('change')
    await wrapper.get('input[type="checkbox"]').setValue(true)

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.createConversation).toHaveBeenCalledTimes(2)
    const [firstBody, firstKey] = mocks.createConversation.mock.calls[0]!
    const [secondBody, secondKey] = mocks.createConversation.mock.calls[1]!
    expect(firstBody).toMatchObject({
      contact_id: 9,
      identity_id: 19,
      inbox_id: 7,
      file: audio,
      kind: 'AUDIO',
      ptt: true
    })
    expect(secondBody).toEqual(firstBody)
    expect(firstKey).toBe(secondKey)
    expect(firstKey).toEqual(expect.any(String))
    expect(wrapper.emitted('created')).toEqual([[77]])
  })

  it('não submete quando reply ou capability não autorizam a iniciação', async () => {
    mocks.outboundCapabilities.mockResolvedValue({
      data: {
        conversation_initiation: {
          enabled: false,
          reason: 'kill_switch_active'
        }
      }
    })

    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: {
        open: false,
        contact,
        inboxes,
        canReply: false
      },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()

    expect(wrapper.text()).toContain('Nova conversa indisponível')
    expect(wrapper.text()).toContain('communication.reply')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mocks.createConversation).not.toHaveBeenCalled()
  })

  it('não atribui falha genérica ao gateway nem duplica submissão pendente', async () => {
    mocks.outboundCapabilities.mockRejectedValueOnce(new Error('offline'))
    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: { open: false, contact, inboxes, canReply: true },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()

    expect(wrapper.text()).toContain('Não foi possível verificar a disponibilidade da iniciação.')
    expect(wrapper.text()).not.toContain('O gateway de comunicação está indisponível.')
    wrapper.unmount()

    let resolveCreate: ((value: unknown) => void) | undefined
    mocks.outboundCapabilities.mockResolvedValueOnce({
      data: { conversation_initiation: { enabled: true, reason: null } }
    })
    mocks.createConversation.mockImplementationOnce(() => new Promise((resolve) => {
      resolveCreate = resolve
    }))
    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: { open: false, contact, inboxes, canReply: true },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()
    await wrapper.get('textarea').setValue('Olá')

    const firstSubmit = wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(mocks.createConversation).toHaveBeenCalledTimes(1)
    expect(resolveCreate).toBeDefined()
    resolveCreate!({ data: { reused_conversation: false, conversation: { id: 79 } } })
    await firstSubmit
    await flushPromises()
  })

  it('ignora inbox padrão desconectada e pré-seleciona apenas uma CONNECTED habilitada', async () => {
    mocks.outboundCapabilities.mockResolvedValue({
      data: { conversation_initiation: { enabled: true, reason: null } }
    })
    mocks.createConversation.mockResolvedValue({
      data: { reused_conversation: false, conversation: { id: 78 } }
    })

    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: {
        open: false,
        contact,
        inboxes: [
          { ...inboxes[0]!, status: 'DISCONNECTED', is_default: true },
          { ...inboxes[0]!, id: 8, name: 'Conectada', is_default: false }
        ],
        canReply: true
      },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()
    await wrapper.get('textarea').setValue('Olá')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.createConversation).toHaveBeenCalledWith(
      expect.objectContaining({ inbox_id: 8 }),
      expect.any(String)
    )
  })

  it('invalida a inbox que desconecta com o modal aberto e não a submete', async () => {
    mocks.outboundCapabilities.mockResolvedValue({
      data: { conversation_initiation: { enabled: true, reason: null } }
    })
    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: { open: false, contact, inboxes, canReply: true },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()
    await wrapper.get('textarea').setValue('Olá')
    await wrapper.setProps({ inboxes: [{ ...inboxes[0]!, status: 'DISCONNECTED' }] })
    await flushPromises()
    await wrapper.get('form').trigger('submit')

    expect(mocks.createConversation).not.toHaveBeenCalled()
  })

  it('busca contatos remotamente e carrega páginas adicionais sem perder o contato escolhido', async () => {
    mocks.outboundCapabilities.mockResolvedValue({
      data: { conversation_initiation: { enabled: true, reason: null } }
    })
    mocks.listContacts
      .mockResolvedValueOnce({
        data: [contact],
        meta: { current_page: 1, last_page: 2, total: 21 }
      })
      .mockResolvedValueOnce({
        data: [{ ...contact, id: 10, name: 'Joana' }],
        meta: { current_page: 2, last_page: 2, total: 21 }
      })

    wrapper = await mountSuspended(NewConversationModal, {
      attachTo: document.body,
      props: { open: false, contact: null, contacts: [], inboxes, canReply: true },
      global: { stubs: globalStubs }
    })
    await wrapper.setProps({ open: true })
    await flushPromises()
    expect(mocks.listContacts).toHaveBeenCalledWith(expect.objectContaining({
      page: 1,
      per_page: 20,
      is_active: true,
      sort: 'name'
    }))
    await wrapper.get('[data-testid="communication-new-conversation-load-more"]').trigger('click')
    await flushPromises()
    expect(mocks.listContacts).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
  })
})

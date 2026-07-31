import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import type {
  CommunicationConversation,
  CommunicationEvent,
  CommunicationMessage
} from '~/types/communication'
import {
  communicationContactLabel,
  communicationAvailabilityPlaceholder,
  communicationConversationImageEvidence,
  communicationDisplayName,
  communicationMessageBody,
  communicationMessageSummary,
  communicationProfilePictureUrl,
  communicationPollVoteCount,
  communicationSignalFromEvent,
  isCommunicationEphemeralEvent,
  isCommunicationSignalActive,
  latestCommunicationCursor,
  mergeCommunicationConversations,
  mergeCommunicationEvents,
  mergeCommunicationMessages,
  mergeCommunicationMessageStatus,
  normalizeCommunicationCursor
} from '~/utils/communication'
import {
  communicationRecordingExtension,
  communicationSendKindForMime,
  formatCommunicationRecordingDuration,
  preferredCommunicationRecorderMimeType,
  shouldSubmitCommunicationComposer
} from '~/utils/communication-composer'
import {
  communicationRealtimeConfiguration,
  communicationRealtimeStateForConnection,
  communicationRealtimeTransports,
  resolveCommunicationRealtimeHost
} from '~/utils/communication-realtime'
import {
  canManageCommunication,
  canReplyCommunication,
  canViewCommunication
} from '~/utils/permissions'
import { pgdasdTrackingMeta } from '~/utils/pgdasd'

function message(id: number, status: CommunicationMessage['status'], occurredAt: string): CommunicationMessage {
  return {
    id,
    conversation_id: 1,
    direction: 'OUTBOUND',
    kind: 'TEXT',
    source: 'HUMAN',
    status,
    body: `Mensagem ${id}`,
    occurred_at: occurredAt
  }
}

function conversation(id: number, priority: number, messages?: CommunicationMessage[]): CommunicationConversation {
  return {
    id,
    inbox_id: 10,
    status: 'OPEN',
    priority,
    lock_version: 1,
    last_message_at: `2026-07-${String(id).padStart(2, '0')}T12:00:00Z`,
    messages
  }
}

describe('projeção local da comunicação', () => {
  it('usa foto somente quando READY e preserva compatibilidade com URL legada', () => {
    expect(communicationProfilePictureUrl({
      profile_picture_url: '/api/v1/communication/profile-pictures/1/2',
      profile_picture_state: 'READY'
    })).toBe('/api/v1/communication/profile-pictures/1/2')
    expect(communicationProfilePictureUrl({
      profile_picture_url: '/api/v1/communication/profile-pictures/1/2',
      profile_picture_state: 'PENDING'
    })).toBeNull()
    expect(communicationProfilePictureUrl({
      profile_picture_url: '/api/v1/communication/profile-pictures/1/2'
    })).toBe('/api/v1/communication/profile-pictures/1/2')
    expect(communicationProfilePictureUrl({
      profile_picture_url: null,
      profile_picture_state: 'READY'
    })).toBeNull()
  })

  it('mantém receipts monotônicos inclusive diante de falha e ordem invertida', () => {
    expect(mergeCommunicationMessageStatus('READ', 'SENT')).toBe('READ')
    expect(mergeCommunicationMessageStatus('DELIVERED', 'FAILED')).toBe('DELIVERED')
    expect(mergeCommunicationMessageStatus('UNKNOWN', 'SENT')).toBe('SENT')
    expect(mergeCommunicationMessageStatus('ACCEPTED', 'UNKNOWN')).toBe('UNKNOWN')
    expect(mergeCommunicationMessageStatus('SENT', 'CANCELED')).toBe('SENT')
  })

  it('faz merge idempotente de mensagens sem perder anexos já carregados', () => {
    const initial = {
      ...message(1, 'DELIVERED', '2026-07-22T10:00:00Z'),
      attachments: [{
        id: 5,
        filename: 'documento.pdf',
        mime_type: 'application/pdf',
        size_bytes: 50,
        sha256: 'a'.repeat(64),
        download_url: '/private'
      }]
    }
    const merged = mergeCommunicationMessages(
      [initial],
      [message(1, 'SENT', '2026-07-22T10:00:00Z'), message(2, 'QUEUED', '2026-07-22T11:00:00Z')]
    )

    expect(merged.map(item => item.id)).toEqual([1, 2])
    expect(merged[0]?.status).toBe('DELIVERED')
    expect(merged[0]?.attachments).toHaveLength(1)
    expect(mergeCommunicationMessages(merged, merged)).toEqual(merged)
  })

  it('mantém conteúdo e disponibilidade, mas respeita anexos explicitamente vazios', () => {
    const initial: CommunicationMessage = {
      ...message(1, 'DELIVERED', '2026-07-22T10:00:00Z'),
      body: 'Mensagem preservada',
      content: { text: 'Mensagem preservada' },
      availability: { state: 'MEDIA_RETRY_AVAILABLE', recoverable: true },
      attachments: [{
        id: 5,
        filename: 'documento.pdf',
        mime_type: 'application/pdf',
        size_bytes: 50,
        sha256: 'a'.repeat(64),
        download_url: '/private'
      }]
    }

    const merged = mergeCommunicationMessages([initial], [{
      ...message(1, 'SENT', '2026-07-22T10:00:00Z'),
      body: null,
      content: { text: null, caption: null },
      availability: null,
      attachments: []
    }])[0]

    expect(merged?.body).toBe('Mensagem preservada')
    expect(merged?.content).toEqual({ text: 'Mensagem preservada', caption: null })
    expect(merged?.attachments).toEqual([])
    expect(merged?.availability).toEqual({ state: 'MEDIA_RETRY_AVAILABLE', recoverable: true })
  })

  it('normaliza texto e legenda aditivos e expõe placeholders de disponibilidade', () => {
    const caption: CommunicationMessage = {
      ...message(3, 'SENT', '2026-07-22T10:00:00Z'),
      body: null,
      content: { caption: 'Legenda da imagem' },
      availability: { state: 'AVAILABLE', recoverable: false }
    }
    expect(communicationMessageBody(caption)).toBe('Legenda da imagem')
    expect(communicationAvailabilityPlaceholder(caption)).toBeNull()

    for (const [state, placeholder] of [
      ['UNSUPPORTED', 'Este tipo de mensagem ainda não é compatível.'],
      ['MEDIA_RETRY_AVAILABLE', 'Esta mídia histórica pode ser recuperada.'],
      ['MEDIA_REQUESTED', 'A recuperação desta mídia foi solicitada.'],
      ['MEDIA_FAILED', 'Não foi possível recuperar esta mídia.'],
      ['UNAVAILABLE', 'Conteúdo indisponível.']
    ] as const) {
      expect(communicationAvailabilityPlaceholder({
        ...caption,
        content: null,
        availability: { state, recoverable: state === 'MEDIA_RETRY_AVAILABLE' }
      })).toBe(placeholder)
    }
  })

  it('preserva timeline detalhada ao atualizar a lista e ordena prioridade', () => {
    const detailed = conversation(1, 0, [message(1, 'READ', '2026-07-22T10:00:00Z')])
    const listProjection = conversation(1, 20)
    const merged = mergeCommunicationConversations(
      [detailed, conversation(2, 5)],
      [listProjection, conversation(2, 5)]
    )

    expect(merged.map(item => item.id)).toEqual([1, 2])
    expect(merged[0]?.messages?.[0]?.status).toBe('READ')
  })

  it('deduplica eventos por cursor e mantém o cursor mais recente', () => {
    const first: CommunicationEvent = {
      cursor: 12,
      type: 'MESSAGE_QUEUED',
      payload: {},
      occurred_at: '2026-07-22T10:00:00Z'
    }
    const second = { ...first, cursor: 15, type: 'MESSAGE_READ' }
    const merged = mergeCommunicationEvents([second], [first, second])

    expect(merged.map(item => item.cursor)).toEqual([12, 15])
    expect(latestCommunicationCursor(merged)).toBe(15)
  })

  it('normaliza cursor websocket number ou string digitável', () => {
    expect(normalizeCommunicationCursor(42)).toBe(42)
    expect(normalizeCommunicationCursor('15')).toBe(15)
    expect(normalizeCommunicationCursor(' 9 ')).toBe(9)
    expect(normalizeCommunicationCursor('12.5')).toBeNull()
    expect(normalizeCommunicationCursor(null)).toBeNull()
    expect(normalizeCommunicationCursor(undefined)).toBeNull()
  })

  it('projeta presence allowlisted com TTL limitado e pausa sem item durável', () => {
    const composing: CommunicationEvent = {
      cursor: 20,
      type: 'CHAT_PRESENCE_CHANGED',
      conversation_id: 7,
      payload: { presence: 'COMPOSING', media: 'TEXT', ttl_seconds: 15 },
      occurred_at: '2026-07-22T10:00:00Z'
    }
    const signal = communicationSignalFromEvent(composing, 1_000)

    expect(isCommunicationEphemeralEvent(composing)).toBe(true)
    expect(signal).toMatchObject({
      kind: 'chat',
      conversation_id: 7,
      presence: 'COMPOSING',
      media: 'TEXT',
      expires_at: 16_000
    })
    expect(isCommunicationSignalActive(signal, 15_999)).toBe(true)
    expect(isCommunicationSignalActive(signal, 16_000)).toBe(false)
    expect(communicationSignalFromEvent({
      ...composing,
      payload: { presence: 'PAUSED', ttl_seconds: 15 }
    }, 1_000)).toBeNull()
  })

  it('limita TTL de presença de contato e preserva somente last seen sanitizado', () => {
    const event: CommunicationEvent = {
      cursor: 21,
      type: 'CONTACT_PRESENCE_CHANGED',
      conversation_id: 8,
      payload: {
        available: false,
        last_seen: '2026-07-22T09:59:00Z',
        ttl_seconds: 999,
        raw_event: 'não deve ser projetado'
      },
      occurred_at: '2026-07-22T10:00:00Z'
    }

    expect(communicationSignalFromEvent(event, 2_000)).toEqual({
      kind: 'contact',
      conversation_id: 8,
      available: false,
      last_seen: '2026-07-22T09:59:00Z',
      expires_at: 302_000
    })
  })

  it('resume conteúdo rico e conta votos sem depender de IDs remotos', () => {
    const poll = {
      ...message(9, 'DELIVERED', '2026-07-22T10:00:00Z'),
      direction: 'INBOUND' as const,
      kind: 'POLL' as const,
      body: null,
      metadata: {
        poll: { name: 'Escolha', options: ['A', 'B'], selectable_options: 1 },
        poll_votes: {
          actor_a: { option_names: ['A'], option_hashes: ['a'.repeat(64)] },
          actor_b: { option_names: ['A', 'B'], option_hashes: ['b'.repeat(64)] }
        }
      }
    }

    expect(communicationMessageSummary(poll)).toBe('Escolha')
    expect(communicationPollVoteCount(poll, 'A')).toBe(2)
    expect(communicationPollVoteCount(poll, 'B')).toBe(1)
    expect(communicationMessageSummary({
      ...poll,
      metadata: { revoked: true }
    })).toBe('Mensagem apagada')
  })

  it('prioriza cliente fiscal e mantém contato como contexto secundário', () => {
    const linked = {
      ...conversation(12, 0),
      clients: [{ id: 1, name: 'Alfa Comércio' }, { id: 2, name: 'Beta Serviços' }],
      contact: {
        id: 3,
        name: 'Maria Financeiro',
        address_masked: '+55••••1234',
        phone: '+5511999991234'
      }
    }
    expect(communicationDisplayName(linked)).toBe('Alfa Comércio +1')
    expect(communicationContactLabel(linked)).toBe('Maria Financeiro · +5511999991234')
    expect(communicationDisplayName({
      ...conversation(13, 0),
      contact: { id: 4, name: 'Contato provisório', address_masked: '+55••••5678' }
    })).toBe('Contato provisório')
  })

  it('nunca usa endereço mascarado como telefone ou título da conversa', () => {
    const masked = {
      ...conversation(14, 0),
      display_title: '+55••••5678',
      secondary_title: '+55••••5678',
      contact: {
        id: 5,
        name: null,
        address_masked: '+55••••5678',
        phone: null
      }
    }

    expect(communicationDisplayName(masked)).toBe('Contato #5')
    expect(communicationContactLabel(masked)).toBeNull()
  })

  it('projeta evidência somente para a última imagem inbound não revogada', () => {
    const inboundImage = {
      ...message(30, 'DELIVERED', '2026-07-22T12:00:00Z'),
      direction: 'INBOUND' as const,
      kind: 'IMAGE' as const,
      attachments: [{
        id: 40,
        filename: 'comprovante.webp',
        mime_type: 'image/webp',
        size_bytes: 2048,
        sha256: 'a'.repeat(64),
        download_url: '/api/v1/communication/attachments/40/download',
        preview_url: '/api/v1/communication/attachments/40/preview'
      }]
    }
    expect(communicationConversationImageEvidence({
      ...conversation(30, 0),
      last_message: inboundImage
    })).toEqual({
      messageId: 30,
      previewUrl: '/api/v1/communication/attachments/40/preview'
    })
    expect(communicationConversationImageEvidence({
      ...conversation(31, 0),
      last_message: { ...inboundImage, direction: 'OUTBOUND' }
    })).toBeNull()
    expect(communicationConversationImageEvidence({
      ...conversation(32, 0),
      last_message: { ...inboundImage, metadata: { revoked: true } }
    })).toBeNull()
  })

  it('mantém fallback textual para imagem sem preview e aceita detalhe em memória', () => {
    const purgedImage = {
      ...message(33, 'DELIVERED', '2026-07-22T12:00:00Z'),
      direction: 'INBOUND' as const,
      kind: 'IMAGE' as const,
      attachments: [{
        id: 43,
        filename: 'expurgada.webp',
        mime_type: 'image/webp',
        size_bytes: 1024,
        sha256: 'b'.repeat(64),
        download_url: '/api/v1/communication/attachments/43/download',
        preview_url: '/api/v1/communication/attachments/43/preview',
        purged_at: '2026-07-22T12:01:00Z'
      }]
    }

    expect(communicationConversationImageEvidence({
      ...conversation(33, 0, [purgedImage])
    })).toEqual({ messageId: 33, previewUrl: null })
    expect(communicationConversationImageEvidence({
      ...conversation(34, 0),
      last_message: { ...purgedImage, kind: 'TEXT' }
    })).toBeNull()
  })
})

describe('interações do composer de comunicação', () => {
  it('envia com Enter, preserva Shift Enter e ignora composição IME', () => {
    expect(shouldSubmitCommunicationComposer({ key: 'Enter', shiftKey: false })).toBe(true)
    expect(shouldSubmitCommunicationComposer({ key: 'Enter', shiftKey: true })).toBe(false)
    expect(shouldSubmitCommunicationComposer({ key: 'Enter', shiftKey: false, isComposing: true })).toBe(false)
    expect(shouldSubmitCommunicationComposer({ key: 'Enter', shiftKey: false, keyCode: 229 })).toBe(false)
    expect(shouldSubmitCommunicationComposer({ key: 'a', shiftKey: false })).toBe(false)
  })

  it('seleciona MIME de gravação e deriva tipo/extensão sem fallback silencioso', () => {
    expect(preferredCommunicationRecorderMimeType(mime => mime === 'audio/mp4')).toBe('audio/mp4')
    expect(preferredCommunicationRecorderMimeType(() => false)).toBeNull()
    expect(communicationRecordingExtension('audio/ogg;codecs=opus')).toBe('ogg')
    expect(communicationRecordingExtension('audio/mp4')).toBe('m4a')
    expect(communicationRecordingExtension('audio/webm;codecs=opus')).toBe('webm')
    expect(communicationSendKindForMime('image/webp')).toBe('IMAGE')
    expect(communicationSendKindForMime('audio/webm;codecs=opus')).toBe('AUDIO')
    expect(communicationSendKindForMime('application/pdf')).toBe('DOCUMENT')
    expect(formatCommunicationRecordingDuration(65)).toBe('1:05')
  })
})

describe('Reverb fail-closed e recuperável', () => {
  it('mantém a caracterização da fila ao adotar paginação incremental', () => {
    const workspace = readFileSync(
      resolve(process.cwd(), 'app/composables/useCommunicationWorkspace.ts'),
      'utf8'
    )
    const api = readFileSync(
      resolve(process.cwd(), 'app/composables/api/createCommunicationApi.ts'),
      'utf8'
    )
    expect(workspace).toContain('function listFilters(page = 1)')
    expect(workspace).toContain('per_page: COMMUNICATION_CONVERSATION_PAGE_SIZE')
    expect(workspace).toContain('COMMUNICATION_CONVERSATION_PAGE_SIZE = 50')
    expect(workspace).toContain('label_ids: labelIdsFilter.value.length ? labelIdsFilter.value : undefined')
    expect(workspace).toContain('sort_by: normalizeCommunicationConversationSortBy(sortBy.value)')
    expect(workspace).toContain('applyConversationMeta(response.meta)')
    expect(workspace).toContain('loadMoreConversations')
    expect(workspace).toContain('conversationDetails.value[id]')
    expect(workspace).toContain('item.id === detail.id ? detail : item')
    expect(workspace).toContain('sessionEpoch: sessionEpoch.value')
    expect(workspace).toContain('conversationQueryGeneration')
    expect(workspace).toContain('conversationQueryController?.abort()')
    expect(api).toContain('CommunicationConversationListMeta')
    expect(api).toContain('options?: { signal?: AbortSignal }')
    expect(api).toContain('conversationListPreferences')
    expect(api).toContain('conversationBulkOperations')
  })

  it('só habilita websocket quando flag e configuração estão completas', () => {
    expect(communicationRealtimeConfiguration({
      communicationEnabled: false,
      reverb: { key: 'key', host: 'reverb', port: 8080, scheme: 'http' }
    }).enabled).toBe(false)
    expect(communicationRealtimeConfiguration({
      communicationEnabled: true,
      reverb: { key: 'communication-disabled', host: 'reverb', port: 8080 }
    }).enabled).toBe(false)
    expect(communicationRealtimeConfiguration({
      communicationEnabled: true,
      reverb: { key: 'app-key', host: 'reverb', port: 8080, scheme: 'https' }
    })).toMatchObject({ enabled: true, forceTLS: true })
    expect(resolveCommunicationRealtimeHost('74.214.172.217', 'localhost')).toBe('localhost')
    expect(resolveCommunicationRealtimeHost('74.214.172.217', '74.214.172.217')).toBe('74.214.172.217')
    expect(communicationRealtimeTransports(false)).toEqual(['ws'])
    expect(communicationRealtimeTransports(true)).toEqual(['wss'])
  })

  it('projeta reconexão, init por canView e sync por cursor normalizado', () => {
    expect(communicationRealtimeStateForConnection('connected')).toBe('connected')
    expect(communicationRealtimeStateForConnection('connecting')).toBe('connecting')
    expect(communicationRealtimeStateForConnection('unavailable')).toBe('unavailable')

    const plugin = readFileSync(
      resolve(process.cwd(), 'app/plugins/communication-realtime.client.ts'),
      'utf8'
    )
    const workspace = readFileSync(
      resolve(process.cwd(), 'app/composables/useCommunicationWorkspace.ts'),
      'utf8'
    )
    const composer = readFileSync(
      resolve(process.cwd(), 'app/components/communication/Composer.vue'),
      'utf8'
    )
    const page = readFileSync(
      resolve(process.cwd(), 'app/components/communication/CommunicationWorkspacePage.vue'),
      'utf8'
    )
    expect(plugin).toContain('\'/api/broadcasting/auth\'')
    expect(plugin).toContain('channelAuthorization')
    expect(plugin).toContain('.listen(\'.communication.event\'')
    expect(plugin).toContain('subscribedChannelCount')
    expect(plugin).toContain('channelsReady')
    expect(workspace).toContain('await api.communication.events.sync(after)')
    expect(workspace).toContain('normalizeCommunicationCursor(event.cursor)')
    expect(workspace).toContain('watch(canView')
    expect(workspace).toContain('{ immediate: true }')
    expect(workspace).toContain('touchesSelected')
    expect(workspace).toContain('reconcileSubscriptions({ force: true })')
    expect(workspace).toContain('5_000')
    expect(workspace).toContain('channelsReady')
    expect(workspace).toContain('event.inbox_id === selectedInboxId')
    expect(workspace).toContain('refreshConversationDetail')
    expect(workspace).toContain('silent: true')
    expect(workspace).toContain('await refreshConversationDetail(selectedId, { reportError: false })')
    expect(workspace).toContain('refreshConversationDetail(conversation.id, { reportError: false })')
    expect(composer).toContain('acknowledge')
    expect(composer).toContain('if (ok) clearDraft()')
    expect(page).toContain('acknowledge?.(ok)')
    expect(page).toContain('void workspace.initialize()')
    expect(page).toContain('applyRouteConversation')
    expect(page).toContain('communicationConversationPath')
    expect(page).toContain('routeConversationId')
    expect(page).not.toContain('route.query')
  })
})

describe('deep-link de conversas', () => {
  it('monta path canônico e parseia id de rota', async () => {
    const {
      COMMUNICATION_INDEX_PATH,
      communicationContactConversationsPath,
      communicationConversationMessagePath,
      communicationConversationPath,
      parseCommunicationConversationId,
      parseCommunicationMessageId,
      isCommunicationNavActive
    } = await import('~/utils/communication-routes')
    expect(COMMUNICATION_INDEX_PATH).toBe('/communication')
    expect(communicationConversationPath(1)).toBe('/communication/conversations/1')
    expect(communicationConversationMessagePath(1, 9)).toBe('/communication/conversations/1/messages/9')
    expect(communicationContactConversationsPath(4)).toBe('/communication/contacts/4/conversations')
    expect(communicationContactConversationsPath(4, 1)).toBe('/communication/contacts/4/conversations/1')
    expect(parseCommunicationConversationId('42')).toBe(42)
    expect(parseCommunicationConversationId(['7'])).toBe(7)
    expect(parseCommunicationConversationId('abc')).toBeNull()
    expect(parseCommunicationMessageId('99')).toBe(99)
    expect(parseCommunicationMessageId('../99')).toBeNull()
    expect(isCommunicationNavActive('/communication')).toBe(true)
    expect(isCommunicationNavActive('/communication/conversations/9')).toBe(true)
    expect(isCommunicationNavActive('/clients')).toBe(false)

    const conversationPage = readFileSync(
      resolve(process.cwd(), 'app/pages/communication/conversations/[id].vue'),
      'utf8'
    )
    expect(conversationPage).toContain('definePageMeta')
    expect(conversationPage).toContain('parsePositiveRouteId(to.params.id)')
    expect(conversationPage).not.toContain('const conversationId =')
  })
})

describe('permissões e estados fiscais', () => {
  it('respeita exclusivamente as permissões efetivas', () => {
    const viewOnly = {
      id: 1,
      effective_permissions: ['communication.view']
    } as MeUser
    expect(canViewCommunication(viewOnly)).toBe(true)
    expect(canReplyCommunication(viewOnly)).toBe(false)
    expect(canManageCommunication(viewOnly)).toBe(false)

    const inboxManager = {
      id: 3,
      effective_permissions: ['communication.view', 'communication.manage_inboxes']
    } as MeUser
    expect(canManageCommunication(inboxManager)).toBe(true)

    const previousPermissionPayload = {
      id: 4,
      effective_permissions: ['communication.manage']
    } as MeUser
    expect(canManageCommunication(previousPermissionPayload)).toBe(false)
  })

  it('expõe SKIPPED_NO_DOCUMENT sem sugerir reabertura automática', () => {
    const meta = pgdasdTrackingMeta('SKIPPED_NO_DOCUMENT')
    expect(meta.label).toBe('Sem documento da competência')
    expect(meta.description).toContain('não reabrirá automaticamente')
  })
})

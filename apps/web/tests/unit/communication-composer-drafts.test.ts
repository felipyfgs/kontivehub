import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { createCommunicationComposerDraftStore } from '~/composables/useCommunicationComposerDrafts'
import type { ComposerDraft, ComposerDraftContext } from '~/types/communication/composer-draft'
import {
  COMPOSER_SESSION_BINARY_BUDGET_BYTES,
  composerDraftBinaryBytes,
  composerDraftContextKey,
  composerDraftHasBinary,
  composerSessionBinaryBytes,
  createComposerBatchSubmissionKeys,
  removeComposerMediaItem,
  updateComposerDraft,
  validateComposerDraft
} from '~/utils/communication-composer-draft'
import { serializeComposerDraft } from '~/utils/communication-composer-draft-api'

const context: ComposerDraftContext = { tenantId: 1, inboxId: 2, conversationId: 3 }
const text = (body = 'Olá'): ComposerDraft => ({
  channel: 'WHATSAPP', family: 'TEXT', body, citation: { replyToMessageId: 8 },
  submission: { idempotencyKey: 'idem-1' }
})

describe('communication composer drafts', () => {
  it('isola os canais e o contexto tenant/inbox/conversation, incluindo a citação', () => {
    const store = createCommunicationComposerDraftStore()
    const note: ComposerDraft = { channel: 'INTERNAL_NOTE', family: 'TEXT', body: 'Privado', citation: { replyToMessageId: 5 } }
    store.set(context, text())
    store.set(context, note)
    store.set({ ...context, conversationId: 4 }, text('Outra conversa'))

    expect(composerDraftContextKey(context)).toBe('1:2:3')
    expect(store.get(context, 'WHATSAPP')).toMatchObject({ body: 'Olá', citation: { replyToMessageId: 8 } })
    expect(store.get(context, 'INTERNAL_NOTE')).toMatchObject({ body: 'Privado', citation: { replyToMessageId: 5 } })
    expect(store.get({ ...context, conversationId: 4 }, 'WHATSAPP')).toMatchObject({ body: 'Outra conversa' })
    expect(store.get({ ...context, tenantId: 9 }, 'WHATSAPP')).toBeNull()
  })

  it('reduz lote sem mutar o draft original e rejeita variantes impossíveis', () => {
    const file = new File(['image'], 'imagem.png', { type: 'image/png' })
    const draft: ComposerDraft = {
      channel: 'WHATSAPP', family: 'MEDIA_BATCH', citation: null,
      items: [
        { clientItemId: 'one', file, kind: 'IMAGE', caption: '', gif: false, ptv: false, viewOnce: false },
        { clientItemId: 'two', file, kind: 'DOCUMENT', caption: '', gif: true, ptv: false, viewOnce: true }
      ],
      submission: { idempotencyKey: 'idem-batch', clientBatchId: 'batch-1' }
    }
    const reduced = removeComposerMediaItem(draft, 'one')

    expect(draft.items).toHaveLength(2)
    expect(reduced).toMatchObject({ items: [{ clientItemId: 'two' }] })
    expect(validateComposerDraft(draft).map(error => error.message)).toEqual(expect.arrayContaining([
      'GIF e vídeo circular exigem vídeo.',
      'Visualização única não é compatível com documento.'
    ]))
  })

  it('cria chaves de submissão estáveis e impede trocar família ou canal no reducer', () => {
    const ids = ['idempotency', 'batch']
    expect(createComposerBatchSubmissionKeys(() => ids.shift()!)).toEqual({
      idempotencyKey: 'web-idempotency',
      clientBatchId: 'web-batch-batch'
    })
    expect(updateComposerDraft(text(), { body: 'Atualizada' })).toMatchObject({ family: 'TEXT', channel: 'WHATSAPP', body: 'Atualizada' })
    function assertImmutableDraftReducerTypes() {
      // @ts-expect-error channel is immutable within a draft reducer.
      updateComposerDraft(text(), { channel: 'INTERNAL_NOTE' })
      // @ts-expect-error family is immutable within a draft reducer.
      updateComposerDraft(text(), { family: 'POLL' })
    }
    void assertImmutableDraftReducerTypes
  })

  it('serializa notas e conteúdo estruturado em JSON com chave estável', () => {
    const note: ComposerDraft = { channel: 'INTERNAL_NOTE', family: 'TEXT', body: 'Somente equipe', citation: { replyToMessageId: 7 } }
    const poll: ComposerDraft = {
      channel: 'WHATSAPP', family: 'POLL', citation: null,
      poll: { name: 'Escolha', options: ['A', 'B'], selectableOptions: 1 },
      submission: { idempotencyKey: 'idem-poll' }
    }

    expect(serializeComposerDraft(context, note)).toMatchObject({ body: { body: 'Somente equipe', internal_note: true, reply_to_message_id: 7 }, headers: {} })
    expect(serializeComposerDraft(context, poll)).toMatchObject({
      path: '/communication/conversations/3/messages',
      body: { kind: 'POLL', idempotency_key: 'idem-poll', poll: { name: 'Escolha', options: ['A', 'B'], selectable_options: 1 } },
      headers: {}
    })
  })

  it('serializa mídia unitária no endpoint singular com variants, legenda, citação e idempotência', () => {
    const file = new File(['video'], 'video.mp4', { type: 'video/mp4' })
    const draft: ComposerDraft = {
      channel: 'WHATSAPP', family: 'MEDIA_BATCH', citation: { replyToMessageId: 4 },
      items: [{ clientItemId: 'item-1', file, kind: 'VIDEO', caption: 'Legenda', gif: true, ptv: false, viewOnce: true }],
      submission: { idempotencyKey: 'idem-batch', clientBatchId: 'client-batch-1' }
    }
    const request = serializeComposerDraft(context, draft)
    expect(request.path).toBe('/communication/conversations/3/messages')
    expect(request.headers).toEqual({})
    expect(request.body).toBeInstanceOf(FormData)
    const form = request.body as FormData
    expect(form.get('idempotency_key')).toBe('idem-batch')
    expect(form.get('reply_to_message_id')).toBe('4')
    expect(form.get('kind')).toBe('VIDEO')
    expect(form.get('body')).toBe('Legenda')
    expect(form.get('gif')).toBe('1')
    expect(form.get('view_once')).toBe('1')
    expect(form.get('client_batch_id')).toBeNull()
    expect(form.get('file')).toMatchObject({ name: 'video.mp4', type: 'video/mp4' })
  })

  it('mantém duas ou mais mídias no endpoint de lote', () => {
    const file = new File(['image'], 'imagem.png', { type: 'image/png' })
    const draft: ComposerDraft = {
      channel: 'WHATSAPP', family: 'MEDIA_BATCH', citation: null,
      items: [
        { clientItemId: 'item-1', file, kind: 'IMAGE', caption: '', gif: false, ptv: false, viewOnce: false },
        { clientItemId: 'item-2', file, kind: 'IMAGE', caption: 'Outra', gif: false, ptv: false, viewOnce: false }
      ],
      submission: { idempotencyKey: 'idem-batch', clientBatchId: 'client-batch-1' }
    }
    const request = serializeComposerDraft(context, draft)
    expect(request.path).toBe('/communication/conversations/3/message-batches')
    const form = request.body as FormData
    expect(form.get('client_batch_id')).toBe('client-batch-1')
    expect(form.get('items[0][client_item_id]')).toBeNull()
    expect(form.get('items[1][caption]')).toBe('Outra')
  })

  it('serializa contato singular e múltiplos com campos allowlisted distintos', () => {
    const draft: ComposerDraft = {
      channel: 'WHATSAPP', family: 'CONTACTS', citation: null,
      contacts: [{ displayName: 'Ana', vcard: 'BEGIN:VCARD' }],
      submission: { idempotencyKey: 'idem-contact' }
    }
    expect(serializeComposerDraft(context, draft)).toMatchObject({
      body: {
        kind: 'CONTACT',
        idempotency_key: 'idem-contact',
        contact: { display_name: 'Ana', vcard: 'BEGIN:VCARD' }
      }
    })
    expect(serializeComposerDraft(context, {
      ...draft,
      contacts: [
        { displayName: 'Ana', vcard: 'BEGIN:VCARD:ANA' },
        { displayName: 'Bia', vcard: 'BEGIN:VCARD:BIA' }
      ]
    })).toMatchObject({
      body: {
        contacts: [
          { display_name: 'Ana', vcard: 'BEGIN:VCARD:ANA' },
          { display_name: 'Bia', vcard: 'BEGIN:VCARD:BIA' }
        ]
      }
    })
  })

  it('inclui idempotência no FormData de mídia singular', () => {
    const file = new File(['audio'], 'nota.ogg', { type: 'audio/ogg' })
    const draft: ComposerDraft = {
      channel: 'WHATSAPP', family: 'AUDIO', citation: null, file, ptt: true,
      submission: { idempotencyKey: 'idem-audio' }
    }
    const request = serializeComposerDraft(context, draft)
    expect(request.headers).toEqual({})
    expect(request.body).toBeInstanceOf(FormData)
    const form = request.body as FormData
    expect(form.get('idempotency_key')).toBe('idem-audio')
    expect(form.get('kind')).toBe('AUDIO')
    expect(form.get('ptt')).toBe('1')
  })

  it('rejeita evento com timestamp inválido ou fim anterior ao início', () => {
    const base: ComposerDraft = {
      channel: 'WHATSAPP', family: 'EVENT', citation: null,
      event: { title: 'Reunião', startsAt: '2026-08-05T12:00:00Z', endsAt: '2026-08-05T13:00:00Z', timezone: 'America/Sao_Paulo' },
      submission: { idempotencyKey: 'idem-event' }
    }
    expect(validateComposerDraft({ ...base, event: { ...base.event, startsAt: 'invalid' } })).not.toEqual([])
    expect(validateComposerDraft({ ...base, event: { ...base.event, endsAt: '2026-08-05T11:00:00Z' } })).not.toEqual([])
    expect(validateComposerDraft({ ...base, event: { ...base.event, endsAt: base.event.startsAt } })).toEqual([])
  })

  it('preserva exatamente chave, blob e estrutura no store após falha recuperável', () => {
    const store = createCommunicationComposerDraftStore()
    const file = new File(['same-bytes'], 'same.png', { type: 'image/png' })
    const draft: ComposerDraft = {
      channel: 'WHATSAPP',
      family: 'MEDIA_BATCH',
      citation: { replyToMessageId: 88 },
      items: [{
        clientItemId: 'same-item',
        file,
        kind: 'IMAGE',
        caption: 'Mesma legenda',
        gif: false,
        ptv: false,
        viewOnce: true
      }],
      sensitiveConfirmed: true,
      submission: {
        idempotencyKey: 'same-idempotency',
        clientBatchId: 'same-batch'
      }
    }

    store.set(context, draft)
    const restored = store.get(context, 'WHATSAPP')

    expect(restored).toBe(draft)
    expect(restored?.family === 'MEDIA_BATCH' && restored.items[0]?.file).toBe(file)
    expect(composerDraftHasBinary(restored)).toBe(true)
    expect(composerDraftBinaryBytes(restored)).toBe(file.size)
    expect(composerSessionBinaryBytes(store.all())).toBe(file.size)
    expect(COMPOSER_SESSION_BINARY_BUDGET_BYTES).toBeGreaterThan(file.size)
  })

  it('mantém binários somente na sessão viva e nunca em armazenamento persistente do browser', () => {
    const storeSource = readFileSync(resolve(process.cwd(), 'app/composables/useCommunicationComposerDrafts.ts'), 'utf8')
    const composer = readFileSync(resolve(process.cwd(), 'app/components/communication/Composer.vue'), 'utf8')
    expect(storeSource).not.toMatch(/localStorage|sessionStorage/)
    expect(composer).toContain('beforeunload')
    expect(composer).toContain('onBeforeRouteLeave')
    expect(composer).toContain('COMPOSER_SESSION_BINARY_BUDGET_BYTES')
    expect(composer).toContain('revokeComposerMediaPreviewUrls')
  })

  it('exige confirmação explícita de visualização única e limites atuais', () => {
    const file = new File(['image'], 'image.png', { type: 'image/png' })
    const media: ComposerDraft = {
      channel: 'WHATSAPP',
      family: 'MEDIA_BATCH',
      citation: null,
      items: [{
        clientItemId: 'one',
        file,
        kind: 'IMAGE',
        caption: '',
        gif: false,
        ptv: false,
        viewOnce: true
      }],
      sensitiveConfirmed: false,
      submission: { idempotencyKey: 'idem-media', clientBatchId: 'batch-media' }
    }
    const contacts: ComposerDraft = {
      channel: 'WHATSAPP',
      family: 'CONTACTS',
      citation: null,
      contacts: [
        { displayName: 'Ana', vcard: 'ANA' },
        { displayName: 'Bia', vcard: 'BIA' }
      ],
      submission: { idempotencyKey: 'idem-contacts' }
    }

    expect(validateComposerDraft(media, { requireSensitiveConfirmation: true }))
      .toContainEqual(expect.objectContaining({ path: 'sensitiveConfirmed' }))
    expect(validateComposerDraft(contacts, { maxContacts: 1 }))
      .toContainEqual(expect.objectContaining({ path: 'contacts' }))
  })

  it('serializa evento e interativo nos nomes públicos da API', () => {
    const event: ComposerDraft = {
      channel: 'WHATSAPP',
      family: 'EVENT',
      citation: null,
      event: {
        title: 'Fechamento',
        startsAt: '2026-08-05T12:00:00Z',
        endsAt: '2026-08-05T13:00:00Z',
        timezone: 'America/Sao_Paulo',
        location: 'Sala 2'
      },
      submission: { idempotencyKey: 'idem-event' }
    }
    const interactive: ComposerDraft = {
      channel: 'WHATSAPP',
      family: 'INTERACTIVE',
      citation: null,
      interactive: {
        type: 'BUTTONS',
        title: 'Escolha',
        body: 'Selecione uma opção',
        actions: [{ id: 'yes', title: 'Sim' }]
      },
      submission: { idempotencyKey: 'idem-interactive' }
    }

    expect(serializeComposerDraft(context, event)).toMatchObject({
      body: {
        event: {
          start_at: '2026-08-05T12:00:00+00:00',
          end_at: '2026-08-05T13:00:00+00:00',
          location_name: 'Sala 2'
        }
      }
    })
    expect(serializeComposerDraft(context, interactive)).toMatchObject({
      body: {
        interactive: {
          mode: 'BUTTONS',
          title: 'Escolha',
          description: 'Selecione uma opção',
          options: ['Sim']
        }
      }
    })
  })
})

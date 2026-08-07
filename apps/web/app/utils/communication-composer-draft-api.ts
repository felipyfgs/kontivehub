import type { ComposerDraft, ComposerDraftContext, ComposerWhatsappDraft } from '~/types/communication/composer-draft'

export interface ComposerDraftApiRequest {
  path: string
  body: Record<string, unknown> | FormData
  headers: Record<string, string>
}

function singularPath(context: ComposerDraftContext) {
  return `/communication/conversations/${context.conversationId}/messages`
}

function appendCitation(body: FormData, draft: ComposerWhatsappDraft) {
  if (draft.citation) body.set('reply_to_message_id', String(draft.citation.replyToMessageId))
}

function eventInstant(value: string): string {
  const instant = new Date(value)
  if (!Number.isFinite(instant.getTime())) return value
  return instant.toISOString().replace(/\.\d{3}Z$/, '+00:00')
}

export function serializeComposerDraft(context: ComposerDraftContext, draft: ComposerDraft): ComposerDraftApiRequest {
  if (draft.channel === 'INTERNAL_NOTE') {
    return { path: singularPath(context), body: { body: draft.body, internal_note: true, ...(draft.citation && { reply_to_message_id: draft.citation.replyToMessageId }) }, headers: {} }
  }
  if (draft.family === 'MEDIA_BATCH') {
    if (draft.items.length === 1) {
      const item = draft.items[0]
      if (!item) throw new Error('Rascunho de mídia sem item para envio.')
      const body = new FormData()
      body.set('idempotency_key', draft.submission.idempotencyKey)
      body.set('kind', item.kind)
      body.set('body', item.caption)
      if (item.gif) body.set('gif', '1')
      if (item.ptv) body.set('ptv', '1')
      if (item.viewOnce) body.set('view_once', '1')
      body.set('file', item.file, item.file.name)
      appendCitation(body, draft)
      return { path: singularPath(context), body, headers: {} }
    }
    const body = new FormData()
    body.set('idempotency_key', draft.submission.idempotencyKey)
    body.set('client_batch_id', draft.submission.clientBatchId)
    if (draft.citation) body.set('reply_to_message_id', String(draft.citation.replyToMessageId))
    draft.items.forEach((item, index) => {
      const prefix = `items[${index}]`
      body.set(`${prefix}[kind]`, item.kind)
      body.set(`${prefix}[caption]`, item.caption)
      if (item.gif) body.set(`${prefix}[gif]`, '1')
      if (item.ptv) body.set(`${prefix}[ptv]`, '1')
      if (item.viewOnce) body.set(`${prefix}[view_once]`, '1')
      body.set(`${prefix}[file]`, item.file, item.file.name)
    })
    return { path: singularPath(context).replace(/\/messages$/, '/message-batches'), body, headers: {} }
  }
  if (draft.family === 'AUDIO' || draft.family === 'STICKER') {
    const body = new FormData()
    body.set('idempotency_key', draft.submission.idempotencyKey)
    body.set('kind', draft.family)
    if (draft.family === 'AUDIO' && draft.ptt) body.set('ptt', '1')
    if (draft.family === 'STICKER' && draft.libraryStickerId) {
      body.set('library_sticker_id', draft.libraryStickerId)
    } else if (draft.file) {
      body.set('file', draft.file, draft.file.name)
    } else {
      throw new Error('Rascunho de figurinha sem arquivo nem biblioteca.')
    }
    appendCitation(body, draft)
    return { path: singularPath(context), body, headers: {} }
  }
  const body: Record<string, unknown> = {
    kind: draft.family === 'CONTACTS' ? 'CONTACT' : draft.family,
    idempotency_key: draft.submission.idempotencyKey,
    ...(draft.citation && { reply_to_message_id: draft.citation.replyToMessageId })
  }
  if (draft.family === 'TEXT') body.body = draft.body
  if (draft.family === 'LOCATION') body.location = draft.location
  if (draft.family === 'CONTACTS') {
    const contacts = draft.contacts.map(contact => ({
      display_name: contact.displayName,
      vcard: contact.vcard
    }))
    if (contacts.length === 1) body.contact = contacts[0]
    else body.contacts = contacts
  }
  if (draft.family === 'POLL') body.poll = { name: draft.poll.name, options: draft.poll.options, selectable_options: draft.poll.selectableOptions }
  if (draft.family === 'EVENT') {
    body.event = {
      title: draft.event.title,
      description: draft.event.description,
      start_at: eventInstant(draft.event.startsAt),
      end_at: eventInstant(draft.event.endsAt),
      timezone: draft.event.timezone,
      location_name: draft.event.location
    }
  }
  if (draft.family === 'INTERACTIVE') {
    body.interactive = {
      mode: draft.interactive.type,
      title: draft.interactive.title,
      description: draft.interactive.body,
      options: draft.interactive.actions.map(action => action.title)
    }
  }
  return { path: singularPath(context), body, headers: {} }
}

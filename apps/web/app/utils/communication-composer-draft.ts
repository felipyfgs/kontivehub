import type {
  ComposerDraft,
  ComposerDraftContext,
  ComposerBatchSubmissionKeys,
  ComposerSubmissionKeys
} from '~/types/communication/composer-draft'

export interface ComposerDraftValidationError {
  path: string
  message: string
}

export interface ComposerDraftValidationLimits {
  maxContacts?: number | null
  maxMediaItems?: number | null
  requireSensitiveConfirmation?: boolean
}

export function composerDraftContextKey(context: ComposerDraftContext): string {
  return `${context.tenantId}:${context.inboxId}:${context.conversationId}`
}

export function createComposerSubmissionKeys(createId: () => string = () => crypto.randomUUID()): ComposerSubmissionKeys {
  return { idempotencyKey: `web-${createId()}` }
}

export function createComposerBatchSubmissionKeys(createId: () => string = () => crypto.randomUUID()): ComposerBatchSubmissionKeys {
  const idempotencyKey = `web-${createId()}`
  return { idempotencyKey, clientBatchId: `web-batch-${createId()}` }
}

export function updateComposerDraft<T extends ComposerDraft>(
  draft: T,
  update: Omit<Partial<T>, 'channel' | 'family'>
): T {
  return { ...draft, ...update }
}

export function removeComposerMediaItem(draft: ComposerDraft, clientItemId: string): ComposerDraft {
  if (draft.channel !== 'WHATSAPP' || draft.family !== 'MEDIA_BATCH') return draft
  return { ...draft, items: draft.items.filter(item => item.clientItemId !== clientItemId) }
}

export function composerDraftHasBinary(draft: ComposerDraft | null): boolean {
  if (!draft || draft.channel === 'INTERNAL_NOTE') return false
  return draft.family === 'MEDIA_BATCH' || draft.family === 'AUDIO' || draft.family === 'STICKER'
}

/** Soft ceiling for in-memory File blobs across every open conversation draft. */
export const COMPOSER_SESSION_BINARY_BUDGET_BYTES = 64 * 1024 * 1024

export function composerDraftBinaryBytes(draft: ComposerDraft | null): number {
  if (!draft || draft.channel === 'INTERNAL_NOTE') return 0
  if (draft.family === 'MEDIA_BATCH') {
    return draft.items.reduce((total, item) => total + item.file.size, 0)
  }
  if (draft.family === 'AUDIO' || draft.family === 'STICKER') return draft.file?.size ?? 0
  return 0
}

export function composerSessionBinaryBytes(
  drafts: ReadonlyMap<string, { whatsapp: ComposerDraft | null, internalNote: ComposerDraft | null }>
): number {
  let total = 0
  for (const slot of drafts.values()) {
    total += composerDraftBinaryBytes(slot.whatsapp)
  }
  return total
}

export function validateComposerDraft(
  draft: ComposerDraft,
  limits: ComposerDraftValidationLimits = {}
): ComposerDraftValidationError[] {
  const errors: ComposerDraftValidationError[] = []
  if (draft.family === 'TEXT' && !draft.body.trim()) {
    errors.push({ path: 'body', message: 'Informe uma mensagem.' })
  }
  if (draft.channel === 'INTERNAL_NOTE') return errors

  if (!draft.submission.idempotencyKey) {
    errors.push({
      path: 'submission.idempotencyKey',
      message: 'A chave de idempotência é obrigatória.'
    })
  }
  switch (draft.family) {
    case 'MEDIA_BATCH':
      if (!draft.items.length) {
        errors.push({ path: 'items', message: 'Selecione ao menos um arquivo.' })
      }
      if (limits.maxMediaItems && draft.items.length > limits.maxMediaItems) {
        errors.push({
          path: 'items',
          message: `Selecione no máximo ${limits.maxMediaItems} arquivos.`
        })
      }
      if (!draft.submission.clientBatchId) {
        errors.push({
          path: 'submission.clientBatchId',
          message: 'O identificador do lote é obrigatório.'
        })
      }
      if (limits.requireSensitiveConfirmation
        && draft.items.some(item => item.viewOnce)
        && !draft.sensitiveConfirmed) {
        errors.push({
          path: 'sensitiveConfirmed',
          message: 'Confirme o destino e a visualização única antes de enviar.'
        })
      }
      draft.items.forEach((item, index) => {
        if ((item.gif || item.ptv) && item.kind !== 'VIDEO') {
          errors.push({
            path: `items.${index}`,
            message: 'GIF e vídeo circular exigem vídeo.'
          })
        }
        if (item.viewOnce && item.kind === 'DOCUMENT') {
          errors.push({
            path: `items.${index}`,
            message: 'Visualização única não é compatível com documento.'
          })
        }
      })
      break
    case 'AUDIO':
      if (!draft.file) errors.push({ path: 'file', message: 'Informe o áudio.' })
      break
    case 'STICKER':
      if (!draft.file && !draft.libraryStickerId) {
        errors.push({ path: 'file', message: 'Informe a figurinha.' })
      }
      break
    case 'LOCATION':
      if (!Number.isFinite(draft.location.latitude)
        || draft.location.latitude < -90
        || draft.location.latitude > 90
        || !Number.isFinite(draft.location.longitude)
        || draft.location.longitude < -180
        || draft.location.longitude > 180) {
        errors.push({ path: 'location', message: 'Informe uma localização válida.' })
      }
      break
    case 'CONTACTS':
      if (!draft.contacts.length) {
        errors.push({ path: 'contacts', message: 'Informe ao menos um contato.' })
      }
      if (limits.maxContacts && draft.contacts.length > limits.maxContacts) {
        errors.push({
          path: 'contacts',
          message: `Selecione no máximo ${limits.maxContacts} contatos.`
        })
      }
      break
    case 'POLL': {
      const options = draft.poll.options.map(value => value.trim()).filter(Boolean)
      if (!draft.poll.name.trim()) {
        errors.push({ path: 'poll.name', message: 'Informe a pergunta.' })
      }
      if (options.length < 2
        || new Set(options.map(value => value.toLocaleLowerCase())).size !== options.length) {
        errors.push({
          path: 'poll.options',
          message: 'Informe ao menos duas opções distintas.'
        })
      }
      if (draft.poll.selectableOptions < 1
        || draft.poll.selectableOptions > options.length) {
        errors.push({
          path: 'poll.selectableOptions',
          message: 'Informe um limite de seleção válido.'
        })
      }
      break
    }
    case 'EVENT': {
      const startsAt = Date.parse(draft.event.startsAt)
      const endsAt = Date.parse(draft.event.endsAt)
      if (!draft.event.title.trim() || !Number.isFinite(startsAt) || !Number.isFinite(endsAt) || endsAt < startsAt) {
        errors.push({ path: 'event', message: 'Informe um evento válido.' })
      }
      break
    }
    case 'INTERACTIVE':
      if (!draft.interactive.title.trim()
        || !draft.interactive.body.trim()
        || !draft.interactive.actions.length) {
        errors.push({
          path: 'interactive.actions',
          message: 'Informe título, mensagem e ao menos uma ação.'
        })
      }
      break
  }
  return errors
}

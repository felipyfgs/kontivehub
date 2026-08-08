import type { ComposerDraft, ComposerDraftContext } from '~/types/communication/composer-draft'
import { readonly, shallowRef } from 'vue'
import { composerDraftContextKey } from '~/utils/communication-composer-draft'

export function createCommunicationComposerDraftStore() {
  const drafts = shallowRef(new Map<string, { whatsapp: ComposerDraft | null, internalNote: ComposerDraft | null }>())
  const slot = (context: ComposerDraftContext) => drafts.value.get(composerDraftContextKey(context)) ?? { whatsapp: null, internalNote: null }

  function get(context: ComposerDraftContext, channel: 'WHATSAPP' | 'INTERNAL_NOTE') {
    const current = slot(context)
    return channel === 'WHATSAPP' ? current.whatsapp : current.internalNote
  }
  function set(context: ComposerDraftContext, draft: ComposerDraft | null) {
    const key = composerDraftContextKey(context)
    const current = slot(context)
    drafts.value = new Map(drafts.value).set(key, draft?.channel === 'INTERNAL_NOTE'
      ? { ...current, internalNote: draft }
      : { ...current, whatsapp: draft })
  }
  function clear(context: ComposerDraftContext, channel: 'WHATSAPP' | 'INTERNAL_NOTE') {
    const current = slot(context)
    drafts.value = new Map(drafts.value).set(composerDraftContextKey(context), channel === 'WHATSAPP'
      ? { ...current, whatsapp: null }
      : { ...current, internalNote: null })
  }
  function all() {
    return drafts.value as ReadonlyMap<string, {
      whatsapp: ComposerDraft | null
      internalNote: ComposerDraft | null
    }>
  }
  return { drafts: readonly(drafts), all, get, set, clear }
}

export function useCommunicationComposerDrafts() {
  const state = useState('communication-composer-drafts', createCommunicationComposerDraftStore)
  return state.value
}

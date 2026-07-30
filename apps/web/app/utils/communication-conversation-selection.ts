import type {
  CommunicationBulkAction,
  CommunicationBulkOperationSubmitItem,
  CommunicationConversation
} from '~/types/communication'

/**
 * Contexto de consulta que limpa a seleção operacional.
 * Paginação não entra: carregar mais não reseta seleção.
 */
export interface CommunicationSelectionQueryContext {
  q: string
  inboxId: number | null
  status: string | null
  assigneeMembershipId: number | null
  workDepartmentId: number | null
  unassignedOnly: boolean
  unreadOnly: boolean
  labelIds: number[]
  contactId: number | null
  sortBy: string
}

export function communicationSelectionQueryKey(
  context: CommunicationSelectionQueryContext
): string {
  return JSON.stringify([
    context.q.trim(),
    context.inboxId,
    context.status || 'ALL',
    context.assigneeMembershipId,
    context.workDepartmentId,
    context.unassignedOnly,
    context.unreadOnly,
    [...context.labelIds].sort((a, b) => a - b),
    context.contactId,
    context.sortBy
  ])
}

/** Substitui a seleção pelos IDs atualmente carregados. */
export function selectLoadedConversationIds(
  conversations: readonly CommunicationConversation[]
): Set<number> {
  return new Set(conversations.map(item => item.id))
}

/** Marca/desmarca um ID individual sem afetar o detalhe aberto. */
export function toggleConversationSelection(
  current: ReadonlySet<number>,
  conversationId: number,
  selected: boolean
): Set<number> {
  const next = new Set(current)
  if (selected) next.add(conversationId)
  else next.delete(conversationId)
  return next
}

/**
 * Preserva apenas IDs ainda presentes na coleção carregada
 * (refresh/realtime/load more). Não auto-seleciona novos IDs.
 */
export function pruneConversationSelection(
  current: ReadonlySet<number>,
  conversations: readonly CommunicationConversation[]
): Set<number> {
  if (current.size === 0) return new Set()
  const loaded = new Set(conversations.map(item => item.id))
  const next = new Set<number>()
  for (const id of current) {
    if (loaded.has(id)) next.add(id)
  }
  return next
}

export function conversationSelectionState(
  selected: ReadonlySet<number>,
  conversations: readonly CommunicationConversation[]
): {
  selectedCount: number
  allLoadedSelected: boolean
  indeterminate: boolean
} {
  const loadedCount = conversations.length
  const selectedCount = selected.size
  if (loadedCount === 0 || selectedCount === 0) {
    return {
      selectedCount,
      allLoadedSelected: false,
      indeterminate: false
    }
  }
  const loadedIds = conversations.map(item => item.id)
  const selectedLoaded = loadedIds.filter(id => selected.has(id)).length
  const allLoadedSelected = selectedLoaded === loadedCount && loadedCount > 0
  return {
    selectedCount,
    allLoadedSelected,
    indeterminate: selectedLoaded > 0 && !allLoadedSelected
  }
}

function resolveThroughMessageId(
  conversation: CommunicationConversation
): number | null {
  const messages = conversation.messages
  if (Array.isArray(messages) && messages.length > 0) {
    return Math.max(...messages.map(message => message.id))
  }
  return conversation.last_message?.id ?? null
}

/**
 * Materializa items[] com snapshots exigidos pela ação bulk.
 * Itens sem snapshot obrigatório são omitidos (fail-closed no cliente).
 */
export function buildConversationBulkItems(
  conversations: readonly CommunicationConversation[],
  selectedIds: ReadonlySet<number>,
  action: CommunicationBulkAction
): CommunicationBulkOperationSubmitItem[] {
  const items: CommunicationBulkOperationSubmitItem[] = []
  for (const conversation of conversations) {
    if (!selectedIds.has(conversation.id)) continue
    const base: CommunicationBulkOperationSubmitItem = {
      conversation_id: conversation.id
    }
    if (
      action === 'SET_STATUS'
      || action === 'SET_ASSIGNEE'
      || action === 'SET_DEPARTMENT'
    ) {
      if (conversation.lock_version == null) continue
      base.lock_version = conversation.lock_version
    }
    if (action === 'MARK_READ') {
      const through = resolveThroughMessageId(conversation)
      if (through == null) continue
      base.through_message_id = through
    }
    if (action === 'MARK_UNREAD') {
      base.read_state_version = conversation.read_state?.version ?? 0
    }
    items.push(base)
  }
  return items
}

/** Concatena páginas preservando a ordem autoritativa da API. */
export function mergeConversationListInApiOrder(
  current: CommunicationConversation[],
  incoming: CommunicationConversation[],
  append: boolean
): CommunicationConversation[] {
  if (!append) {
    return incoming.slice()
  }
  const byId = new Map(current.map(item => [item.id, item]))
  const result = current.map((item) => {
    const next = incoming.find(candidate => candidate.id === item.id)
    return next ?? item
  })
  for (const item of incoming) {
    if (byId.has(item.id)) continue
    result.push(item)
    byId.set(item.id, item)
  }
  return result
}

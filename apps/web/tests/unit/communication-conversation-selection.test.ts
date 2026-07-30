import { describe, expect, it } from 'vitest'
import type { CommunicationConversation } from '~/types/communication'
import {
  buildConversationBulkItems,
  communicationSelectionQueryKey,
  conversationSelectionState,
  pruneConversationSelection,
  selectLoadedConversationIds,
  toggleConversationSelection
} from '~/utils/communication-conversation-selection'

function conversation(
  id: number,
  patch: Partial<CommunicationConversation> = {}
): CommunicationConversation {
  return {
    id,
    inbox_id: 1,
    status: 'OPEN',
    priority: 0,
    lock_version: 3,
    unread_count: 0,
    last_message: {
      id: id * 10,
      conversation_id: id,
      direction: 'INBOUND',
      kind: 'TEXT',
      source: 'HUMAN',
      status: 'DELIVERED'
    },
    read_state: {
      version: 2,
      last_read_through_message_id: id * 10
    },
    ...patch
  }
}

describe('seleção operacional da lista de conversas', () => {
  it('distingue seleção vazia, parcial e total inclusive nos limites', () => {
    const oneLoaded = [conversation(1)]
    const threeLoaded = [conversation(1), conversation(2), conversation(3)]

    expect(conversationSelectionState(new Set(), oneLoaded)).toEqual({
      selectedCount: 0,
      allLoadedSelected: false,
      indeterminate: false
    })
    expect(conversationSelectionState(new Set([1]), oneLoaded)).toEqual({
      selectedCount: 1,
      allLoadedSelected: true,
      indeterminate: false
    })
    expect(conversationSelectionState(new Set([1, 2]), threeLoaded)).toEqual({
      selectedCount: 2,
      allLoadedSelected: false,
      indeterminate: true
    })
    expect(conversationSelectionState(new Set([1, 2, 3]), threeLoaded)).toEqual({
      selectedCount: 3,
      allLoadedSelected: true,
      indeterminate: false
    })
    expect(conversationSelectionState(new Set(), [])).toEqual({
      selectedCount: 0,
      allLoadedSelected: false,
      indeterminate: false
    })
    expect(conversationSelectionState(new Set([9]), threeLoaded)).toEqual({
      selectedCount: 1,
      allLoadedSelected: false,
      indeterminate: false
    })
  })

  it('seleciona individualmente e todas as carregadas', () => {
    const loaded = [conversation(1), conversation(2), conversation(3)]
    let selected = new Set<number>()

    selected = toggleConversationSelection(selected, 2, true)
    expect([...selected]).toEqual([2])

    selected = selectLoadedConversationIds(loaded)
    expect([...selected].sort((a, b) => a - b)).toEqual([1, 2, 3])

    const state = conversationSelectionState(selected, loaded)
    expect(state.allLoadedSelected).toBe(true)
    expect(state.indeterminate).toBe(false)
    expect(state.selectedCount).toBe(3)
  })

  it('não auto-seleciona IDs de uma nova página', () => {
    const firstPage = [conversation(1), conversation(2)]
    let selected = selectLoadedConversationIds(firstPage)
    expect(selected.size).toBe(2)

    const afterLoadMore = [...firstPage, conversation(3), conversation(4)]
    selected = pruneConversationSelection(selected, afterLoadMore)

    expect([...selected].sort((a, b) => a - b)).toEqual([1, 2])
    expect(selected.has(3)).toBe(false)
    expect(selected.has(4)).toBe(false)

    const state = conversationSelectionState(selected, afterLoadMore)
    expect(state.allLoadedSelected).toBe(false)
    expect(state.indeterminate).toBe(true)
  })

  it('limpa seleção quando o contexto de consulta muda', () => {
    const base = {
      q: '',
      inboxId: null as number | null,
      status: 'OPEN' as string | null,
      assigneeMembershipId: null as number | null,
      workDepartmentId: null as number | null,
      unassignedOnly: false,
      unreadOnly: false,
      labelIds: [] as number[],
      contactId: null as number | null,
      sortBy: 'last_activity_desc'
    }
    const openKey = communicationSelectionQueryKey(base)
    const unreadKey = communicationSelectionQueryKey({ ...base, unreadOnly: true })
    const sortKey = communicationSelectionQueryKey({ ...base, sortBy: 'priority_desc' })
    const labelKey = communicationSelectionQueryKey({ ...base, labelIds: [9, 2] })
    const labelKeySame = communicationSelectionQueryKey({ ...base, labelIds: [2, 9] })
    const contactKey = communicationSelectionQueryKey({ ...base, contactId: 42 })

    expect(openKey).not.toBe(unreadKey)
    expect(openKey).not.toBe(sortKey)
    expect(openKey).not.toBe(labelKey)
    expect(openKey).not.toBe(contactKey)
    expect(labelKey).toBe(labelKeySame)
  })

  it('materializa items bulk com snapshots por ação', () => {
    const loaded = [
      conversation(1),
      conversation(2, { lock_version: 5, unread_count: 2 }),
      conversation(3, {
        last_message: undefined,
        first_unread_message_id: null,
        read_state: { version: 0, last_read_through_message_id: null }
      })
    ]
    const selected = new Set([1, 2, 3])

    const triage = buildConversationBulkItems(loaded, selected, 'SET_STATUS')
    expect(triage).toEqual([
      { conversation_id: 1, lock_version: 3 },
      { conversation_id: 2, lock_version: 5 },
      { conversation_id: 3, lock_version: 3 }
    ])

    const read = buildConversationBulkItems(loaded, selected, 'MARK_READ')
    expect(read).toEqual([
      { conversation_id: 1, through_message_id: 10 },
      { conversation_id: 2, through_message_id: 20 }
    ])

    const unread = buildConversationBulkItems(loaded, selected, 'MARK_UNREAD')
    expect(unread).toEqual([
      { conversation_id: 1, read_state_version: 2 },
      { conversation_id: 2, read_state_version: 2 },
      { conversation_id: 3, read_state_version: 0 }
    ])

    const labels = buildConversationBulkItems(loaded, new Set([2]), 'ADD_LABELS')
    expect(labels).toEqual([{ conversation_id: 2 }])
  })

  it('remove da seleção IDs que saíram da coleção no refresh', () => {
    const selected = new Set([1, 2, 99])
    const pruned = pruneConversationSelection(selected, [conversation(1), conversation(3)])
    expect([...pruned]).toEqual([1])
  })
})

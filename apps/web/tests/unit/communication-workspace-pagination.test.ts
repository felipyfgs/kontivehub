import { describe, expect, it } from 'vitest'
import type { Conversation } from '~/types/communication/conversations'
import {
  communicationConversationListQueryKey,
  isConversationRequestCurrent,
  mergeCommunicationConversationPage
} from '~/composables/useCommunicationWorkspace'

function conversation(id: number, priority = 0): Conversation {
  return {
    id,
    inbox_id: 1,
    status: 'OPEN',
    priority,
    lock_version: 1,
    last_message_at: `2026-07-23T12:${String(id).padStart(2, '0')}:00-03:00`
  }
}

describe('paginação do workspace de comunicação', () => {
  it('substitui a página inicial e concatena próximas páginas sem ids duplicados', () => {
    const stale = [conversation(99)]
    const firstPage = mergeCommunicationConversationPage(
      stale,
      [conversation(2), conversation(1)],
      false
    )
    expect(firstPage.map(item => item.id)).toEqual([2, 1])

    const secondPage = mergeCommunicationConversationPage(
      firstPage,
      [conversation(3), conversation(2, 4)],
      true
    )
    // Ordem autoritativa da API: preserva página atual e anexa novos IDs.
    expect(secondPage.map(item => item.id)).toEqual([2, 1, 3])
    expect(secondPage.filter(item => item.id === 2)).toHaveLength(1)
    expect(secondPage.find(item => item.id === 2)?.priority).toBe(4)
  })

  it('descarta resposta fora de ordem por geração e por troca de Tenant', () => {
    const active = { generation: 8, sessionEpoch: 12 }

    expect(isConversationRequestCurrent(active, active)).toBe(true)
    expect(isConversationRequestCurrent(
      { generation: 7, sessionEpoch: 12 },
      active
    )).toBe(false)
    expect(isConversationRequestCurrent(
      { generation: 8, sessionEpoch: 11 },
      active
    )).toBe(false)
  })

  it('vincula snapshot às dimensões da consulta e ignora somente paginação e token', () => {
    const base = {
      q: 'fiscal',
      status: 'OPEN' as const,
      unread: true,
      label_ids: [9, 3, 9],
      sort_by: 'last_activity_desc' as const,
      per_page: 50
    }
    const first = communicationConversationListQueryKey({
      ...base,
      page: 1,
      snapshot: true
    })
    const next = communicationConversationListQueryKey({
      ...base,
      label_ids: [3, 9],
      page: 4,
      snapshot_token: 'opaco'
    })

    expect(next).toBe(first)
    for (const changed of [
      { ...base, q: 'outro' },
      { ...base, inbox_id: 7 },
      { ...base, status: 'PENDING' as const },
      { ...base, assignee_membership_id: 4 },
      { ...base, work_department_id: 3 },
      { ...base, unassigned: true },
      { ...base, unread: false },
      { ...base, label_ids: [3] },
      { ...base, contact_id: 42 },
      { ...base, sort_by: 'priority_desc' as const },
      { ...base, per_page: 100 }
    ]) {
      expect(communicationConversationListQueryKey(changed)).not.toBe(first)
    }
  })
})

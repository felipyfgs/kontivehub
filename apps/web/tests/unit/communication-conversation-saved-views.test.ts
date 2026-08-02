import { describe, expect, it } from 'vitest'
import {
  asConversationSavedViewPayload,
  buildConversationSavedViewPayload,
  conversationSavedViewUnavailableReason
} from '~/utils/communication-conversation-saved-views'

describe('visões salvas de conversas', () => {
  it('serializa somente filtros autoritativos e resolve a exclusão entre responsável e não atribuídas', () => {
    expect(buildConversationSavedViewPayload({
      status: 'OPEN',
      sortBy: 'priority_desc',
      inboxId: null,
      assigneeMembershipId: 7,
      workDepartmentId: 3,
      labelIds: [9, 9, 10],
      unread: false,
      unassigned: true
    })).toEqual({
      status: 'OPEN',
      sort_by: 'priority_desc',
      work_department_id: 3,
      label_ids: [9, 10],
      unassigned: true
    })
  })

  it('rejeita payloads com busca, contato ou forma inválida', () => {
    expect(asConversationSavedViewPayload({
      status: 'OPEN',
      sort_by: 'last_activity_desc',
      q: 'não permitido'
    })).toBeNull()
    expect(asConversationSavedViewPayload({
      status: 'OPEN',
      sort_by: 'last_activity_desc',
      contact_id: 42
    })).toBeNull()
    expect(asConversationSavedViewPayload({
      status: 'OPEN',
      sort_by: 'last_activity_desc',
      label_ids: [0]
    })).toBeNull()
  })

  it('bloqueia referências obsoletas sem remover silenciosamente a restrição', () => {
    const payload = {
      status: 'PENDING',
      sort_by: 'last_activity_desc',
      assignee_membership_id: 99
    }
    const catalogs = {
      inboxIds: [1],
      assigneeMembershipIds: [7],
      workDepartmentIds: [3],
      labelIds: [9]
    }

    expect(conversationSavedViewUnavailableReason(payload, catalogs))
      .toContain('responsável salvo')
    expect(conversationSavedViewUnavailableReason({
      ...payload,
      assignee_membership_id: 7
    }, catalogs)).toBeNull()
  })
})

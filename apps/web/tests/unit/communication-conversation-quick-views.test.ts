import { describe, expect, it } from 'vitest'
import {
  activeCommunicationQuickView,
  COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS,
  communicationQuickViewState
} from '../../app/utils/communication-conversation-quick-views'

describe('visões rápidas das conversas', () => {
  it('mantém três presets fixos, ordenados e sem contagens inventadas', () => {
    expect(COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS).toEqual([
      { label: 'Em aberto', value: 'OPEN' },
      { label: 'Não lidas', value: 'UNREAD' },
      { label: 'Não atribuídas', compactLabel: 'Não atrib.', value: 'UNASSIGNED' }
    ])
  })

  it('aplica somente status, leitura e atribuição do preset escolhido', () => {
    expect(communicationQuickViewState('OPEN')).toEqual({
      status: 'OPEN',
      unreadOnly: false,
      unassignedOnly: false
    })
    expect(communicationQuickViewState('UNREAD')).toEqual({
      status: 'OPEN',
      unreadOnly: true,
      unassignedOnly: false
    })
    expect(communicationQuickViewState('UNASSIGNED')).toEqual({
      status: 'OPEN',
      unreadOnly: false,
      unassignedOnly: true
    })
  })

  it('não marca tab em estados compostos ou status fora dos presets', () => {
    expect(activeCommunicationQuickView({
      status: 'PENDING',
      unreadOnly: false,
      unassignedOnly: false
    })).toBeNull()
    expect(activeCommunicationQuickView({
      status: 'OPEN',
      unreadOnly: true,
      unassignedOnly: true
    })).toBeNull()
  })
})

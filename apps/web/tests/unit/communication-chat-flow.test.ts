import { describe, expect, it } from 'vitest'
import type { CommunicationMessage } from '~/types/communication'
import {
  COMMUNICATION_TIMELINE_BOTTOM_THRESHOLD,
  appendedCommunicationMessages,
  canRetryCommunicationReadAcknowledgement,
  communicationNewMessagesLabel,
  communicationUserScrollBehavior,
  isCommunicationReadStateVersionNewer,
  isCommunicationTimelineNearBottom,
  mergeCommunicationReadThroughMessageId,
  shouldFollowCommunicationTimeline,
  shouldMarkCommunicationTimelineRead
} from '~/utils/communication-timeline'

function message(id: number, direction: CommunicationMessage['direction']): CommunicationMessage {
  return {
    id,
    conversation_id: 10,
    direction,
    kind: direction === 'INTERNAL' ? 'NOTE' : 'TEXT',
    source: 'HUMAN',
    status: 'SENT',
    body: `Mensagem ${id}`,
    occurred_at: `2026-07-22T12:${String(id).padStart(2, '0')}:00Z`
  }
}

describe('fluxo natural da timeline de comunicação', () => {
  it('considera o viewport próximo do fim dentro do limiar inclusivo', () => {
    expect(isCommunicationTimelineNearBottom({
      scrollTop: 804,
      clientHeight: 600,
      scrollHeight: 1_500
    })).toBe(true)
    expect(isCommunicationTimelineNearBottom({
      scrollTop: 803,
      clientHeight: 600,
      scrollHeight: 1_500
    })).toBe(false)
    expect(COMMUNICATION_TIMELINE_BOTTOM_THRESHOLD).toBe(96)
  })

  it('distingue mensagens anexadas de refresh que atualiza itens existentes', () => {
    const current = [message(1, 'INBOUND'), message(2, 'OUTBOUND'), message(3, 'INBOUND')]
    expect(appendedCommunicationMessages([1, 2], current).map(item => item.id)).toEqual([3])
    expect(appendedCommunicationMessages([1, 2, 3], current)).toEqual([])
  })

  it('acompanha troca de conversa, proximidade do fim e envio próprio', () => {
    expect(shouldFollowCommunicationTimeline({
      conversationChanged: true,
      wasNearBottom: false,
      appended: []
    })).toBe(true)
    expect(shouldFollowCommunicationTimeline({
      conversationChanged: false,
      wasNearBottom: true,
      appended: [message(3, 'INBOUND')]
    })).toBe(true)
    expect(shouldFollowCommunicationTimeline({
      conversationChanged: false,
      wasNearBottom: false,
      appended: [message(4, 'OUTBOUND')]
    })).toBe(true)
    expect(shouldFollowCommunicationTimeline({
      conversationChanged: false,
      wasNearBottom: false,
      appended: [message(5, 'INTERNAL')]
    })).toBe(true)
  })

  it('preserva a leitura quando chegam apenas mensagens inbound longe do fim', () => {
    expect(shouldFollowCommunicationTimeline({
      conversationChanged: false,
      wasNearBottom: false,
      appended: [message(6, 'INBOUND')]
    })).toBe(false)
  })

  it('confirma leitura inicial somente após render visível e auto-read apenas no fim', () => {
    const base = {
      rendered: true,
      visible: true,
      atEnd: false,
      initialReadPending: true,
      manualUnread: false,
      unreadCount: 2,
      snapshotThroughMessageId: 42
    }
    expect(shouldMarkCommunicationTimelineRead(base)).toBe(true)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      rendered: false
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      visible: false
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      initialReadPending: false,
      atEnd: false
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      initialReadPending: false,
      atEnd: true
    })).toBe(true)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      manualUnread: true,
      atEnd: true
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      initialReadPending: true,
      manualUnread: true
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      snapshotThroughMessageId: null
    })).toBe(false)
    expect(shouldMarkCommunicationTimelineRead({
      ...base,
      unreadCount: 0
    })).toBe(false)
  })

  it('ignora versões de leitura repetidas ou fora de ordem', () => {
    expect(isCommunicationReadStateVersionNewer(4, 3)).toBe(true)
    expect(isCommunicationReadStateVersionNewer(4, 4)).toBe(false)
    expect(isCommunicationReadStateVersionNewer(3, 4)).toBe(false)
  })

  it('preserva cursor monotônico em evento parcial e libera retry só para snapshot novo', () => {
    expect(mergeCommunicationReadThroughMessageId(42, undefined)).toBe(42)
    expect(mergeCommunicationReadThroughMessageId(42, null)).toBe(42)
    expect(mergeCommunicationReadThroughMessageId(42, 40)).toBe(42)
    expect(mergeCommunicationReadThroughMessageId(42, 51)).toBe(51)
    expect(canRetryCommunicationReadAcknowledgement(42, 42)).toBe(false)
    expect(canRetryCommunicationReadAcknowledgement(42, 51)).toBe(true)
    expect(canRetryCommunicationReadAcknowledgement(undefined, 42)).toBe(true)
    expect(canRetryCommunicationReadAcknowledgement(undefined, null)).toBe(false)
  })

  it('formata o contador e respeita movimento reduzido', () => {
    expect(communicationNewMessagesLabel(1)).toBe('1 nova mensagem')
    expect(communicationNewMessagesLabel(4)).toBe('4 novas mensagens')
    expect(communicationUserScrollBehavior(false)).toBe('smooth')
    expect(communicationUserScrollBehavior(true)).toBe('auto')
  })
})

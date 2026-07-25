import type { CommunicationMessage } from '~/types/communication'

export const COMMUNICATION_TIMELINE_BOTTOM_THRESHOLD = 96

export interface CommunicationTimelineScrollMetrics {
  scrollTop: number
  scrollHeight: number
  clientHeight: number
}

export function isCommunicationTimelineNearBottom(
  metrics: CommunicationTimelineScrollMetrics,
  threshold = COMMUNICATION_TIMELINE_BOTTOM_THRESHOLD
): boolean {
  const distance = metrics.scrollHeight - metrics.clientHeight - metrics.scrollTop
  return distance <= Math.max(0, threshold)
}

export function appendedCommunicationMessages(
  previousIds: Iterable<number>,
  current: CommunicationMessage[]
): CommunicationMessage[] {
  const previous = new Set(previousIds)
  return current.filter(message => !previous.has(message.id))
}

export function shouldFollowCommunicationTimeline(input: {
  conversationChanged: boolean
  wasNearBottom: boolean
  appended: CommunicationMessage[]
}): boolean {
  return input.conversationChanged
    || input.wasNearBottom
    || input.appended.some(message => message.direction !== 'INBOUND')
}

export function communicationNewMessagesLabel(count: number): string {
  return count === 1 ? '1 nova mensagem' : `${count} novas mensagens`
}

export function communicationUserScrollBehavior(reducedMotion: boolean): ScrollBehavior {
  return reducedMotion ? 'auto' : 'smooth'
}

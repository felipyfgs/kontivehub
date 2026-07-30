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

export function shouldMarkCommunicationTimelineRead(input: {
  rendered: boolean
  visible: boolean
  atEnd: boolean
  initialReadPending: boolean
  manualUnread: boolean
  unreadCount: number
  snapshotThroughMessageId: number | null
}): boolean {
  if (
    !input.rendered
    || !input.visible
    || input.manualUnread
    || input.unreadCount < 1
    || input.snapshotThroughMessageId === null
  ) {
    return false
  }

  return input.initialReadPending || input.atEnd
}

export function isCommunicationReadStateVersionNewer(
  incomingVersion: number,
  knownVersion: number
): boolean {
  return Number.isInteger(incomingVersion)
    && incomingVersion >= 0
    && incomingVersion > knownVersion
}

export function mergeCommunicationReadThroughMessageId(
  known: number | null | undefined,
  incoming: unknown
): number | null {
  const current = typeof known === 'number' && Number.isInteger(known) ? known : null
  const next = typeof incoming === 'number' && Number.isInteger(incoming) ? incoming : null
  if (current === null) return next
  if (next === null) return current
  return Math.max(current, next)
}

export function canRetryCommunicationReadAcknowledgement(
  failedSnapshotMessageId: number | undefined,
  snapshotMessageId: number | null
): boolean {
  return snapshotMessageId !== null && failedSnapshotMessageId !== snapshotMessageId
}

export function communicationNewMessagesLabel(count: number): string {
  return count === 1 ? '1 nova mensagem' : `${count} novas mensagens`
}

export function communicationUserScrollBehavior(reducedMotion: boolean): ScrollBehavior {
  return reducedMotion ? 'auto' : 'smooth'
}

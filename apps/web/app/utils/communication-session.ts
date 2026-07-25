import type { CommunicationInboxStatus } from '~/types/communication'

export type CommunicationSessionAction = 'connect' | 'disconnect' | 'logout'

/** Matriz canônica das ações administrativas por estado e credencial. */
export function communicationSessionActions(
  status: CommunicationInboxStatus,
  hasCredentials: boolean
): CommunicationSessionAction[] {
  if (status === 'CONNECTING') return ['disconnect']
  if (status === 'CONNECTED') {
    return hasCredentials ? ['disconnect', 'logout'] : ['disconnect']
  }
  return hasCredentials ? ['connect', 'logout'] : ['connect']
}

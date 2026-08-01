import type { InboxStatus } from '~/types/communication/inboxes'

export type SessionAction = 'connect' | 'disconnect' | 'logout'

/** Matriz canônica das ações administrativas por estado e credencial. */
export function communicationSessionActions(
  status: InboxStatus,
  hasCredentials: boolean
): SessionAction[] {
  if (status === 'CONNECTING') return ['disconnect']
  if (status === 'CONNECTED') {
    return hasCredentials ? ['disconnect', 'logout'] : ['disconnect']
  }
  return hasCredentials ? ['connect', 'logout'] : ['connect']
}

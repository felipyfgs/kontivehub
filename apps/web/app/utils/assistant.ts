import type { MeUser } from '~/types/api'

/**
 * Disponibilidade efetiva do assistente.
 * Fonte de verdade: meta `me.assistant.enabled` da API (fail-closed).
 */
export function isAssistantAvailable(me?: MeUser | null): boolean {
  return me?.assistant?.enabled === true
}

/** Alias semântico para gates de UI (trigger, shortcut). */
export function canUseAssistant(me?: MeUser | null): boolean {
  return isAssistantAvailable(me)
}

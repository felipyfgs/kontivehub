import type { MeUser } from '~/types/api'

/**
 * Disponibilidade efetiva do assistente.
 * Fonte de verdade: meta `me.assistant.enabled` da API (fail-closed).
 */
export function canUseAssistant(me?: MeUser | null): boolean {
  return me?.assistant?.enabled === true
}

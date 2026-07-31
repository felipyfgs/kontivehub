import { parsePositiveRouteId } from '~/utils/route-params'

/** Path canônico da lista de atendimento. */
export const COMMUNICATION_INDEX_PATH = '/communication'

/** Catálogo de contatos de comunicação. */
export const COMMUNICATION_CONTACTS_PATH = `${COMMUNICATION_INDEX_PATH}/contacts`

/** Gestão de respostas rápidas. */
export const COMMUNICATION_QUICK_RESPONSES_PATH = `${COMMUNICATION_INDEX_PATH}/quick-responses`

/** Gestão administrativa de fluxos (robôs). */
export const COMMUNICATION_FLOWS_PATH = `${COMMUNICATION_INDEX_PATH}/flows`

/** Deep-link de conversa (estilo Chatwoot: …/conversations/{id}). */
export function communicationConversationPath(id: number): string {
  return `${COMMUNICATION_INDEX_PATH}/conversations/${id}`
}

/** Deep-link de uma mensagem dentro da conversa. */
export function communicationConversationMessagePath(conversationId: number, messageId: number): string {
  return `${communicationConversationPath(conversationId)}/messages/${messageId}`
}

/** Contexto estável: histórico de conversas de um contato. */
export function communicationContactConversationsPath(contactId: number, conversationId?: number): string {
  const base = `${communicationContactPath(contactId)}/conversations`
  return conversationId ? `${base}/${conversationId}` : base
}

export function parseCommunicationMessageId(param: unknown): number | null {
  return parseCommunicationConversationId(param)
}

/** Deep-link dos detalhes do contato. */
export function communicationContactPath(id: number): string {
  return `${COMMUNICATION_CONTACTS_PATH}/${id}`
}

/** Deep-link do detalhe de fluxo. */
export function communicationFlowPath(id: number): string {
  return `${COMMUNICATION_FLOWS_PATH}/${id}`
}

/** Deep-link do editor visual do fluxo. */
export function communicationFlowEditorPath(id: number): string {
  return `${COMMUNICATION_FLOWS_PATH}/${id}/editor`
}

export function parseCommunicationConversationId(param: unknown): number | null {
  return parsePositiveRouteId(param)
}

export function parseCommunicationContactId(param: unknown): number | null {
  return parsePositiveRouteId(param)
}

export function parseCommunicationFlowId(param: unknown): number | null {
  return parsePositiveRouteId(param)
}

/**
 * Rotas que compartilham a mesma instância do workspace master-detail.
 * Catálogo, detalhe de contato, respostas rápidas e fluxos usam superfícies próprias.
 */
export function isCommunicationWorkspacePath(path: string): boolean {
  if (path === COMMUNICATION_INDEX_PATH) return true

  return /^\/communication\/conversations\/[1-9]\d*(?:\/messages\/[1-9]\d*)?\/?$/.test(path)
    || /^\/communication\/contacts\/[1-9]\d*\/conversations(?:\/[1-9]\d*)?\/?$/.test(path)
}

export function isCommunicationNavActive(path: string): boolean {
  return path === COMMUNICATION_INDEX_PATH || path.startsWith(`${COMMUNICATION_INDEX_PATH}/`)
}

export function isCommunicationContactsNavActive(path: string): boolean {
  return path === COMMUNICATION_CONTACTS_PATH || path.startsWith(`${COMMUNICATION_CONTACTS_PATH}/`)
}

export function isCommunicationQuickResponsesNavActive(path: string): boolean {
  return path === COMMUNICATION_QUICK_RESPONSES_PATH
    || path.startsWith(`${COMMUNICATION_QUICK_RESPONSES_PATH}/`)
}

export function isCommunicationFlowsNavActive(path: string): boolean {
  return path === COMMUNICATION_FLOWS_PATH
    || path.startsWith(`${COMMUNICATION_FLOWS_PATH}/`)
}

export function isCommunicationInboxNavActive(path: string): boolean {
  if (!isCommunicationNavActive(path)) return false
  if (isCommunicationContactsNavActive(path)) return false
  if (isCommunicationQuickResponsesNavActive(path)) return false
  if (isCommunicationFlowsNavActive(path)) return false
  return true
}

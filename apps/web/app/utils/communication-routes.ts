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

/** Deep-link da ficha de contato. */
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
  const raw = Array.isArray(param) ? param[0] : param
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

export function parseCommunicationContactId(param: unknown): number | null {
  const raw = Array.isArray(param) ? param[0] : param
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

export function parseCommunicationFlowId(param: unknown): number | null {
  const raw = Array.isArray(param) ? param[0] : param
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
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

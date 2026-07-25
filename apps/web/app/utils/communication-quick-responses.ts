import type {
  CommunicationCannedResponse,
  CommunicationCannedResponseListParams
} from '~/types/communication'

/** Variáveis allowlist do backend (store/update/render). */
export const CANNED_RESPONSE_VARIABLES = [
  '{{contato.nome}}',
  '{{cliente.nome}}',
  '{{atendente.nome}}',
  '{{inbox.nome}}'
] as const

const SHORTCUT_PATTERN = /^[a-z0-9._-]+$/

export function normalizeCannedShortcut(value: string): string {
  return value.trim().toLowerCase()
}

export function isValidCannedShortcut(value: string): boolean {
  const normalized = normalizeCannedShortcut(value)
  return normalized.length > 0 && SHORTCUT_PATTERN.test(normalized)
}

export function cannedResponseStatusLabel(item: Pick<CommunicationCannedResponse, 'is_active'>): string {
  return item.is_active ? 'Ativa' : 'Inativa'
}

export function cannedResponseStatusColor(
  item: Pick<CommunicationCannedResponse, 'is_active'>
): 'success' | 'neutral' {
  return item.is_active ? 'success' : 'neutral'
}

export function buildCannedResponseListQuery(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
  page: number
  perPage: number
}): CommunicationCannedResponseListParams {
  const params: CommunicationCannedResponseListParams = {
    manage: 1,
    page: input.page,
    per_page: input.perPage
  }
  const q = input.q.trim()
  if (q) params.q = q
  if (input.isActive === 'true') params.is_active = true
  else if (input.isActive === 'false') params.is_active = false
  return params
}

export function cannedResponseEmptyKind(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
}): 'empty' | 'filtered' {
  if (input.q.trim() || input.isActive !== 'all') return 'filtered'
  return 'empty'
}

export interface CannedSlashTokenMatch {
  start: number
  end: number
  query: string
}

/**
 * Detecta token `/atalho` imediatamente antes do cursor.
 * Retorna null se não houver `/` ativo (espaço após barra encerra).
 */
export function findCannedSlashToken(text: string, cursor: number): CannedSlashTokenMatch | null {
  const safeCursor = Math.max(0, Math.min(cursor, text.length))
  const before = text.slice(0, safeCursor)
  const match = before.match(/(?:^|[\s\n])\/([a-z0-9._-]*)$/i)
  if (!match) return null
  const query = match[1] ?? ''
  const token = `/${query}`
  const start = safeCursor - token.length
  if (start < 0) return null
  return { start, end: safeCursor, query: query.toLowerCase() }
}

export function filterCannedResponsesByShortcut(
  items: CommunicationCannedResponse[],
  query: string
): CommunicationCannedResponse[] {
  const needle = query.trim().toLowerCase()
  const active = items.filter(item => item.is_active)
  if (!needle) return active.slice(0, 8)
  return active
    .filter(item => item.shortcut.includes(needle) || item.title.toLowerCase().includes(needle))
    .slice(0, 8)
}

export function replaceCannedSlashToken(
  text: string,
  match: CannedSlashTokenMatch,
  replacement: string
): string {
  return `${text.slice(0, match.start)}${replacement}${text.slice(match.end)}`
}

export interface CannedAutocompleteKeyEvent {
  key: string
  altKey?: boolean
  ctrlKey?: boolean
  metaKey?: boolean
  isComposing?: boolean
  keyCode?: number
}

/** Teclas do listbox — nunca durante composition IME. */
export function shouldHandleCannedAutocompleteKey(event: CannedAutocompleteKeyEvent): boolean {
  if (event.isComposing === true || event.keyCode === 229) return false
  if (event.altKey || event.ctrlKey || event.metaKey) return false
  return ['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(event.key)
}

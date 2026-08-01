import type { ConversationSortBy } from '~/types/communication/conversations'

/** Allowlist canônica — espelha App\Enums\Communication\ConversationListSort. */
export const COMMUNICATION_SORT_BY_VALUES = [
  'last_activity_desc',
  'last_activity_asc',
  'created_desc',
  'created_asc',
  'unread_desc',
  'priority_desc',
  'priority_asc'
] as const satisfies readonly ConversationSortBy[]

export const COMMUNICATION_DEFAULT_SORT_BY: ConversationSortBy = 'last_activity_desc'

export const COMMUNICATION_SORT_BY_OPTIONS: Array<{
  label: string
  value: ConversationSortBy
}> = [
  { label: 'Atividade (recente)', value: 'last_activity_desc' },
  { label: 'Atividade (antiga)', value: 'last_activity_asc' },
  { label: 'Criação (recente)', value: 'created_desc' },
  { label: 'Criação (antiga)', value: 'created_asc' },
  { label: 'Não lidas primeiro', value: 'unread_desc' },
  { label: 'Prioridade (alta)', value: 'priority_desc' },
  { label: 'Prioridade (baixa)', value: 'priority_asc' }
]

export function isCommunicationConversationSortBy(
  value: unknown
): value is ConversationSortBy {
  return typeof value === 'string'
    && (COMMUNICATION_SORT_BY_VALUES as readonly string[]).includes(value)
}

/**
 * Normaliza valor vindo da API, preferência ou USelectMenu (string | item).
 * Valores inválidos caem no default da preferência (last_activity_desc).
 */
export function normalizeCommunicationConversationSortBy(
  value: unknown
): ConversationSortBy {
  if (isCommunicationConversationSortBy(value)) {
    return value
  }
  if (value && typeof value === 'object' && 'value' in value) {
    return normalizeCommunicationConversationSortBy(
      (value as { value: unknown }).value
    )
  }
  return COMMUNICATION_DEFAULT_SORT_BY
}

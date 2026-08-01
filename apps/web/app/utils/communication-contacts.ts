import type { DropdownMenuItem } from '@nuxt/ui'
import type { Contact, ContactListParams, ContactSortField, Identity } from '~/types/communication/contacts'
import {
  communicationContactConversationsPath,
  communicationContactPath
} from '~/utils/communication-routes'

/** Whitelist alinhada ao contrato HTTP de sort do catálogo. */
export const COMMUNICATION_CONTACT_SORT_FIELDS = ['name', 'id', 'created_at'] as const satisfies readonly ContactSortField[]

export function isCommunicationContactSortField(value: unknown): value is ContactSortField {
  return typeof value === 'string'
    && (COMMUNICATION_CONTACT_SORT_FIELDS as readonly string[]).includes(value)
}

export function communicationContactDisplayName(contact: Pick<Contact, 'name' | 'id' | 'is_provisional'>): string {
  const name = contact.name?.trim()
  if (name) return name
  if (contact.is_provisional) return `Provisório #${contact.id}`
  return `Contato #${contact.id}`
}

/**
 * Iniciais para UAvatar: nome real → 1–2 letras; provisório/sem nome → `?`.
 * Sem foto remota de gateway no catálogo.
 */
export function communicationContactInitials(
  contact: Pick<Contact, 'name' | 'id' | 'is_provisional'>
): string {
  const name = contact.name?.trim()
  if (!name) return '?'
  const parts = name
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (!parts.length) return '?'
  if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase()
  return `${parts[0]![0] || ''}${parts[1]![0] || ''}`.toUpperCase()
}

export function communicationContactIdentityCount(contact: Contact): number {
  return (contact.identities || []).length
}

export function communicationContactPrimaryPhone(contact: Contact): string | null {
  const identities = contact.identities || []
  const preferred = identities.find(identity => identity.is_active && identity.phone)
    || identities.find(identity => identity.phone)
  return preferred?.phone || null
}

export function communicationContactLinkedClientNames(contact: Contact): string[] {
  const names = new Set<string>()
  for (const identity of contact.identities || []) {
    for (const link of identity.links || []) {
      const label = link.client_name?.trim() || (link.client_id ? `Cliente #${link.client_id}` : '')
      if (label) names.add(label)
    }
  }
  return [...names]
}

export function communicationContactHasLinks(contact: Contact): boolean {
  return (contact.identities || []).some(identity => (identity.links || []).length > 0)
}

export function communicationContactStatusLabel(contact: Contact): string {
  if (contact.purged_at) return 'Expurgado'
  if (!contact.is_active) return 'Inativo'
  if (contact.is_provisional) return 'Provisório'
  return 'Ativo'
}

export function communicationContactStatusColor(
  contact: Contact
): 'success' | 'warning' | 'neutral' | 'error' {
  if (contact.purged_at) return 'error'
  if (!contact.is_active) return 'neutral'
  if (contact.is_provisional) return 'warning'
  return 'success'
}

/**
 * Reforça contraste do texto sobre badges `subtle` sem perder a cor semântica.
 * As variantes padrão do tema usam o tom 500, insuficiente para texto pequeno.
 */
export function communicationContactStatusContrastClass(contact: Contact): string {
  if (contact.purged_at) return '!text-red-900 dark:!text-red-100'
  if (!contact.is_active) return '!text-zinc-900 dark:!text-zinc-100'
  if (contact.is_provisional) return '!text-amber-900 dark:!text-amber-100'
  return '!text-green-900 dark:!text-green-100'
}

/** Texto escuro sobre fundos saturados primário/error do tema. */
export const COMMUNICATION_CONTACT_SOLID_ACTION_CLASS = '!text-zinc-950'

/** Texto semântico com contraste AA sobre fundos `soft`/`subtle` de erro. */
export const COMMUNICATION_CONTACT_DANGER_SOFT_CLASS = '!text-red-900 dark:!text-red-100'

/** Rótulos de ações de linha / detalhes (pt-BR, estáveis para a11y e testes). */
export const COMMUNICATION_CONTACT_ACTION_LABELS = {
  openDetail: 'Detalhes',
  goToConversations: 'Ir para conversas',
  export: 'Exportar',
  purge: 'Expurgar'
} as const

export type ContactActionHandlers = {
  onExport?: () => void
  onPurge?: () => void
}

/** Fonte única das ações compactas de contato para lista, navbar e contexto. */
export function communicationContactActions(
  contact: Contact,
  canManage: boolean,
  handlers: ContactActionHandlers = {}
): DropdownMenuItem[][] {
  const navigation: DropdownMenuItem[] = [
    { label: COMMUNICATION_CONTACT_ACTION_LABELS.openDetail, icon: 'i-lucide-arrow-up-right', to: communicationContactPath(contact.id) },
    { label: COMMUNICATION_CONTACT_ACTION_LABELS.goToConversations, icon: 'i-lucide-messages-square', to: communicationContactConversationsPath(contact.id) }
  ]
  if (!canManage || contact.purged_at) return [navigation]
  const management: DropdownMenuItem[] = []
  if (handlers.onExport) management.push({ label: COMMUNICATION_CONTACT_ACTION_LABELS.export, icon: 'i-lucide-download', onSelect: handlers.onExport })
  if (handlers.onPurge) management.push({ label: COMMUNICATION_CONTACT_ACTION_LABELS.purge, icon: 'i-lucide-trash-2', color: 'error', onSelect: handlers.onPurge })
  return management.length ? [navigation, management] : [navigation]
}

export function communicationContactRowActionsAriaLabel(
  contact: Pick<Contact, 'name' | 'id' | 'is_provisional'>
): string {
  return `Ações de ${communicationContactDisplayName(contact)}`
}

/**
 * Busca com oito ou mais dígitos pode conter telefone/PII.
 * Ela usa POST/body e nunca é persistida na URL.
 */
export function isSensitiveCommunicationContactSearch(value: unknown): boolean {
  if (typeof value !== 'string') return false
  return (value.match(/\d/g) || []).length >= 8
}

export function buildCommunicationContactListQuery(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
  isProvisional: 'all' | 'true' | 'false'
  linked: 'all' | 'true' | 'false'
  sort: ContactSortField | null
  sortDirection: 'asc' | 'desc' | null
  page: number
  perPage: number
}): ContactListParams {
  const params: ContactListParams = {
    page: input.page,
    per_page: input.perPage
  }
  const q = input.q.trim()
  if (q) params.q = q

  if (input.isActive === 'true') params.is_active = true
  else if (input.isActive === 'false') {
    params.is_active = false
    params.include_inactive = true
  } else {
    // "all" = ativos + inativos
    params.include_inactive = true
  }

  if (input.isProvisional === 'true') params.is_provisional = true
  else if (input.isProvisional === 'false') params.is_provisional = false

  if (input.linked === 'true') params.linked = true
  else if (input.linked === 'false') params.linked = false

  if (input.sort && isCommunicationContactSortField(input.sort)) {
    params.sort = input.sort
    params.sort_direction = input.sortDirection === 'desc' ? 'desc' : 'asc'
  }

  return params
}

export function hasActiveCommunicationContactFilters(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
  isProvisional: 'all' | 'true' | 'false'
  linked: 'all' | 'true' | 'false'
}): boolean {
  return Boolean(
    input.q.trim()
    || input.isActive !== 'true'
    || input.isProvisional !== 'all'
    || input.linked !== 'all'
  )
}

export function communicationContactEmptyKind(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
  isProvisional: 'all' | 'true' | 'false'
  linked: 'all' | 'true' | 'false'
}): 'empty' | 'filtered' {
  return hasActiveCommunicationContactFilters(input) ? 'filtered' : 'empty'
}

export function flattenCommunicationIdentityLinks(identities: Identity[] | undefined) {
  return (identities || []).flatMap(identity =>
    (identity.links || []).map(link => ({ identity, link }))
  )
}

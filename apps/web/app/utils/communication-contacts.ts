import type {
  CommunicationContact,
  CommunicationContactListParams,
  CommunicationContactSortField,
  CommunicationIdentity
} from '~/types/communication'

/** Whitelist alinhada ao contrato HTTP de sort do catálogo. */
export const COMMUNICATION_CONTACT_SORT_FIELDS = ['name', 'id', 'created_at'] as const satisfies readonly CommunicationContactSortField[]

export function isCommunicationContactSortField(value: unknown): value is CommunicationContactSortField {
  return typeof value === 'string'
    && (COMMUNICATION_CONTACT_SORT_FIELDS as readonly string[]).includes(value)
}

export function communicationContactDisplayName(contact: Pick<CommunicationContact, 'name' | 'id' | 'is_provisional'>): string {
  const name = contact.name?.trim()
  if (name) return name
  if (contact.is_provisional) return `Provisório #${contact.id}`
  return `Contato #${contact.id}`
}

export function communicationContactPrimaryMasked(contact: CommunicationContact): string | null {
  const identities = contact.identities || []
  const active = identities.find(identity => identity.is_active) || identities[0]
  return active?.address || active?.address_masked || null
}

export function communicationContactLinkedClientNames(contact: CommunicationContact): string[] {
  const names = new Set<string>()
  for (const identity of contact.identities || []) {
    for (const link of identity.links || []) {
      const label = link.client_name?.trim() || (link.client_id ? `Cliente #${link.client_id}` : '')
      if (label) names.add(label)
    }
  }
  return [...names]
}

export function communicationContactHasLinks(contact: CommunicationContact): boolean {
  return (contact.identities || []).some(identity => (identity.links || []).length > 0)
}

export function communicationContactStatusLabel(contact: CommunicationContact): string {
  if (contact.purged_at) return 'Expurgado'
  if (!contact.is_active) return 'Inativo'
  if (contact.is_provisional) return 'Provisório'
  return 'Ativo'
}

export function communicationContactStatusColor(
  contact: CommunicationContact
): 'success' | 'warning' | 'neutral' | 'error' {
  if (contact.purged_at) return 'error'
  if (!contact.is_active) return 'neutral'
  if (contact.is_provisional) return 'warning'
  return 'success'
}

export function buildCommunicationContactListQuery(input: {
  q: string
  isActive: 'all' | 'true' | 'false'
  isProvisional: 'all' | 'true' | 'false'
  linked: 'all' | 'true' | 'false'
  sort: CommunicationContactSortField | null
  sortDirection: 'asc' | 'desc' | null
  page: number
  perPage: number
}): CommunicationContactListParams {
  const params: CommunicationContactListParams = {
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

export function flattenCommunicationIdentityLinks(identities: CommunicationIdentity[] | undefined) {
  return (identities || []).flatMap(identity =>
    (identity.links || []).map(link => ({ identity, link }))
  )
}

export interface Label {
  id: number
  name: string
  color: string
}

export interface ClientReference {
  id: number
  name: string
}

export type ProfilePictureState = 'UNKNOWN' | 'PENDING' | 'READY' | 'UNAVAILABLE' | 'FAILED'

export interface ContactSummary {
  id: number
  name?: string | null
  profile_picture_url?: string | null
  profile_picture_state?: ProfilePictureState
  is_provisional?: boolean | null
  address_masked?: string | null
  phone?: string | null
}

export interface IdentityLink {
  id: number
  client_id: number
  client_name?: string | null
  client_contact_id?: number | null
  client_contact_name?: string | null
  is_primary: boolean
  receives_automatic: boolean
}

export interface Identity {
  id: number
  channel: 'WHATSAPP' | string
  address_masked: string
  /** Telefone E.164 seguro já apresentado pela API; nunca reconstruir pela máscara. */
  phone?: string | null
  is_active: boolean
  links: IdentityLink[]
}

export interface IdentityLinkEntry {
  identity: Identity
  link: IdentityLink
}

/** Params de listagem do catálogo (GET /communication/contacts). */
export interface ContactListParams {
  q?: string
  is_active?: boolean
  is_provisional?: boolean
  linked?: boolean
  include_inactive?: boolean
  sort?: 'name' | 'id' | 'created_at'
  sort_direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export type ContactSortField = NonNullable<ContactListParams['sort']>

export interface Contact {
  id: number
  name?: string | null
  profile_picture_url?: string | null
  profile_picture_state?: ProfilePictureState
  is_provisional: boolean
  is_active: boolean
  identities?: Identity[]
  purged_at?: string | null
}

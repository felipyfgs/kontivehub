import type { InboxItem, InboxItemLinks } from '~/types/api'

/**
 * Rotas tenant-safe canônicas do painel (SPA).
 */
export const SERPRO_INBOX_ROUTES = {
  authorization: '/conta/escritorio',
  usage: '/conta/consumo',
  subscription: '/conta/assinatura',
  monitoring: '/monitoring',
  health: '/health',
  clients: '/clients',
  syncs: '/syncs'
} as const

/** Aceita apenas deep-links internos emitidos pelo contrato atual. */
export function validateTenantPath(path?: string | null): string | null {
  if (!path || typeof path !== 'string') return null
  const trimmed = path.trim()
  if (!trimmed.startsWith('/')) return null

  if (
    trimmed === SERPRO_INBOX_ROUTES.authorization
    || trimmed === '/conta'
    || trimmed.startsWith('/conta/')
    || trimmed === '/clients'
    || trimmed.startsWith('/clients/')
    || trimmed.startsWith('/monitoring')
    || trimmed.startsWith('/health')
    || trimmed.startsWith('/syncs')
    || trimmed.startsWith('/docs')
    || trimmed.startsWith('/work')
    || trimmed.startsWith('/admin')
  ) {
    return trimmed
  }

  return null
}

function firstValidLink(links?: InboxItemLinks | null): string | null {
  if (!links) return null
  const candidates = [
    links.health,
    links.quarantine,
    links.serpro_authorization,
    links.usage,
    links.monitoring,
    links.credential,
    links.sync,
    links.client
  ]
  for (const c of candidates) {
    const n = validateTenantPath(c)
    if (n) return n
  }
  return null
}

/**
 * Resolve deep-link tenant-safe para um item da inbox operacional.
 * Nunca devolve rota inexistente; fallback /health.
 */
export function resolveInboxItemLink(item: Pick<InboxItem, 'type' | 'links' | 'client_id' | 'reasons'>): string {
  const fromLinks = firstValidLink(item.links)
  if (fromLinks) return fromLinks

  const type = String(item.type || '')

  if (type.startsWith('serpro_') || type === 'source_unavailable') {
    return SERPRO_INBOX_ROUTES.authorization
  }
  if (type.startsWith('proxy_power')) {
    if (item.client_id) {
      return `/clients/${item.client_id}/dados-adicionais`
    }
    return SERPRO_INBOX_ROUTES.authorization
  }
  if (type.startsWith('usage_')) {
    return SERPRO_INBOX_ROUTES.usage
  }
  if (type === 'query_blocked') {
    return item.client_id
      ? `/monitoring/clients/${item.client_id}`
      : SERPRO_INBOX_ROUTES.monitoring
  }
  if (type.startsWith('credential')) {
    return item.client_id ? `/clients/${item.client_id}/dados-adicionais` : SERPRO_INBOX_ROUTES.clients
  }
  if (type.startsWith('sync') || type.startsWith('cursor')) {
    return SERPRO_INBOX_ROUTES.syncs
  }
  if (type.startsWith('backup')) {
    return SERPRO_INBOX_ROUTES.health
  }
  if (type.startsWith('outbound') || type.startsWith('svrs_') || type.startsWith('cte_')) {
    return item.client_id ? `/clients/${item.client_id}/cadastro` : SERPRO_INBOX_ROUTES.clients
  }
  if (type.startsWith('quarantine')) {
    return '/docs/imports'
  }
  if (type.startsWith('sitfis') || type.startsWith('mailbox')) {
    return SERPRO_INBOX_ROUTES.monitoring
  }

  return SERPRO_INBOX_ROUTES.health
}

/** Labels pt-BR para filtros da inbox SERPRO. */
export const SERPRO_INBOX_TYPE_FILTERS: Array<{ label: string, value: string }> = [
  { label: 'Termo ausente', value: 'serpro_termo_missing' },
  { label: 'Termo expirado', value: 'serpro_termo_expired' },
  { label: 'Token expirando', value: 'serpro_token_expiring' },
  { label: 'Ação autorização SERPRO', value: 'serpro_auth_action_required' },
  { label: 'Autorização bloqueada', value: 'serpro_auth_blocked' },
  { label: 'Procuração expirada', value: 'proxy_power_expired' },
  { label: 'Procuração ausente', value: 'proxy_power_missing' },
  { label: 'Fonte indisponível', value: 'source_unavailable' },
  { label: 'Consulta bloqueada', value: 'query_blocked' },
  { label: 'Franquia esgotada', value: 'usage_franchise_exceeded' },
  { label: 'Consumo elevado', value: 'usage_high' }
]

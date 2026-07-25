import type { InboxItem, InboxItemLinks } from '~/types/api'

/**
 * Rotas tenant-safe canônicas do painel (SPA).
 * Links legados da API (ex.: /settings/integracao-serpro) são normalizados aqui.
 */
export const SERPRO_INBOX_ROUTES = {
  authorization: '/conta/escritorio',
  /** Importação manual de procurações removida — redireciona ao settings unificado. */
  proxies: '/conta/escritorio',
  usage: '/conta/consumo',
  subscription: '/conta/assinatura',
  monitoring: '/monitoring',
  health: '/health',
  clients: '/clients',
  syncs: '/syncs'
} as const

const LEGACY_PATH_REWRITES: Array<{ pattern: RegExp, to: string | ((m: RegExpMatchArray) => string) }> = [
  { pattern: /^\/settings\/integracao-serpro\/?$/i, to: SERPRO_INBOX_ROUTES.authorization },
  { pattern: /^\/settings\/proxies\/?$/i, to: SERPRO_INBOX_ROUTES.authorization },
  { pattern: /^\/settings\/consumo\/?$/i, to: SERPRO_INBOX_ROUTES.usage },
  { pattern: /^\/settings\/usage\/?$/i, to: SERPRO_INBOX_ROUTES.usage },
  { pattern: /^\/clients\/(\d+)\/procuracoes\/?$/i, to: m => `/clients/${m[1]}` },
  { pattern: /^\/fiscal\/runs\/\d+\/?$/i, to: SERPRO_INBOX_ROUTES.monitoring },
  { pattern: /^\/integracao-serpro\/?$/i, to: SERPRO_INBOX_ROUTES.authorization }
]

/** Reescreve path legado para rota existente no SPA. */
export function normalizeTenantPath(path?: string | null): string | null {
  if (!path || typeof path !== 'string') return null
  const trimmed = path.trim()
  if (!trimmed.startsWith('/')) return null

  for (const rule of LEGACY_PATH_REWRITES) {
    const match = rule.pattern.exec(trimmed)
    if (match) {
      return typeof rule.to === 'function' ? rule.to(match) : rule.to
    }
  }

  // Paths absolutos já canônicos
  if (
    trimmed === SERPRO_INBOX_ROUTES.authorization
    || trimmed === '/conta'
    || trimmed.startsWith('/conta/')
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

function firstNormalizedLink(links?: InboxItemLinks | null): string | null {
  if (!links) return null
  const candidates = [
    links.serpro_authorization,
    links.proxy,
    links.usage,
    links.run,
    links.monitoring,
    links.credential,
    links.sync,
    links.client
  ]
  for (const c of candidates) {
    const n = normalizeTenantPath(c)
    if (n) return n
  }
  return null
}

/**
 * Resolve deep-link tenant-safe para um item da inbox operacional.
 * Nunca devolve rota inexistente; fallback /health.
 */
export function resolveInboxItemLink(item: Pick<InboxItem, 'type' | 'links' | 'client_id' | 'reasons'>): string {
  const fromLinks = firstNormalizedLink(item.links)
  if (fromLinks) return fromLinks

  const type = String(item.type || '')

  if (type.startsWith('serpro_') || type === 'source_unavailable') {
    return SERPRO_INBOX_ROUTES.authorization
  }
  if (type.startsWith('proxy_power')) {
    if (item.client_id) {
      return `/clients/${item.client_id}`
    }
    return SERPRO_INBOX_ROUTES.proxies
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
    return item.client_id ? `/clients/${item.client_id}` : SERPRO_INBOX_ROUTES.clients
  }
  if (type.startsWith('sync') || type.startsWith('cursor')) {
    return SERPRO_INBOX_ROUTES.syncs
  }
  if (type.startsWith('backup')) {
    return SERPRO_INBOX_ROUTES.health
  }
  if (type.startsWith('outbound') || type.startsWith('svrs_') || type.startsWith('cte_')) {
    return item.client_id ? `/clients/${item.client_id}` : SERPRO_INBOX_ROUTES.clients
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

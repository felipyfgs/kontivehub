/**
 * Sync canônico de filtros de lista com a query string.
 * Keys: q, filtros estruturados, page, per_page, sort, sort_direction.
 * Valores multi usam CSV. Defaults omitidos da URL.
 */
import type { LocationQuery, LocationQueryRaw } from 'vue-router'

export type ListFilterQueryScalar = string | number | boolean | null | undefined

export type ListFilterQueryState = Record<string, ListFilterQueryScalar>

export interface ListFilterQuerySchema {
  /** Defaults — omitidos na serialização quando iguais. */
  defaults: ListFilterQueryState
  /** Keys numéricas (page, per_page, ids). */
  numberKeys?: readonly string[]
  /** Keys booleanas. */
  booleanKeys?: readonly string[]
}

function firstQueryValue(raw: unknown): string | undefined {
  if (raw === undefined || raw === null) return undefined
  if (Array.isArray(raw)) {
    const first = raw[0]
    return first === undefined || first === null ? undefined : String(first)
  }
  return String(raw)
}

export function parseListFilterQuery(
  query: LocationQuery | Record<string, unknown>,
  schema: ListFilterQuerySchema
): ListFilterQueryState {
  const numberKeys = new Set(schema.numberKeys ?? [])
  const booleanKeys = new Set(schema.booleanKeys ?? [])
  const out: ListFilterQueryState = { ...schema.defaults }

  for (const key of Object.keys(schema.defaults)) {
    const raw = firstQueryValue((query as Record<string, unknown>)[key])
    if (raw === undefined) continue

    if (booleanKeys.has(key)) {
      out[key] = raw === '1' || raw === 'true'
      continue
    }
    if (numberKeys.has(key)) {
      const n = Number(raw)
      out[key] = Number.isFinite(n) ? n : schema.defaults[key]
      continue
    }
    out[key] = raw
  }

  return out
}

export function serializeListFilterQuery(
  state: ListFilterQueryState,
  schema: ListFilterQuerySchema
): LocationQueryRaw {
  const query: LocationQueryRaw = {}
  for (const [key, value] of Object.entries(state)) {
    if (!(key in schema.defaults)) continue
    const def = schema.defaults[key]
    if (value === undefined || value === null) continue
    if (typeof value === 'string' && value.trim() === '') continue
    if (value === def) continue
    if (typeof value === 'boolean') {
      query[key] = value ? '1' : '0'
      continue
    }
    query[key] = String(value)
  }
  return query
}

/** Alias canônico: aceita direction ou sort_direction na leitura. */
export function resolveSortDirection(
  query: LocationQuery | Record<string, unknown>,
  fallback: 'asc' | 'desc' = 'asc'
): 'asc' | 'desc' {
  const raw = firstQueryValue(
    (query as Record<string, unknown>).sort_direction
    ?? (query as Record<string, unknown>).direction
  )
  if (!raw) return fallback
  const lower = raw.toLowerCase()
  return lower === 'desc' ? 'desc' : 'asc'
}

export const CLIENTS_LIST_QUERY_SCHEMA: ListFilterQuerySchema = {
  defaults: {
    q: '',
    status: 'all',
    operational_filter: 'total',
    category_ids: '',
    tax_regimes: '',
    procuracao_statuses: '',
    page: 1,
    per_page: 20,
    sort: 'legal_name',
    sort_direction: 'asc'
  },
  numberKeys: ['page', 'per_page']
}

/**
 * Mantém estado de lista espelhado na URL (replace).
 * Hosts aplicam parse no mount e chamam `pushState` após mudanças.
 */
export function useListFilterQuery(schema: ListFilterQuerySchema) {
  const route = useRoute()
  const router = useRouter()

  function read(): ListFilterQueryState {
    const base = parseListFilterQuery(route.query, schema)
    if ('sort_direction' in schema.defaults) {
      base.sort_direction = resolveSortDirection(route.query, String(schema.defaults.sort_direction) === 'desc' ? 'desc' : 'asc')
    }
    return base
  }

  async function write(state: ListFilterQueryState) {
    const query = serializeListFilterQuery(state, schema)
    await router.replace({ path: route.path, query })
  }

  return {
    read,
    write,
    parse: (query: LocationQuery | Record<string, unknown>) => parseListFilterQuery(query, schema),
    serialize: (state: ListFilterQueryState) => serializeListFilterQuery(state, schema)
  }
}

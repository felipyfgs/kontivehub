import type { DataTableFilterModel } from '~/types/data-table-filter'
import type { MonitoringFilterConfig, MonitoringFilterValue } from '~/types/fiscal-modules'
import type {
  ClientsSavedFilterPayload,
  ClosingSavedFilterPayload,
  DocsSavedFilterPayload,
  MonitoringSavedFilterPayload,
  SavedListFilterPayload,
  WorkProcessesSavedFilterPayload,
  WorkQueueSavedFilterPayload
} from '~/types/saved-list-filters'
import { SAVED_LIST_SCHEMA_VERSION } from '~/types/saved-list-filters'
import {
  modelsToMonitoringFilters,
  monitoringFiltersToModels,
  normalizeMonitoringFilters,
  resetMonitoringFilters
} from '~/utils/monitoring-filters'
import {
  emptyDocsFilters,
  FILTER_ALL,
  isActiveFilterValue,
  type NotesFilterState
} from '~/utils/notes-filters'
import type { WorkQueueFilters } from '~/composables/useWorkQueueFilters'

function isRecord(value: unknown): value is Record<string, unknown> {
  return value != null && typeof value === 'object' && !Array.isArray(value)
}

function sanitizeFilterModel(raw: unknown): DataTableFilterModel | null {
  if (!isRecord(raw) || typeof raw.key !== 'string' || !raw.key.trim()) return null
  const operator = raw.operator === 'contains'
    || raw.operator === 'between'
    || raw.operator === 'in'
    ? raw.operator
    : 'eq'
  const value = raw.value
  if (value === undefined || value === null) return null
  if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean') {
    return null
  }
  const model: DataTableFilterModel = {
    key: raw.key.trim(),
    operator,
    value
  }
  if (typeof raw.label === 'string' && raw.label.trim()) {
    model.label = raw.label.trim()
  }
  return model
}

function asString(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function normalizeCsv(
  value: unknown,
  accept: (token: string) => boolean = () => true
): string {
  const tokens = String(value ?? '')
    .split(',')
    .map(token => token.trim())
    .filter(token => token !== '' && accept(token))

  return [...new Set(tokens)].sort((a, b) => a.localeCompare(b)).join(',')
}

function asPositiveIntOrNull(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : null
}

/** Remove defaults vazios do payload monitoring (q vazio, filters vazios). */
export function stripEmptyMonitoringPayload(
  payload: MonitoringSavedFilterPayload
): MonitoringSavedFilterPayload {
  const q = String(payload.q ?? '').trim()
  const filters = Array.isArray(payload.filters)
    ? payload.filters.filter((item): item is DataTableFilterModel => {
        if (!item || typeof item !== 'object') return false
        if (item.value === '' || item.value === null || item.value === undefined) return false
        return true
      })
    : []

  return {
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    q,
    filters
  }
}

/**
 * MonitoringFilterValue + chips → payload versionado para API.
 * Usa converters existentes de monitoring-filters.
 */
export function monitoringFiltersToPayload(
  filters: MonitoringFilterValue,
  config: MonitoringFilterConfig | null | undefined,
  clientLabel?: string | null
): MonitoringSavedFilterPayload {
  const normalized = normalizeMonitoringFilters(filters)
  const models = monitoringFiltersToModels(normalized, config, clientLabel)
  return stripEmptyMonitoringPayload({
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    q: normalized.q,
    filters: models
  })
}

/**
 * Payload → MonitoringFilterValue (hidrata chips + q).
 * Chaves desconhecidas no payload são ignoradas (soft migrate).
 */
export function monitoringPayloadToFilters(
  payload: SavedListFilterPayload | null | undefined,
  config: MonitoringFilterConfig | null | undefined,
  base?: Partial<MonitoringFilterValue> | null
): MonitoringFilterValue {
  if (!isRecord(payload)) {
    return normalizeMonitoringFilters(base ?? resetMonitoringFilters())
  }

  const rawFilters = Array.isArray(payload.filters) ? payload.filters : []
  const models = rawFilters
    .map(sanitizeFilterModel)
    .filter((item): item is DataTableFilterModel => item != null)

  const q = typeof payload.q === 'string' ? payload.q : (base?.q ?? '')
  return modelsToMonitoringFilters(models, config, {
    ...resetMonitoringFilters(),
    ...base,
    q
  })
}

/** True se o payload monitoring tem recorte útil (busca ou chip). */
export function hasMonitoringPayloadContent(
  payload: MonitoringSavedFilterPayload | null | undefined
): boolean {
  if (!payload) return false
  if (String(payload.q || '').trim()) return true
  return Array.isArray(payload.filters) && payload.filters.length > 0
}

/** True se o estado aplicado da lista tem filtros/busca ativos. */
export function hasActiveMonitoringFiltersForSave(
  filters: MonitoringFilterValue,
  config: MonitoringFilterConfig | null | undefined
): boolean {
  const payload = monitoringFiltersToPayload(filters, config)
  return hasMonitoringPayloadContent(payload)
}

// ── clients.index ─────────────────────────────────────────────────────────

export interface ClientsFilterState {
  q: string
  /** 'all' | 'active' | 'inactive' */
  status: string
  /**
   * KPI: total | with_credential | without_credential | expiring
   * | credential_expired | capture_problem
   */
  operational_filter: string
  category_ids: string
  tax_regimes: string
  procuracao_statuses: string
}

export function emptyClientsFilters(): ClientsFilterState {
  return {
    q: '',
    status: 'all',
    operational_filter: 'total',
    category_ids: '',
    tax_regimes: '',
    procuracao_statuses: ''
  }
}

const CLIENT_STATUSES = new Set(['all', 'active', 'inactive'])
const CLIENT_OPERATIONAL = new Set([
  'total',
  'with_credential',
  'without_credential',
  'expiring',
  'credential_expired',
  'capture_problem'
])
const CLIENT_PROCURACAO_STATUSES = new Set([
  'authorized',
  'expiring',
  'expired',
  'missing',
  'unverified'
])

export function clientsFiltersToPayload(
  state: ClientsFilterState
): ClientsSavedFilterPayload {
  return {
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    q: String(state.q ?? '').trim(),
    status: CLIENT_STATUSES.has(state.status) ? state.status : 'all',
    operational_filter: CLIENT_OPERATIONAL.has(state.operational_filter)
      ? state.operational_filter
      : 'total',
    category_ids: normalizeCsv(state.category_ids, value => /^\d+$/.test(value) && Number(value) > 0),
    tax_regimes: normalizeCsv(state.tax_regimes),
    procuracao_statuses: normalizeCsv(
      state.procuracao_statuses,
      value => CLIENT_PROCURACAO_STATUSES.has(value)
    )
  }
}

export function clientsPayloadToFilters(
  payload: SavedListFilterPayload | null | undefined
): ClientsFilterState {
  const empty = emptyClientsFilters()
  if (!isRecord(payload)) return empty
  const status = asString(payload.status, 'all')
  const operational = asString(payload.operational_filter, 'total')
  return {
    q: asString(payload.q, '').trim(),
    status: CLIENT_STATUSES.has(status) ? status : 'all',
    operational_filter: CLIENT_OPERATIONAL.has(operational) ? operational : 'total',
    category_ids: normalizeCsv(asString(payload.category_ids, ''), value => /^\d+$/.test(value) && Number(value) > 0),
    tax_regimes: normalizeCsv(asString(payload.tax_regimes, '')),
    procuracao_statuses: normalizeCsv(
      asString(payload.procuracao_statuses, ''),
      value => CLIENT_PROCURACAO_STATUSES.has(value)
    )
  }
}

export function hasClientsPayloadContent(
  payload: ClientsSavedFilterPayload | null | undefined
): boolean {
  if (!payload) return false
  if (String(payload.q || '').trim()) return true
  if (payload.status && payload.status !== 'all') return true
  if (payload.operational_filter && payload.operational_filter !== 'total') return true
  if (payload.category_ids) return true
  if (payload.tax_regimes) return true
  if (payload.procuracao_statuses) return true
  return false
}

export function hasActiveClientsFiltersForSave(state: ClientsFilterState): boolean {
  return hasClientsPayloadContent(clientsFiltersToPayload(state))
}

// ── docs.catalog ──────────────────────────────────────────────────────────

const DOCS_FILTER_KEYS: (keyof NotesFilterState)[] = [
  'q',
  'kind',
  'direction',
  'client_id',
  'establishment_id',
  'issuer_cnpj',
  'taker_cnpj',
  'fiscal_role',
  'acquisition_source',
  'artifact_quality',
  'coverage_status',
  'competence',
  'issued_from',
  'issued_to',
  'status',
  'missing_party_name'
]

/** NotesFilterState → payload (só chaves ativas; defaults omitidos). */
export function docsFiltersToPayload(filters: NotesFilterState): DocsSavedFilterPayload {
  const payload: DocsSavedFilterPayload = {
    schema_version: SAVED_LIST_SCHEMA_VERSION
  }
  for (const key of DOCS_FILTER_KEYS) {
    const value = filters[key]
    if (!isActiveFilterValue(value)) continue
    payload[key] = value
  }
  return payload
}

/** Payload → NotesFilterState (soft migrate: chaves desconhecidas ignoradas). */
export function docsPayloadToFilters(
  payload: SavedListFilterPayload | null | undefined
): NotesFilterState {
  const next = emptyDocsFilters()
  if (!isRecord(payload)) return next
  for (const key of DOCS_FILTER_KEYS) {
    const raw = payload[key]
    if (typeof raw !== 'string' || !raw) continue
    if (key === 'kind' && !['NFSE', 'NFE', 'NFCE', 'CTE'].includes(raw.toUpperCase())) continue
    next[key] = key === 'kind' ? raw.toUpperCase() : raw
  }
  return next
}

export function hasDocsPayloadContent(
  payload: DocsSavedFilterPayload | null | undefined
): boolean {
  if (!payload || !isRecord(payload)) return false
  return DOCS_FILTER_KEYS.some((key) => {
    const value = payload[key]
    return typeof value === 'string' && isActiveFilterValue(value)
  })
}

export function hasActiveDocsFiltersForSave(filters: NotesFilterState): boolean {
  return hasDocsPayloadContent(docsFiltersToPayload(filters))
}

// ── work.queue ────────────────────────────────────────────────────────────

export function workQueueFiltersToPayload(
  filters: WorkQueueFilters
): WorkQueueSavedFilterPayload {
  const tab = String(filters.tab || 'open') || 'open'
  const scope = String(filters.scope || 'default') || 'default'
  const perPage = Math.min(100, Math.max(1, Number(filters.per_page) || 10))
  const payload: WorkQueueSavedFilterPayload = {
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    tab,
    q: String(filters.q || '').trim(),
    department_id: filters.department_id && filters.department_id > 0
      ? filters.department_id
      : null,
    assignee_membership_id: filters.assignee_membership_id
      && filters.assignee_membership_id > 0
      ? filters.assignee_membership_id
      : null,
    client_id: filters.client_id && filters.client_id > 0 ? filters.client_id : null,
    scope
  }
  if (perPage !== 10) payload.per_page = perPage
  return payload
}

/**
 * Payload → patch de WorkQueueFilters (page sempre 1 ao aplicar).
 */
export function workQueuePayloadToFilters(
  payload: SavedListFilterPayload | null | undefined
): Pick<
  WorkQueueFilters,
  'tab' | 'q' | 'department_id' | 'assignee_membership_id' | 'client_id' | 'scope' | 'page' | 'per_page'
> {
  const defaults = {
    tab: 'open',
    q: '',
    department_id: null as number | null,
    assignee_membership_id: null as number | null,
    client_id: null as number | null,
    scope: 'default',
    page: 1,
    per_page: 10
  }
  if (!isRecord(payload)) return defaults
  const perPageRaw = Number(payload.per_page)
  return {
    tab: asString(payload.tab, 'open') || 'open',
    q: asString(payload.q, '').trim(),
    department_id: asPositiveIntOrNull(payload.department_id),
    assignee_membership_id: asPositiveIntOrNull(payload.assignee_membership_id),
    client_id: asPositiveIntOrNull(payload.client_id),
    scope: asString(payload.scope, 'default') || 'default',
    page: 1,
    per_page: Number.isFinite(perPageRaw)
      ? Math.min(100, Math.max(1, perPageRaw))
      : 10
  }
}

export function hasWorkQueuePayloadContent(
  payload: WorkQueueSavedFilterPayload | null | undefined
): boolean {
  if (!payload) return false
  if (payload.tab && payload.tab !== 'open') return true
  if (String(payload.q || '').trim()) return true
  if (payload.department_id) return true
  if (payload.assignee_membership_id) return true
  if (payload.client_id) return true
  if (payload.scope && payload.scope !== 'default') return true
  if (payload.per_page && payload.per_page !== 10) return true
  return false
}

export function hasActiveWorkQueueFiltersForSave(filters: WorkQueueFilters): boolean {
  return hasWorkQueuePayloadContent(workQueueFiltersToPayload(filters))
}

// ── work.processes ────────────────────────────────────────────────────────

export interface WorkProcessesFilterState {
  q: string
  competence: string
  status: string
  client_id: number | null
  department_id: number | null
  /** `client` = grupos por cliente; omitido/null = Processo/rotina. */
  group?: 'client' | null
}

export function emptyWorkProcessesFilters(): WorkProcessesFilterState {
  return {
    q: '',
    competence: '',
    status: 'all',
    client_id: null,
    department_id: null,
    group: null
  }
}

export function workProcessesFiltersToPayload(
  state: WorkProcessesFilterState
): WorkProcessesSavedFilterPayload {
  const payload: WorkProcessesSavedFilterPayload = {
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    q: String(state.q ?? '').trim(),
    competence: String(state.competence ?? '').trim(),
    status: state.status && state.status !== 'all' ? state.status : 'all',
    client_id: asPositiveIntOrNull(state.client_id),
    department_id: asPositiveIntOrNull(state.department_id)
  }
  if (state.group === 'client') {
    payload.group = 'client'
  }
  return payload
}

export function workProcessesPayloadToFilters(
  payload: SavedListFilterPayload | null | undefined
): WorkProcessesFilterState {
  const empty = emptyWorkProcessesFilters()
  if (!isRecord(payload)) return empty
  const status = asString(payload.status, 'all')
  return {
    q: asString(payload.q, '').trim(),
    competence: asString(payload.competence, '').trim(),
    status: status && status !== 'all' ? status : 'all',
    client_id: asPositiveIntOrNull(payload.client_id),
    department_id: asPositiveIntOrNull(payload.department_id),
    group: asString(payload.group, '') === 'client' ? 'client' : null
  }
}

export function hasWorkProcessesPayloadContent(
  payload: WorkProcessesSavedFilterPayload | null | undefined
): boolean {
  if (!payload) return false
  if (String(payload.q || '').trim()) return true
  if (String(payload.competence || '').trim()) return true
  if (payload.status && payload.status !== 'all') return true
  if (payload.client_id) return true
  if (payload.department_id) return true
  if (payload.group === 'client') return true
  return false
}

export function hasActiveWorkProcessesFiltersForSave(
  state: WorkProcessesFilterState
): boolean {
  return hasWorkProcessesPayloadContent(workProcessesFiltersToPayload(state))
}

// ── closing.list ──────────────────────────────────────────────────────────

export interface ClosingFilterState {
  competence: string
  band: string
  model: string
  root: string
  source: string
  client_id: string
}

export function emptyClosingFilters(defaultCompetence = ''): ClosingFilterState {
  return {
    competence: defaultCompetence,
    band: FILTER_ALL,
    model: FILTER_ALL,
    root: '',
    source: FILTER_ALL,
    client_id: ''
  }
}

export function closingFiltersToPayload(
  state: ClosingFilterState
): ClosingSavedFilterPayload {
  return {
    schema_version: SAVED_LIST_SCHEMA_VERSION,
    competence: String(state.competence ?? '').trim(),
    band: state.band && state.band !== FILTER_ALL ? state.band : FILTER_ALL,
    model: state.model && state.model !== FILTER_ALL ? state.model : FILTER_ALL,
    root: String(state.root ?? '').replace(/\D/g, '').slice(0, 8),
    source: state.source && state.source !== FILTER_ALL ? state.source : FILTER_ALL,
    client_id: String(state.client_id ?? '').trim()
  }
}

export function closingPayloadToFilters(
  payload: SavedListFilterPayload | null | undefined,
  defaultCompetence = ''
): ClosingFilterState {
  const empty = emptyClosingFilters(defaultCompetence)
  if (!isRecord(payload)) return empty
  const band = asString(payload.band, FILTER_ALL).toUpperCase()
  const model = asString(payload.model, FILTER_ALL)
  const source = asString(payload.source, FILTER_ALL).toUpperCase()
  const competence = asString(payload.competence, '').trim()
  return {
    competence: /^\d{4}-\d{2}$/.test(competence) ? competence : defaultCompetence,
    band: ['PLANNED', 'ATTENTION', 'CONTINGENCY', 'OVERDUE', FILTER_ALL].includes(band)
      ? band
      : FILTER_ALL,
    model: ['55', '65', 'NFE', 'NFCE', FILTER_ALL].includes(model) ? model : FILTER_ALL,
    root: asString(payload.root, '').replace(/\D/g, '').slice(0, 8),
    source: ['SVRS', 'AUTXML', 'MANUAL', 'PACKAGE', 'VAULT', FILTER_ALL].includes(source)
      ? source
      : FILTER_ALL,
    client_id: asString(payload.client_id, '').trim()
  }
}

export function hasClosingPayloadContent(
  payload: ClosingSavedFilterPayload | null | undefined
): boolean {
  if (!payload) return false
  // competência sozinha é o recorte base da tela — só conta como “ativo” se
  // houver outros eixos além dela (ou se quisermos sempre permitir salvar o mês).
  // Permitir salvar com competência preenchida (uso principal da tela).
  if (String(payload.competence || '').trim()) return true
  if (payload.band && payload.band !== FILTER_ALL) return true
  if (payload.model && payload.model !== FILTER_ALL) return true
  if (String(payload.root || '').trim()) return true
  if (payload.source && payload.source !== FILTER_ALL) return true
  if (String(payload.client_id || '').trim()) return true
  return false
}

export function hasActiveClosingFiltersForSave(state: ClosingFilterState): boolean {
  return hasClosingPayloadContent(closingFiltersToPayload(state))
}

/** Parse defensivo de resposta API. */
export function parseSavedFilterPayload(raw: unknown): SavedListFilterPayload {
  if (!isRecord(raw)) return { schema_version: SAVED_LIST_SCHEMA_VERSION }
  return raw as SavedListFilterPayload
}

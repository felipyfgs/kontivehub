import type { DataTableFilterModel } from '~/types/data-table-filter'

/** Superfícies estáveis de presets (schema_version 1). */
export const SAVED_LIST_SURFACES = [
  'monitoring.simples_mei',
  'monitoring.dctfweb',
  'monitoring.installments',
  'monitoring.sitfis',
  'monitoring.declarations',
  'monitoring.fgts',
  'monitoring.guides',
  'monitoring.registrations',
  'monitoring.tax_processes',
  'monitoring.mailbox',
  'clients.index',
  'docs.catalog',
  'work.queue',
  'work.processes',
  'closing.list'
] as const

export type SavedListSurface = (typeof SAVED_LIST_SURFACES)[number]

export const SAVED_LIST_SCHEMA_VERSION = 1 as const

export type SavedFilterVisibility = 'personal' | 'office'

/** Payload monitoring v1: busca + chips (defaults vazios omitidos na serialização). */
export interface MonitoringSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  q: string
  filters: DataTableFilterModel[]
}

/** Payload clients.index v1. */
export interface ClientsSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  q: string
  /** 'all' | 'active' | 'inactive' — espelho do statusFilter da lista. */
  status: string
  /**
   * KPI operacional: total | with_credential | without_credential | expiring
   * | credential_expired | capture_problem
   */
  operational_filter: string
  /** CSV de ids; opcional para compatibilidade com presets anteriores. */
  category_ids?: string
  /** CSV de códigos canônicos; opcional para compatibilidade com presets anteriores. */
  tax_regimes?: string
  /** CSV de status projetados de procuração; opcional para compat. */
  procuracao_statuses?: string
}

/** Payload docs.catalog v1 — espelho enxuto de NotesFilterState (só chaves ativas). */
export interface DocsSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  q?: string
  kind?: string
  direction?: string
  client_id?: string
  establishment_id?: string
  issuer_cnpj?: string
  taker_cnpj?: string
  fiscal_role?: string
  acquisition_source?: string
  artifact_quality?: string
  coverage_status?: string
  competence?: string
  issued_from?: string
  issued_to?: string
  status?: string
  missing_party_name?: string
}

/** Payload work.queue v1 (sem page — apply sempre volta à página 1). */
export interface WorkQueueSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  tab: string
  q: string
  department_id: number | null
  assignee_membership_id: number | null
  client_id: number | null
  scope: string
  per_page?: number
}

/** Payload work.processes v1. */
export interface WorkProcessesSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  q: string
  competence: string
  /** 'all' ou status do processo. */
  status: string
  client_id: number | null
  department_id: number | null
  /** Agrupamento: só `client` é persistido; omitido = Processo/rotina. */
  group?: 'client'
}

/** Payload closing.list v1. */
export interface ClosingSavedFilterPayload {
  schema_version: typeof SAVED_LIST_SCHEMA_VERSION
  competence: string
  band: string
  model: string
  root: string
  source: string
  client_id: string
}

/** Payload genérico serializado (JSONB). */
export type SavedListFilterPayload
  = | MonitoringSavedFilterPayload
    | ClientsSavedFilterPayload
    | DocsSavedFilterPayload
    | WorkQueueSavedFilterPayload
    | WorkProcessesSavedFilterPayload
    | ClosingSavedFilterPayload
    | Record<string, unknown>

export interface SavedListFilter {
  id: number
  surface: string
  name: string
  visibility: SavedFilterVisibility
  schema_version: number
  payload: SavedListFilterPayload
  user_id?: number
  /** Nome do autor (listagem office). */
  author_name?: string | null
  can_edit?: boolean
  can_delete?: boolean
  can_share?: boolean
  created_at?: string
  updated_at?: string
}

export interface CreateSavedListFilterBody {
  surface: string
  name: string
  visibility: SavedFilterVisibility
  payload: SavedListFilterPayload
  schema_version?: number
}

export interface UpdateSavedListFilterBody {
  name?: string
  visibility?: SavedFilterVisibility
  payload?: SavedListFilterPayload
  schema_version?: number
}

/** Mapa moduleKey / nav → surface de monitoring. */
export const MONITORING_MODULE_SURFACES: Record<string, SavedListSurface> = {
  simples_mei: 'monitoring.simples_mei',
  dctfweb: 'monitoring.dctfweb',
  installments: 'monitoring.installments',
  sitfis: 'monitoring.sitfis',
  declarations: 'monitoring.declarations',
  fgts: 'monitoring.fgts',
  guides: 'monitoring.guides',
  registrations: 'monitoring.registrations',
  tax_processes: 'monitoring.tax_processes',
  mailbox: 'monitoring.mailbox'
}

export function isSavedListSurface(value: string): value is SavedListSurface {
  return (SAVED_LIST_SURFACES as readonly string[]).includes(value)
}

export function isMonitoringSurface(surface: string): boolean {
  return surface.startsWith('monitoring.')
}

export function resolveMonitoringSurface(
  moduleKey?: string | null
): SavedListSurface | null {
  if (!moduleKey) return null
  return MONITORING_MODULE_SURFACES[moduleKey] ?? null
}

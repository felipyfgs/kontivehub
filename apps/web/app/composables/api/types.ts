import type { DocumentDirection, FiscalRole, InboxItemType, InboxSeverity } from '~/types/api'

export interface InboxListParams {
  severity?: InboxSeverity | ''
  type?: InboxItemType | ''
  limit?: number
  cursor?: string
}

export interface ClientListParams {
  q?: string
  page?: number
  per_page?: number
  /** Inclui agregados leves usados exclusivamente no dashboard de clientes. */
  dashboard?: boolean | 0 | 1
  /** Filtro de estado no escritório (true/false) */
  is_active?: boolean | 0 | 1
  operational_filter?:
    | 'with_credential'
    | 'without_credential'
    | 'expiring'
    | 'credential_expired'
    | 'capture_problem'
  category_ids?: string
  tax_regimes?: string
  /** CSV: authorized|expiring|expired|missing|unverified */
  procuracao_statuses?: string
  sort?: 'legal_name' | 'cnpj' | 'is_active' | 'tax_regime' | 'created_at'
  direction?: 'asc' | 'desc'
}

export interface NoteListParams {
  /** Busca de triagem: número, nome, CNPJ ou chave. */
  q?: string
  /** Tipo DF-e (NFSE, NFE, CTE, …). */
  kind?: string
  /** @deprecated Preferir `q`; mantido para compat. */
  access_key?: string
  issuer_cnpj?: string
  taker_cnpj?: string
  competence?: string
  status?: string
  fiscal_role?: FiscalRole | ''
  acquisition_source?: string
  artifact_quality?: string
  coverage_status?: string
  /** Entrada (IN) / Saída (OUT) / Indefinida. */
  direction?: DocumentDirection | ''
  client_id?: number
  establishment_id?: number
  issued_from?: string
  issued_to?: string
  /** Fila: falta nome de emitente ou tomador. */
  missing_party_name?: boolean | 0 | 1
  cursor?: string
  limit?: number
  page?: number
  per_page?: number
}

/** Cliente HTTP Sanctum (useSanctumClient). */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type ApiClient = <T = any>(url: string, options?: Record<string, any>) => Promise<T>

export type ApiUrl = (path: string) => string

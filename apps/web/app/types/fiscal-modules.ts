/**
 * Espelho TypeScript dos enums e DTOs de módulo / situação / cobertura / origem
 * e dos read models de carteira (overview + clients).
 *
 * Fonte canônica: backend App\Enums\Fiscal* e App\DTO\Fiscal\Module\*.
 * Discriminados por `module_key` — não usar Record<string, unknown> nos fluxos principais.
 */

// ---------------------------------------------------------------------------
// Enums / catálogos
// ---------------------------------------------------------------------------

/** Chaves de módulo da carteira (read model + rotas /monitoring). */
export type FiscalModuleKey
  = | 'dashboard'
    | 'simples_mei'
    | 'dctfweb'
    | 'installments'
    | 'sitfis'
    | 'mailbox'
    | 'declarations'
    | 'guides'
    | 'fgts'

/** Módulos com overview/carteira REST (exclui dashboard agregado). */
export type FiscalPortfolioModuleKey = Exclude<FiscalModuleKey, 'dashboard'>

export const FISCAL_MODULE_KEYS: readonly FiscalModuleKey[] = [
  'dashboard',
  'simples_mei',
  'dctfweb',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'fgts'
] as const

export const FISCAL_PORTFOLIO_MODULE_KEYS: readonly FiscalPortfolioModuleKey[] = [
  'simples_mei',
  'dctfweb',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'fgts'
] as const

export const FISCAL_MODULE_LABELS: Record<FiscalModuleKey, string> = {
  dashboard: 'Dashboard',
  simples_mei: 'Simples Nacional',
  dctfweb: 'DCTFWeb',
  installments: 'Parcelamentos',
  sitfis: 'Situação Fiscal',
  mailbox: 'Caixas Postais',
  declarations: 'Declarações',
  guides: 'Guias',
  fgts: 'FGTS Digital'
}

export const FISCAL_MODULE_PATHS: Record<FiscalModuleKey, string> = {
  dashboard: '/monitoring',
  /** Path = item da sidebar (MEI desacoplado em /monitoring/mei). */
  simples_mei: '/monitoring/simples',
  dctfweb: '/monitoring/dctfweb',
  installments: '/monitoring/installments',
  sitfis: '/monitoring/sitfis',
  mailbox: '/monitoring/mailbox',
  declarations: '/monitoring/declarations',
  guides: '/monitoring/guides',
  fgts: '/monitoring/fgts'
}

/** Segmento de rota API (`/fiscal/modules/{segment}/…`). */
export const FISCAL_MODULE_API_SEGMENTS: Record<FiscalPortfolioModuleKey, string> = {
  simples_mei: 'simples_mei',
  dctfweb: 'dctfweb',
  installments: 'installments',
  sitfis: 'sitfis',
  mailbox: 'mailbox',
  declarations: 'declarations',
  guides: 'guides',
  fgts: 'fgts'
}

export interface FiscalRegistrationLink {
  id: number
  client_id: number
  link_key: string
  status: string
  evidence_version?: string | null
  source_provenance?: string | null
  is_simulated?: boolean
  refreshed_at?: string | null
  observed_at?: string | null
}

export interface FiscalTaxProcess {
  id: number
  client_id: number
  process_number: string
  status: string
  evidence_version?: string | null
  source_provenance?: string | null
  is_simulated?: boolean
  refreshed_at?: string | null
  observed_at?: string | null
}

export interface FiscalPnrRenunciation {
  id: number
  client_id: number
  renunciation_id: number
  status: string
  source_provenance?: string | null
  occurred_at?: string | null
  observed_at?: string | null
  refreshed_at?: string | null
  receipt?: { mime_type?: string | null, byte_size?: number | null, observed_at?: string | null } | null
}

/** Situação fiscal — espelho de App\Enums\FiscalSituation. */
export type FiscalSituationCode
  = | 'UP_TO_DATE'
    | 'PENDING'
    | 'PROCESSING'
    | 'ATTENTION'
    | 'ERROR'
    | 'NOT_APPLICABLE'
    | 'UNKNOWN'
    | 'UNSUPPORTED'
    | 'BLOCKED'

export const FISCAL_SITUATION_CODES: readonly FiscalSituationCode[] = [
  'UP_TO_DATE',
  'PENDING',
  'PROCESSING',
  'ATTENTION',
  'ERROR',
  'NOT_APPLICABLE',
  'UNKNOWN',
  'UNSUPPORTED',
  'BLOCKED'
] as const

/** Cobertura — espelho de App\Enums\FiscalCoverage. */
export type FiscalCoverageCode
  = | 'FULL'
    | 'PARTIAL'
    | 'UNSUPPORTED'
    | 'UNKNOWN'
    | 'NOT_APPLICABLE'

export const FISCAL_COVERAGE_CODES: readonly FiscalCoverageCode[] = [
  'FULL',
  'PARTIAL',
  'UNSUPPORTED',
  'UNKNOWN',
  'NOT_APPLICABLE'
] as const

/** Origem do dado — espelho de App\Enums\FiscalDataOrigin. */
export type FiscalDataOrigin = 'DEMO' | 'SIMULATED' | 'TRIAL' | 'LIVE'

export const FISCAL_DATA_ORIGINS: readonly FiscalDataOrigin[] = [
  'DEMO',
  'SIMULATED',
  'TRIAL',
  'LIVE'
] as const

export function isSyntheticFiscalOrigin(origin?: string | null): boolean {
  const v = String(origin || '').toUpperCase()
  return v === 'DEMO' || v === 'SIMULATED' || v === 'TRIAL'
}

export function isFiscalPortfolioModule(value: string): value is FiscalPortfolioModuleKey {
  return (FISCAL_PORTFOLIO_MODULE_KEYS as readonly string[]).includes(value)
}

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------

/** Contadores da partição completa (9 situações canônicas). Chaves ausentes → 0. */
export interface FiscalModuleCounters {
  up_to_date: number
  processing: number
  pending: number
  attention: number
  error: number
  blocked: number
  unknown: number
  unsupported: number
  not_applicable: number
}

export const EMPTY_FISCAL_MODULE_COUNTERS: Readonly<FiscalModuleCounters> = Object.freeze({
  up_to_date: 0,
  processing: 0,
  pending: 0,
  attention: 0,
  error: 0,
  blocked: 0,
  unknown: 0,
  unsupported: 0,
  not_applicable: 0
})

/** Normaliza contadores parciais (deploy escalonado / fixtures legados). */
export function normalizeFiscalModuleCounters(
  value?: Partial<FiscalModuleCounters> | null
): FiscalModuleCounters {
  return {
    up_to_date: Number(value?.up_to_date) || 0,
    processing: Number(value?.processing) || 0,
    pending: Number(value?.pending) || 0,
    attention: Number(value?.attention) || 0,
    error: Number(value?.error) || 0,
    blocked: Number(value?.blocked) || 0,
    unknown: Number(value?.unknown) || 0,
    unsupported: Number(value?.unsupported) || 0,
    not_applicable: Number(value?.not_applicable) || 0
  }
}

export interface FiscalModuleAgendaItem {
  client_id?: number | null
  label?: string | null
  due_at?: string | null
  situation?: string | null
  href?: string | null
}

export interface FiscalModuleCategorySummary {
  id: number
  code: string
  name: string
  default_coverage?: string | null
  linked_clients: number
}

/** KPI acionável da faixa (Total + nove situações canônicas). */
export type FiscalKpiKey
  = | 'total'
    | 'up_to_date'
    | 'processing'
    | 'pending'
    | 'attention'
    | 'error'
    | 'blocked'
    | 'unknown'
    | 'unsupported'
    | 'not_applicable'

/** Chaves de contador (sem Total). */
export const FISCAL_COUNTER_KPI_KEYS = [
  'up_to_date',
  'processing',
  'pending',
  'attention',
  'error',
  'blocked',
  'unknown',
  'unsupported',
  'not_applicable'
] as const satisfies readonly Exclude<FiscalKpiKey, 'total'>[]

export interface FiscalModuleMetrics {
  total_clients?: number
  tab_counts?: Record<string, number>
  partial_coverage?: boolean
  guide_payment_supported?: boolean
  open_messages?: number
  unconfirmed_payment_guides?: number
}

/** Motivo público de indisponibilidade de documento (espelho DocumentUnavailableReason). */
export type FiscalDocumentUnavailableReason
  = | 'STRUCTURED_ONLY'
    | 'PROCESSING'
    | 'NOT_SUPPORTED'
    | 'NOT_PRODUCTION'
    | 'NOT_COLLECTED'
    | 'NOT_AVAILABLE'
    | 'EXPIRED'
    | 'INTEGRITY_REJECTED'

/**
 * Descritor público de documento/evidência tenant-scoped.
 * href só vem do backend; UI NÃO monta URL por convenção de módulo.
 */
export interface FiscalDocumentDescriptor {
  available: boolean
  kind: 'PDF' | string | null
  label: string | null
  content_type: string | null
  observed_at: string | null
  source_surface: string | null
  source_label: string | null
  href: string | null
  unavailable_reason: FiscalDocumentUnavailableReason | string | null
}

/** Tipo de retorno da superfície (page-payload-matrix / MonitoringResultKind). */
export type FiscalMonitoringResultKind
  = | 'STRUCTURED'
    | 'PDF'
    | 'ASYNC_PDF'
    | 'AGGREGATE'
    | 'UNAVAILABLE'

/** Classe pública da action; somente READ pode ser executada pelo workspace. */
export type FiscalMonitoringOperationClass
  = | 'READ'
    | 'DOCUMENT_GENERATION'
    | 'FISCAL_MUTATION'

export type FiscalMonitoringDocumentPolicy
  = | 'NEVER'
    | 'WHEN_ARTIFACT'
    | 'ASYNC_WHEN_READY'

/** Estado público uniforme das consultas do workspace. */
export type FiscalMonitoringQueryState
  = | 'IDLE'
    | 'QUEUED'
    | 'PROCESSING'
    | 'READY'
    | 'NO_DATA'
    | 'FAILED'
    | 'BLOCKED'
    | 'UNSUPPORTED'

export type FiscalMonitoringFreshnessState = 'FRESH' | 'STALE' | 'UNKNOWN'

export interface FiscalMonitoringFreshness {
  state: FiscalMonitoringFreshnessState
  age_seconds: number | null
  ttl_seconds: number
}

export interface FiscalMonitoringSnapshotReference {
  snapshot_id: number
  observed_at: string | null
  source_provenance: string
  coverage: FiscalCoverageCode | string
  freshness: FiscalMonitoringFreshness
}

export interface FiscalMonitoringQueryProjection {
  state: FiscalMonitoringQueryState
  /** Alias transitório emitido pelo backend; deve coincidir com state. */
  status: FiscalMonitoringQueryState
  state_label: string
  observed_at: string | null
  source_provenance: string
  coverage: FiscalCoverageCode | string
  reason_code: string | null
  run_id: number | null
  freshness: FiscalMonitoringFreshness
  last_snapshot: FiscalMonitoringSnapshotReference | null
  has_preserved_snapshot: boolean
}

export interface MonitoringCoverageParameterField {
  name: string
  type: string
  required: boolean
  label: string
  pattern?: string | null
}

export interface MonitoringCoverageAction {
  action_key: string
  label: string
  operation_class: FiscalMonitoringOperationClass
  params_schema: MonitoringCoverageParameterField[]
  result_kind: FiscalMonitoringResultKind
  document_policy: FiscalMonitoringDocumentPolicy
  available: boolean
  official_state: string
  source_label: string
  async: boolean
  output_fields: MonitoringCoverageOutputField[]
  trial_scenario_available: boolean
  request_documented: boolean
  response_documented: boolean
}

export interface MonitoringCoverageCapability {
  capability_key: string
  label: string
  actions_total: number
  available_actions: number
  actions: MonitoringCoverageAction[]
}

/**
 * Resumo público da superfície no overview (`data.surface`).
 * Sem idSistema/idServico/operation_key.
 */
export interface FiscalMonitoringSurfaceSummary {
  surface_key: string
  route: string
  responsibility: string
  result_kind: FiscalMonitoringResultKind | string
  allows_document: boolean
  official_state_label: string
  channel_label: string
  source_label: string
  capabilities?: MonitoringCoverageCapability[]
}

export interface MonitoringCoverageOutputField {
  name: string
  type: string
}

export interface MonitoringCoverageOperation {
  action_key: string
  label: string
  route: string
  official_state: string
  is_mutating: boolean
  trial_scenario_available: boolean
  request_documented: boolean
  response_documented: boolean
  output_fields: MonitoringCoverageOutputField[]
}

export interface MonitoringCoverageSurface extends FiscalMonitoringSurfaceSummary {
  capabilities: MonitoringCoverageCapability[]
  operations_total: number
  production_operations: number
  mutating_operations: number
  trial_scenarios: number
  operations: MonitoringCoverageOperation[]
}

export interface MonitoringCoverageContract {
  manifest_version: string
  verified_at: string
  truth_note: string
  totals: {
    surfaces: number
    catalog_operations: number
    surface_operations: number
    trial_scenarios: number
  }
  surfaces: MonitoringCoverageSurface[]
}

export type DeclarationCoverageState
  = | 'FULL'
    | 'PARTIAL'
    | 'INVENTORIED'
    | 'UNSUPPORTED'

export interface DeclarationIntegrationCoverageEntry {
  code: DeclarationsSubmodule
  label: string
  source_kind: 'INTEGRA_CONTADOR' | 'EXTERNAL'
  source_label: string
  coverage: DeclarationCoverageState | string
  monitoring_supported: boolean
  transmission_supported: boolean
  operations_total: number
  implemented_operations: number
  inventoried_operations: number
  routes: string[]
  verified_at: string
  note: string
}

export interface DeclarationIntegrationCoverage {
  manifest_version: string
  verified_at: string
  truth_note: string
  obligations: DeclarationIntegrationCoverageEntry[]
}

export type DeclarationOperationFlow = 'READ' | 'MUTATION'
export type DeclarationOperationAvailability
  = | 'AVAILABLE'
    | 'CONTROLLED'
    | 'PROSPECTION'
    | 'NOT_IMPLEMENTED'

export interface DeclarationOperationParam {
  name: string
  type: 'string' | 'integer' | 'month' | 'date' | 'object' | 'array' | 'base64'
  required: boolean
  label: string
  format?: string
  help?: string
}

export interface DeclarationOperation {
  action_id: string
  obligation: DeclarationsSubmodule
  label: string
  official_route: string
  flow: DeclarationOperationFlow
  official_state: 'PRODUCTION' | 'PROSPECTION' | string
  implementation_state: string
  availability: DeclarationOperationAvailability | string
  executable: boolean
  requires_preflight: boolean
  is_billable: boolean
  async: boolean
  params: DeclarationOperationParam[]
  result_kind: 'STRUCTURED' | 'DOCUMENT' | string
}

export interface DeclarationOperationCatalog {
  verified_at: string
  counts: {
    total: number
    production: number
    prospection: number
    read: number
    mutation: number
    production_read: number
    production_mutation: number
    executable: number
  }
  operations: DeclarationOperation[]
}

export interface DeclarationOperationPreflight {
  action_id: string
  eligible: boolean
  replayed?: boolean
  confirmation_required?: boolean
  confirmation_phrase?: string | null
  effect?: string | null
  competence?: string | null
  eligibility?: {
    allowed?: boolean
    codes?: string[]
    primary_code?: string | null
    messages?: string[]
  } | null
  cost_estimate?: Record<string, unknown> | null
  estimated_cost_micros?: number | null
  preflight_token?: string | null
  preflight_expires_at?: string | null
  idempotency_key?: string | null
  mutation_operation_id?: number | null
  status?: string | null
  denial_code?: string | null
  denial_message?: string | null
  codes?: string[]
}

export interface DeclarationOperationMutation {
  id: number
  client_id: number
  action_id: string
  status: string
  status_label?: string | null
  competence_period_key?: string | null
  effect_summary?: string | null
  confirmation_phrase?: string | null
  preflight_token?: string | null
  preflight_expires_at?: string | null
  result_code?: string | null
  result_message?: string | null
  denial_code?: string | null
  denial_message?: string | null
  evidence_ref?: string | null
  external_correlation?: string | null
  allows_reconciliation?: boolean
  is_terminal?: boolean
  is_uncertain?: boolean
  created_at?: string | null
  updated_at?: string | null
}

export interface DeclarationOperationReadResult {
  action_id: string
  eligibility: string
  async: boolean
  module_route?: string | null
  result?: Record<string, unknown> | null
  serpro_call?: string | null
}

export interface DeclarationCatalogPayload {
  obligations: Array<Record<string, unknown>>
  calendar: Record<string, unknown> | null
  integration_coverage: DeclarationIntegrationCoverage
  operation_catalog: DeclarationOperationCatalog
}

/** true somente com artefato real: available + href não vazio. */
export function documentActionVisible(
  doc?: FiscalDocumentDescriptor | null
): boolean {
  if (!doc || doc.available !== true) return false
  if (doc.unavailable_reason != null && String(doc.unavailable_reason).trim() !== '') return false
  const href = typeof doc.href === 'string' ? doc.href.trim() : ''
  return href.length > 0
}

/** Rótulo público do motivo de indisponibilidade; null se desconhecido/ausente. */
export function documentUnavailableLabel(
  reason?: FiscalDocumentUnavailableReason | string | null
): string | null {
  if (reason == null || String(reason).trim() === '') return null
  switch (String(reason).trim().toUpperCase()) {
    case 'STRUCTURED_ONLY':
      return 'Somente dados estruturados'
    case 'PROCESSING':
      return 'Processando'
    case 'NOT_SUPPORTED':
      return 'Documento não suportado'
    case 'NOT_PRODUCTION':
      return 'Operação não produtiva'
    case 'NOT_COLLECTED':
      return 'Documento ainda não coletado'
    case 'NOT_AVAILABLE':
      return 'Documento não disponível'
    case 'EXPIRED':
      return 'Documento expirado pela política de retenção'
    case 'INTEGRITY_REJECTED':
      return 'Documento rejeitado pela verificação de integridade'
    default:
      return null
  }
}

export function isSurfaceUnavailable(
  surface?: FiscalMonitoringSurfaceSummary | null
): boolean {
  return String(surface?.result_kind || '').toUpperCase() === 'UNAVAILABLE'
}

/** Superfície proíbe botão de documento (MIT, mailbox, cadastros, e-Processo…). */
export function surfaceAllowsDocument(
  surface?: FiscalMonitoringSurfaceSummary | null
): boolean {
  if (!surface) return true
  return surface.allows_document === true
}

export interface FiscalModuleOverviewBase<M extends FiscalPortfolioModuleKey = FiscalPortfolioModuleKey> {
  module_key: M
  module_label?: string
  data_origin?: FiscalDataOrigin | string | null
  data_origin_label?: string | null
  is_synthetic?: boolean
  coverage?: string | null
  source_label?: string | null
  as_of?: string | null
  total_clients: number
  counters: FiscalModuleCounters
  agenda?: FiscalModuleAgendaItem[]
  categories?: FiscalModuleCategorySummary[]
  metrics?: FiscalModuleMetrics
  /** Resumo público da superfície (result_kind, allows_document…). */
  surface?: FiscalMonitoringSurfaceSummary | null
}

export type FiscalModuleOverview<M extends FiscalPortfolioModuleKey = FiscalPortfolioModuleKey>
  = FiscalModuleOverviewBase<M>

// ---------------------------------------------------------------------------
// Client rows (carteira) — detail discriminado por module_key
// ---------------------------------------------------------------------------

export interface FiscalClientRowBase<
  M extends FiscalPortfolioModuleKey,
  D extends FiscalModuleClientDetail
> {
  module_key: M
  client_id: number
  legal_name: string
  display_name?: string | null
  /** display_name || legal_name */
  name?: string | null
  /** CNPJ normalizado (14 chars) para exibição/cópia; cnpj_masked permanece por compat. */
  cnpj?: string | null
  cnpj_masked: string
  root_cnpj_masked?: string | null
  competence?: string | null
  situation: string
  coverage: string
  data_origin?: FiscalDataOrigin | string | null
  last_consulted_at?: string | null
  /** Último snapshot/consulta lógica (alias operacional). */
  last_snapshot_at?: string | null
  /** Próxima execução automática do monitor (office+monitor policy). */
  next_scheduled_at?: string | null
  next_deadline_at?: string | null
  next_action?: string | null
  /** Snapshot considerado recente (UI pede confirmação antes de refresh). */
  is_recent_snapshot?: boolean
  /** Estado oficial de procuração (sync e-CAC). */
  procuracao_status?: 'authorized' | 'missing' | 'expired' | 'unverified' | string | null
  /** Saldo comercial do monitor no período (franquia). */
  commercial_quota?: {
    remaining?: number | null
    limit?: number | null
    used?: number | null
    period_ends_at?: string | null
    block_reason?: string | null
  } | null
  /** Motivo de bloqueio acionável (procuração, franquia, flag…) — sem stack técnico. */
  block_reason?: string | null
  block_message?: string | null
  links?: Record<string, string | null | undefined>
  /**
   * Descritor de documento/evidência (aditivo).
   * Botão só com documentActionVisible(document).
   */
  document?: FiscalDocumentDescriptor | null
  detail: D
}

export type PgdasdDeclarationState
  = | 'CURRENT'
    | 'DUE_WITHIN_DEADLINE'
    | 'OVERDUE_NOT_FOUND'
    | 'UNVERIFIED'

export type PgdasdDasPaymentState
  = | 'PAID'
    | 'UNPAID'
    | 'NO_DAS'
    | 'UNVERIFIED'

export type PgdasdRbt12Status
  = | 'PENDING'
    | 'PARSED'
    | 'NO_DAS'
    | 'NOT_FOUND'
    | 'AMBIGUOUS'
    | 'FAILED'

export type PgdasdTrackingStatus
  = | 'NOT_CONFIGURED'
    | 'NO_HISTORY'
    | 'SCHEDULED'
    | 'QUEUED'
    | 'ACCEPTED'
    | 'SENT'
    | 'DELIVERED'
    | 'READ'
    | 'PARTIAL'
    | 'FAILED'
    | 'UNKNOWN'
    | 'SKIPPED_NO_DOCUMENT'
    | 'CANCELED'

export type PgdasdCommunicationChannel = 'EMAIL' | 'WHATSAPP'

export interface PgdasdLatestDeclaration {
  period_key?: string | null
  declaration_number?: string | null
  number?: string | null
  operation_type?: string | null
  transmitted_at?: string | null
}

export interface PgdasdRbt12Summary {
  status?: PgdasdRbt12Status | string | null
  period_key?: string | null
  /** Valor monetário formatável (string decimal) — preferido na API atual. */
  rbt12_value?: string | null
  total_cents?: number | null
  internal_market_cents?: number | null
  external_market_cents?: number | null
  /** RPA do extrato (receita do PA) — informativo; não é o valor do chip RBT12. */
  rpa_cents?: number | null
  composition?: {
    internal_market_cents?: number | null
    external_market_cents?: number | null
    total_cents?: number | null
  } | null
  origin?: {
    das_number?: string | null
    declaration_number?: string | null
    declaration_transmitted_at?: string | null
  } | null
  parser_version?: string | null
  extracted_at?: string | null
  availability_reason?: string | null
  unavailable_reason?: string | null
}

/** Preferência pública de comunicação (canais + intenção automática + elegibilidade de envio). */
export interface PgdasdCommunicationPreference {
  client_id?: number | null
  automatic_requested: boolean
  automatic_effective: boolean
  email_enabled: boolean
  whatsapp_enabled: boolean
  lock_version: number
  execution_mode: 'TEMPLATE_ONLY' | 'WHATSAPP_NATIVE'
  eligible_channels?: PgdasdCommunicationChannel[]
  tracking_status?: PgdasdTrackingStatus | string | null
  /** Canal + docs elegíveis — UI pode habilitar Send mesmo com provider off (fila). */
  can_send?: boolean
  /** Provider externo ligado (config fail-closed). */
  provider_enabled?: boolean
}

export interface PgdasdPaymentOpenCompetency {
  period_key: string
  amount_cents?: number | null
}

export interface PgdasdClientSummary {
  expected_period_key?: string | null
  latest_declaration?: PgdasdLatestDeclaration | null
  declaration_state?: PgdasdDeclarationState | string | null
  declaration_state_reason?: string | null
  /** Alias transitório aceito durante deploy escalonado. */
  declaration_reason?: string | null
  /** Pagamento dos DAS do PA esperado (eixo ortogonal à entrega; UI: coluna Situação). */
  payment_state?: PgdasdDasPaymentState | string | null
  payment_state_reason?: string | null
  payment_das_count?: number | null
  payment_unpaid_count?: number | null
  payment_paid_count?: number | null
  /** Competências com DAS unpaid no histórico local do cliente (não só o PA esperado). */
  payment_open_competencies?: PgdasdPaymentOpenCompetency[]
  last_valid_query_at?: string | null
  rbt12?: PgdasdRbt12Summary | null
  /** Documentos PGDAS-D já persistidos; nunca contém bytes ou referência ao cofre. */
  documents?: PgdasdArtifactDescriptor[]
  communication?: PgdasdCommunicationPreference | null
}

export type PgmeiDebtState
  = | 'HAS_ACTIVE_DEBT'
    | 'NO_ACTIVE_DEBT'
    | 'UNVERIFIED'

export type PgmeiFreshnessState = 'CURRENT' | 'OUTDATED'

/**
 * Projeção local da última consulta válida do DIVIDAATIVA24 para um ano.
 * `NO_ACTIVE_DEBT` vale somente para `year`; a UI nunca o generaliza para outros anos.
 */
export interface PgmeiClientSummary {
  year: number
  debt_state: PgmeiDebtState | string
  freshness_state: PgmeiFreshnessState | string
  debt_count: number
  total_cents: number
  last_valid_query_at?: string | null
  communication?: PgdasdCommunicationPreference | null
}

export interface PgmeiDebtItem {
  id?: number | null
  period_key?: string | null
  tribute?: string | null
  amount_cents: number
  federated_entity?: string | null
  original_status?: string | null
  /** Aliases aceitos durante rollout aditivo do backend. */
  periodo_apuracao?: string | null
  tributo?: string | null
  ente_federado?: string | null
  situacao_original?: string | null
}

export interface PgmeiLocalGuideDescriptor {
  id: number
  period_key?: string | null
  label?: string | null
  status?: string | null
  amount_cents?: number | null
  due_at?: string | null
  href?: string | null
  download_href?: string | null
}

export interface PgmeiDebtObservation {
  id?: number | null
  year: number
  debt_state?: PgmeiDebtState | string | null
  freshness_state?: PgmeiFreshnessState | string | null
  debt_count?: number | null
  total_cents?: number | null
  observed_at?: string | null
  queried_at?: string | null
  items?: PgmeiDebtItem[]
}

export interface PgmeiHistoryPayload {
  client?: {
    id?: number | null
    legal_name?: string | null
    cnpj_masked?: string | null
  } | null
  year?: number | null
  current?: PgmeiClientSummary | null
  observations?: PgmeiDebtObservation[]
  history?: PgmeiDebtObservation[]
  guides?: PgmeiLocalGuideDescriptor[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Histórico local sanitizado de CCMEI/DADOSCCMEI122. */
export interface CcmeiCertificateSummary {
  status: string
  situation: string
  last_valid_query_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface CcmeiCertificateObservation {
  id: number
  status: string
  situation: string
  observed_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface CcmeiHistoryPayload {
  client_id: number
  current?: CcmeiCertificateSummary | null
  history?: CcmeiCertificateObservation[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Descritor sanitizado de certificado emitido por CCMEI121. */
export interface CcmeiIssuedCertificate {
  id: number
  mime_type: 'application/pdf' | string
  byte_size: number
  source_provenance?: 'SERPRO_TRIAL' | 'SERPRO_REAL' | string | null
  observed_at?: string | null
}

export interface CcmeiIssuedCertificateHistoryPayload {
  client_id: number
  certificates?: CcmeiIssuedCertificate[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Descritor sanitizado de comprovante PAGTOWEB 7.2. */
export interface PagtowebArrecadacaoReceipt {
  id: number
  mime_type: 'application/pdf' | string
  byte_size: number
  source_provenance?: 'SERPRO_TRIAL' | 'SERPRO_REAL' | string | null
  observed_at?: string | null
}

export interface PagtowebArrecadacaoReceiptHistoryPayload {
  client_id: number
  items?: PagtowebArrecadacaoReceipt[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Histórico local sanitizado da situação cadastral CCMEISITCADASTRAL123. */
export interface CcmeiRegistrationStatusSummary {
  status: string
  enquadrado_mei: boolean
  situation: string
  count: number
  observed_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface CcmeiRegistrationStatusHistoryPayload {
  client_id: number
  current?: CcmeiRegistrationStatusSummary | null
  history?: CcmeiRegistrationStatusSummary[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Metadados sanitizados do apoio de receita SICALC 5.2. */
export interface SicalcRevenueSupportSummary {
  revenue_code: string
  description: string
  extensions: Array<{
    obrigatorios: Record<string, boolean>
    opcionais: Record<string, boolean>
    informacoes: Record<string, boolean | string>
  }>
  extension_count: number
  observed_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface SicalcRevenueSupportHistoryPayload {
  client_id: number
  revenue_code?: string | null
  current?: SicalcRevenueSupportSummary[]
  history?: SicalcRevenueSupportSummary[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Agregado sanitizado da consulta PAGTOWEB 7.3; sem documentos individuais. */
export interface PagtowebPaymentCountSummary {
  payment_count: number
  filter_summary: Record<string, unknown>
  observed_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface PagtowebPaymentCountHistoryPayload {
  client_id: number
  current?: PagtowebPaymentCountSummary | null
  history?: PagtowebPaymentCountSummary[]
  provenance?: { source?: string | null, serpro_called?: boolean } | null
}

/** Item sanitizado de PAGTOWEB 7.1; o identificador original nunca chega à UI. */
export interface PagtowebPaymentListItem {
  document_masked: string
  document_type?: string | null
  revenue_code?: string | null
  revenue_description?: string | null
  paid_on?: string | null
  due_on?: string | null
  total_amount?: string | number | null
}

export interface PagtowebPaymentListHistoryPayload {
  client_id: number
  current?: {
    filter_summary?: Record<string, unknown>
    returned_count?: number
    observed_at?: string | null
    source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
  } | null
  items: PagtowebPaymentListItem[]
  meta: { page: number, per_page: number, total: number }
  provenance?: { source?: string | null, serpro_called?: boolean } | null
}

/** Histórico local da lista DEFIS retornada pelo serviço SERPRO 142. */
export interface DefisDeclarationItem {
  calendar_year: number
  declaration_type: '1' | '2' | '3' | '4'
  observed_at?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | string | null
}

export interface DefisDeclarationsHistoryPayload {
  client_id: number
  declarations: DefisDeclarationItem[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Descritor local da DEFIS 143; nunca contém PDF, Base64, hash ou idDefis. */
export interface DefisLatestDeclarationDocument {
  id: number
  calendar_year: number
  kind: 'RECIBO' | 'DECLARACAO'
  filename: string
  content_type: string
  byte_size?: number | null
  observed_at?: string | null
  download_path: string
}

export interface DefisLatestDeclarationHistoryPayload {
  client_id: number
  documents: DefisLatestDeclarationDocument[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Referência opaca local de uma declaração DEFIS; nunca é o identificador SERPRO. */
export interface DefisSpecificDeclarationReference {
  reference_id: number
  calendar_year: number
  declaration_type: '1' | '2' | '3' | '4'
  observed_at?: string | null
}

/** Descritor local da DEFIS 144; o PDF permanece no cofre. */
export interface DefisSpecificDeclarationDocument {
  id: number
  kind: 'RECIBO' | 'DECLARACAO'
  filename: string
  content_type: string
  byte_size?: number | null
  observed_at?: string | null
  download_path: string
}

export interface DefisSpecificDeclarationHistoryPayload {
  client_id: number
  references: DefisSpecificDeclarationReference[]
  documents: DefisSpecificDeclarationDocument[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Histórico local da consulta SERPRO 102, sem payload bruto. */
export interface RegimeCalendarItem {
  calendar_year: number
  regime_apuracao: 'COMPETENCIA' | 'CAIXA'
  observed_at?: string | null
  source_service?: string | null
}

export interface RegimeCalendarPayload {
  data: RegimeCalendarItem[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Histórico local da opção anual retornada pelo serviço SERPRO 103. */
export interface RegimeOptionItem {
  calendar_year: number
  regime_apuracao: 'COMPETENCIA' | 'CAIXA'
  observed_at?: string | null
}

export interface RegimeOptionPayload {
  data: RegimeOptionItem[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

/** Resolução 104 já armazenada localmente; nunca contém Base64 ou bytes. */
export interface RegimeResolutionItem {
  calendar_year: number
  observed_at?: string | null
  content_type?: string | null
  byte_size?: number | null
  available: boolean
  document: {
    available: boolean
    kind: 'TEXT'
    label: string
    content_type?: string | null
    observed_at?: string | null
    href?: string | null
  }
}

export interface RegimeResolutionPayload {
  data: RegimeResolutionItem[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

export interface PgdasdArtifactDescriptor {
  id: number
  kind?: string | null
  period_key?: string | null
  declaration_number?: string | null
  das_number?: string | null
  filename?: string | null
  content_type?: string | null
  byte_size?: number | null
  observed_at?: string | null
  /** URL same-origin entregue pela API para o download autenticado. */
  download_path?: string | null
  /** Compatibilidade transitória para contratos anteriores. */
  download_href?: string | null
}

export interface PgdasdHistoryDeclaration extends PgdasdLatestDeclaration {
  id?: number | null
  normalized_operation_type?: string | null
  malha?: string | boolean | null
  documents?: PgdasdArtifactDescriptor[]
}

export interface PgdasdHistoryDas {
  id?: number | null
  normalized_operation_type?: string | null
  das_number?: string | null
  issued_at?: string | null
  payment_located?: boolean | null
  payment_observed_at?: string | null
  documents?: PgdasdArtifactDescriptor[]
}

export interface PgdasdHistoryPeriod {
  period_key?: string | null
  declaration_state?: PgdasdDeclarationState | string | null
  declaration_reason?: string | null
  last_valid_query_at?: string | null
  declarations?: PgdasdHistoryDeclaration[]
  das?: PgdasdHistoryDas[]
  artifacts?: PgdasdArtifactDescriptor[]
  documents?: PgdasdArtifactDescriptor[]
  rbt12?: PgdasdRbt12Summary | null
}

export interface PgdasdHistoryPayload {
  client?: {
    id?: number | null
    legal_name?: string | null
    cnpj_masked?: string | null
  } | null
  expected_period_key?: string | null
  declaration_state?: PgdasdDeclarationState | string | null
  declaration_state_reason?: string | null
  last_valid_query_at?: string | null
  periods?: PgdasdHistoryPeriod[]
  history?: PgdasdHistoryPeriod[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

export interface PgdasdCommunicationPreview {
  client?: { id?: number | null, legal_name?: string | null } | null
  period_key?: string | null
  execution_mode: 'TEMPLATE_ONLY' | 'WHATSAPP_NATIVE'
  can_send: boolean
  automatic_effective?: boolean
  provider_enabled?: boolean
  channels?: Array<{
    channel: PgdasdCommunicationChannel | string
    enabled: boolean
    eligible: boolean
    recipients?: Array<{
      contact_id?: number | null
      name?: string | null
      masked?: string | null
    }>
  }>
  documents?: PgdasdArtifactDescriptor[]
  warnings?: string[]
  preferences?: PgdasdCommunicationPreference | null
}

export interface PgdasdCommunicationTracking {
  client_id?: number | null
  status: PgdasdTrackingStatus | string
  channels?: Array<{
    channel: PgdasdCommunicationChannel | string
    status: PgdasdTrackingStatus | string
    dispatches?: Array<{
      id?: number | null
      status?: PgdasdTrackingStatus | string | null
      recipient_masked?: string | null
      period_key?: string | null
      queued_at?: string | null
      sent_at?: string | null
      delivered_at?: string | null
      read_at?: string | null
      failed_at?: string | null
      canceled_at?: string | null
      events?: Array<{ status?: string | null, occurred_at?: string | null }>
    }>
  }>
}

export interface SimplesMeiClientDetail {
  module_key?: 'simples_mei' | string
  submodule?: string | null
  period_key?: string | null
  competence_id?: number | null
  pgdasd?: PgdasdClientSummary | null
  pgmei?: PgmeiClientSummary | null
  /** Campos espelhados no detail (além de detail.pgdasd) para tabela. */
  declaration_state?: PgdasdDeclarationState | string | null
  last_declaration?: PgdasdLatestDeclaration & {
    numero_declaracao?: string | null
    operation_kind?: string | null
  } | null
  payment_state?: PgdasdDasPaymentState | string | null
  payment_state_reason?: string | null
  payment_das_count?: number | null
  payment_unpaid_count?: number | null
  payment_paid_count?: number | null
  payment_open_competencies?: PgdasdPaymentOpenCompetency[]
  rbt12?: PgdasdRbt12Summary | null
  last_productive_consulted_at?: string | null
  /** Alias transitório dos documentos de detail.pgdasd durante deploy escalonado. */
  documents?: PgdasdArtifactDescriptor[]
  communication?: PgdasdCommunicationPreference | null
  links?: Record<string, string | null>
  /** Projeção oficial e-CAC (sem egress na listagem). */
  procuracao_status?: 'authorized' | 'expiring' | 'missing' | 'expired' | 'unverified' | 'verifying' | 'failed' | string | null
  procuracao_valid_to?: string | null
  procuracao_checked_at?: string | null
}

export type DctfwebDeclarationState
  = | 'CURRENT'
    | 'NO_MOVEMENT_VALID'
    | 'DUE_WITHIN_DEADLINE'
    | 'OVERDUE_NOT_FOUND'
    | 'UNVERIFIED'

export interface DctfwebLatestDeclaration {
  period_key?: string | null
  category?: string | null
  receipt_number?: string | null
  declaration_type?: string | null
  transmitted_at?: string | null
  no_movement?: boolean | null
  declaration_state?: DctfwebDeclarationState | string | null
  transmission_status?: string | null
}

export interface DctfwebClientSummary {
  expected_period_key?: string | null
  period_key?: string | null
  category?: string | null
  declaration_state?: DctfwebDeclarationState | string | null
  declaration_state_reason?: string | null
  last_declaration?: DctfwebLatestDeclaration | Record<string, unknown> | null
  latest_declaration?: DctfwebLatestDeclaration | null
  last_search_at?: string | null
  last_valid_query_at?: string | null
  last_productive_consulted_at?: string | null
  calendar_verified?: boolean
  communication?: PgdasdCommunicationPreference | null
  has_history?: boolean
  has_tracking?: boolean
  links?: Record<string, string | null>
}

export interface DctfwebEvidenceDescriptor {
  id: number
  kind?: string | null
  version?: number | null
  is_current?: boolean
  /** Nome sanitizado calculado no servidor a partir do MIME autorizado. */
  filename?: string | null
  content_type?: string | null
  byte_size?: number | null
  observed_at?: string | null
  download_path?: string | null
}

/** Projeção local sanitizada da consulta MIT/LISTAAPURACOES317. */
export interface MitListaApuracoes317 {
  id: number
  client_id: number
  period_key?: string | null
  situation?: string | null
  encerramento_status?: string | null
  observed_at?: string | null
  lista_apuracoes_317?: {
    id_apuracao?: number | null
    situacao?: number | null
    data_encerramento?: string | null
    evento_especial?: boolean | null
    valor_total_apurado?: number | null
  } | null
}

export interface MitListaApuracoes317Payload {
  data: MitListaApuracoes317[]
  provenance?: {
    source?: string | null
    serpro_called?: boolean
  } | null
}

export interface DctfwebHistoryPeriod {
  period_key?: string | null
  declaration_state?: DctfwebDeclarationState | string | null
  last_valid_query_at?: string | null
  declarations?: Array<Record<string, unknown>>
  observations?: Array<Record<string, unknown>>
  documents?: DctfwebEvidenceDescriptor[]
  artifacts?: DctfwebEvidenceDescriptor[]
}

export interface DctfwebHistoryPayload {
  client?: {
    id?: number
    legal_name?: string | null
    cnpj_masked?: string | null
  }
  expected_period_key?: string | null
  category?: string | null
  declaration_state?: DctfwebDeclarationState | string | null
  last_valid_query_at?: string | null
  periods?: DctfwebHistoryPeriod[]
  history?: DctfwebHistoryPeriod[]
  declarations?: Array<Record<string, unknown>>
  observations?: Array<Record<string, unknown>>
  artifacts?: DctfwebEvidenceDescriptor[]
  provenance?: {
    source?: string
    serpro_called?: boolean
  }
}

export interface DctfwebClientDetail {
  module_key?: 'dctfweb' | string
  submodule?: string | null
  declaration_state?: DctfwebDeclarationState | string | null
  last_declaration?: DctfwebLatestDeclaration | Record<string, unknown> | null
  last_search_at?: string | null
  last_productive_consulted_at?: string | null
  communication?: PgdasdCommunicationPreference | null
  has_history?: boolean
  has_tracking?: boolean
  dctfweb?: {
    id?: number | null
    period_key?: string | null
    expected_period_key?: string | null
    category?: string | null
    declaration_state?: DctfwebDeclarationState | string | null
    declaration_state_reason?: string | null
    transmission_status?: string | null
    payment_status?: string | null
    receipt_number?: string | null
    no_movement?: boolean | null
    situation?: string | null
    last_declaration?: DctfwebLatestDeclaration | null
    last_search_at?: string | null
    last_valid_query_at?: string | null
    calendar_verified?: boolean
    communication?: PgdasdCommunicationPreference | null
    has_history?: boolean
    has_tracking?: boolean
  } | null
  mit?: {
    id: number
    period_key?: string | null
    encerramento_status?: string | null
    dctfweb_transmission_status?: string | null
    situation?: string | null
    /** Preferência isolada da cápsula MIT (enrichment futuro); ausente até o backend expor. */
    communication?: PgdasdCommunicationPreference | null
  } | null
  links?: Record<string, string | null>
}

export interface InstallmentModalityCatalogItem {
  code: string
  label: string
  regime: string
  official_state: 'PRODUCTION' | 'PROSPECTION' | string
  official_state_label: string
  monitoring_supported: boolean
  executable: boolean
  required_power: string | null
}

export interface InstallmentParcel {
  id: number
  office_id: number
  client_id: number
  order_id: number
  modality: string
  parcel_key: string
  parcel_number?: number | null
  status: string
  source_status?: string | null
  due_at?: string | null
  amount_cents?: number | null
  document_available?: boolean
  payment_status?: string | null
  paid_at?: string | null
  tax_guide_id?: number | null
  logical_key?: string | null
}

export interface InstallmentPayment {
  id: number
  office_id: number
  client_id: number
  order_id: number
  modality: string
  payment_ref?: string | null
  status: string
  paid_at?: string | null
  amount_cents?: number | null
}

export interface InstallmentOrder {
  id: number
  office_id: number
  client_id: number
  modality: string
  regime?: string | null
  external_order_id: string
  situation?: string | null
  source_status?: string | null
  requested_at?: string | null
  consolidated_at?: string | null
  parcel_count?: number | null
  total_amount_cents?: number | null
  observed_at?: string | null
  parcels?: InstallmentParcel[]
  payments?: InstallmentPayment[]
}

export interface InstallmentMonitorResult {
  modality: string
  accepted: boolean
  run?: Record<string, unknown>
  error_code?: string
}

export interface InstallmentMonitorClientResult {
  client_id: number
  requested_modalities: number
  accepted: number
  failed: number
  results: InstallmentMonitorResult[]
}

export interface InstallmentMonitorBulkResponse {
  clients: number
  requested_modalities_per_client: number
  accepted: number
  failed: number
  results: InstallmentMonitorClientResult[]
}

export interface InstallmentsClientDetail {
  module_key?: 'installments' | string
  order_id?: number | null
  modality?: string | null
  modalities?: string[]
  order_count?: number
  orders?: InstallmentOrder[]
  external_order_id?: string | null
  total_amount_cents?: number | null
  parcel_count?: number | null
  order_situation?: string | null
  next_parcel_id?: number | null
  next_parcel_due_at?: string | null
  next_parcel_amount_cents?: number | null
  overdue_parcels?: number
  links?: Record<string, string | null>
}

export interface SitfisClientDetail {
  module_key?: 'sitfis' | string
  snapshot_id?: number | null
  observed_at?: string | null
  age_seconds?: number | null
  ttl_seconds?: number | null
  is_expired?: boolean
  findings_count?: number
  pending_count?: number
  /** Wrapper de comunicação SITFIS (core compartilhado); ausente = controles desabilitados. */
  communication?: PgdasdCommunicationPreference | null
  links?: Record<string, string | null>
}

/** Resposta tipada de GET /api/v1/fiscal/sitfis */
export interface SitfisShowResponse {
  snapshot?: Record<string, unknown> | null
  situation?: string | null
  protocol?: string | null
  coverage?: string | null
  evidence_artifact_id?: number | null
  age_seconds?: number | null
  observed_at?: string | null
  expires_at?: string | null
  next_refresh_at?: string | null
  ttl_seconds?: number | null
  is_within_ttl?: boolean
  can_refresh?: boolean
  block_reason?: string | null
  source_provenance?: string | null
  verification_state?: string | null
  is_negative_certificate?: boolean
  disclaimer?: string | null
  active_run?: Record<string, unknown> | null
  last_failed_run?: Record<string, unknown> | null
  display_only?: boolean
  error_code?: string | null
  error_message?: string | null
  links?: {
    evidence_download?: string | null
  } | null
  cache_key_hint?: string | null
}

export interface SitfisHistorySearch {
  id: number
  observed_at: string | null
  situation?: string | null
  version: number
  is_current: boolean
  evidence_artifact_id: number | null
  links?: {
    evidence_download?: string | null
  } | null
}

export interface SitfisHistoryPayload {
  client: {
    id: number
    legal_name: string
    cnpj_masked?: string | null
  }
  searches: SitfisHistorySearch[]
}

/** Resposta tipada de POST /api/v1/fiscal/sitfis/refresh */
export interface SitfisRefreshResponse {
  enqueued: boolean
  reused_snapshot?: boolean
  reason?: string | null
  run?: Record<string, unknown> | null
  situation?: SitfisShowResponse | null
}

export interface MailboxClientDetail {
  module_key?: 'mailbox' | string
  official_unread_count?: number
  stored_message_count?: number
  open_triage_count?: number
  dte_status?: string | null
  latest_message_id?: number | null
  latest_subject_preview?: string | null
  latest_received_at?: string | null
  latest_due_at?: string | null
  links?: Record<string, string | null>
}

export interface DeclarationsClientDetail {
  module_key?: 'declarations' | string
  /** Aba ativa do hub (PGDAS, DCTFWEB, …). */
  submodule?: string | null
  open_count?: number
  next_projection_id?: number | null
  next_obligation_code?: string | null
  next_period_key?: string | null
  next_due_at?: string | null
  next_delivery_status?: string | null
  next_situation?: string | null
  /** Enriquecimento PGDAS (reuso do domínio PGDAS-D). */
  declaration_state?: PgdasdDeclarationState | string | null
  declaration_state_reason?: string | null
  last_declaration?: PgdasdLatestDeclaration | null
  last_valid_query_at?: string | null
  pgdasd?: PgdasdClientSummary | null
  /** Enriquecimento parcial FGTS (sem inventar guia/pagamento). */
  fgts?: FgtsClientDetail | null
  partial_coverage_notice?: string | null
  links?: Record<string, string | null>
}

export interface GuidesClientDetail {
  module_key?: 'guides' | string
  guides_count?: number
  open_count?: number
  unpaid_amount_cents?: number
  next_guide_id?: number | null
  next_due_at?: string | null
  next_amount_cents?: number | null
  next_payment_status?: string | null
  links?: Record<string, string | null>
}

export interface FgtsClientDetail {
  module_key?: 'fgts' | string
  competence_period_key?: string | null
  closure_status?: string | null
  totalization_status?: string | null
  guide_status?: string | null
  payment_status?: string | null
  coverage?: string | null
  last_synced_at?: string | null
  partial_coverage_notice?: string | null
  /** Enrichment futuro (wrapper de comunicação FGTS); ausente até o backend expor. */
  communication?: PgdasdCommunicationPreference | null
  links?: Record<string, string | null>
}

export type FiscalModuleClientDetail
  = | SimplesMeiClientDetail
    | DctfwebClientDetail
    | InstallmentsClientDetail
    | SitfisClientDetail
    | MailboxClientDetail
    | DeclarationsClientDetail
    | GuidesClientDetail
    | FgtsClientDetail

export type SimplesMeiClientRow = FiscalClientRowBase<'simples_mei', SimplesMeiClientDetail>
export type DctfwebClientRow = FiscalClientRowBase<'dctfweb', DctfwebClientDetail>
export type InstallmentsClientRow = FiscalClientRowBase<'installments', InstallmentsClientDetail>
export type SitfisClientRow = FiscalClientRowBase<'sitfis', SitfisClientDetail>
export type MailboxClientRow = FiscalClientRowBase<'mailbox', MailboxClientDetail>
export type DeclarationsClientRow = FiscalClientRowBase<'declarations', DeclarationsClientDetail>
export type GuidesClientRow = FiscalClientRowBase<'guides', GuidesClientDetail>
export type FgtsClientRow = FiscalClientRowBase<'fgts', FgtsClientDetail>

export type FiscalModuleClientRow
  = | SimplesMeiClientRow
    | DctfwebClientRow
    | InstallmentsClientRow
    | SitfisClientRow
    | MailboxClientRow
    | DeclarationsClientRow
    | GuidesClientRow
    | FgtsClientRow

export type FiscalModuleClientRowFor<M extends FiscalPortfolioModuleKey>
  = Extract<FiscalModuleClientRow, { module_key: M }>

// ---------------------------------------------------------------------------
// Filtros / paginação / respostas API
// ---------------------------------------------------------------------------

export interface FiscalModulePortfolioFilters {
  page?: number
  per_page?: number
  q?: string
  situation?: string
  competence?: string
  submodule?: string
  /** Ano-calendário da projeção PGMEI; omitido nas demais cápsulas. */
  year?: number
  delivery_status?: string
  /**
   * Envio de comunicação PGDAS-D: `sent` | `not_sent` (CSV).
   * Só aplica em simples_mei + PGDASD.
   */
  send_status?: string
  /** Um id ou CSV "1,2,3" (multi-cliente no portfolio). */
  client_id?: number | string
  /** Cobertura (FULL / PARTIAL / …) — ModulePortfolioFilters backend. */
  coverage?: string
  /** Modalidade de parcelamento (PARCSN, …) — só installments. */
  modality?: string
  sort?: 'legal_name' | 'display_name' | 'situation' | 'last_declaration' | 'rbt12' | 'last_consulted_at' | 'competence' | 'id' | string
  sort_direction?: 'asc' | 'desc'
}

/** Valor aplicado e controlado por todas as listas do monitoramento. */
export interface MonitoringFilterValue {
  q: string
  situation: string
  competence: string
  /**
   * Clientes selecionados (multi). `[]` = sem filtro de cliente.
   * Serializa para `client_id=1,2,3` na API do portfolio.
   */
  clientIds: number[]
  deliveryStatus: string
  /** Envio comunicação PGDAS: sent | not_sent | all (CSV multi). */
  sendStatus: string
  paymentStatus: string
  status: string
  /** Cobertura FULL|PARTIAL|…; default 'all'. */
  coverage: string
  /** Modalidade de parcelamento; default 'all'. */
  modality: string
}

/** Campos estruturados (chips) — busca `q` permanece dedicada na toolbar. */
export type MonitoringStructuredFilterField
  = | {
    key: 'situation'
    kind: 'option'
    label: string
    items?: Array<{ label: string, value: string }>
    /** Default true no adapter (multi situação). */
    multiple?: boolean
  }
  | {
    key: 'competence'
    kind: 'month'
    label: string
  }
  | {
    key: 'clientId'
    kind: 'client'
    label: string
    /** Default true no adapter (vários clientes no portfolio). */
    multiple?: boolean
  }
  | {
    key: 'deliveryStatus' | 'sendStatus' | 'paymentStatus' | 'status' | 'coverage' | 'modality'
    kind: 'option'
    label: string
    items: Array<{ label: string, value: string }>
    /** Multi-seleção; coverage costuma ficar single. */
    multiple?: boolean
  }

/**
 * @deprecated Preferir `MonitoringStructuredFilterField` / `fields`.
 * Mantido para compatibilidade de imports legados em testes.
 */
export type MonitoringAdvancedFilterField
  = | {
    key: 'competence'
    kind: 'month'
    label: string
    hint?: string
  }
  | {
    key: 'clientId'
    kind: 'client'
    label: string
    hint?: string
  }
  | {
    key: 'deliveryStatus' | 'paymentStatus' | 'status'
    kind: 'select' | 'option'
    label: string
    hint?: string
    items: Array<{ label: string, value: string }>
  }

/** Schema visual da toolbar; submódulos continuam sendo identidade da rota. */
export interface MonitoringFilterConfig {
  search?: false | {
    placeholder?: string
    ariaLabel?: string
  }
  /**
   * Campos estruturados ordenados (chips). Situação, cliente, competência etc.
   * entram aqui — não há mais painel avançado separado.
   */
  fields?: MonitoringStructuredFilterField[]
  /**
   * @deprecated Use `fields`. Ainda aceito em migração; normalizado para fields.
   */
  situation?: boolean
  /**
   * @deprecated Use `fields`.
   */
  advanced?: MonitoringAdvancedFilterField[]
}

/** Alias usado por useApi / páginas. */
export type FiscalModuleClientsParams = FiscalModulePortfolioFilters

export interface FiscalModuleClientsPage<M extends FiscalPortfolioModuleKey = FiscalPortfolioModuleKey> {
  data: FiscalModuleClientRowFor<M>[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  current_page?: number
  last_page?: number
  total?: number
  per_page?: number
}

export interface FiscalModuleOverviewResponse<M extends FiscalPortfolioModuleKey = FiscalPortfolioModuleKey> {
  data: FiscalModuleOverview<M>
}

/** Estado de empty/erro da carteira (UI). */
export type FiscalTableEmptyKind
  = | 'loading'
    | 'empty'
    | 'error'
    | 'unsupported'
    | 'blocked'
    | 'filtered'

export const DCTFWEB_TABS = [
  { label: 'DCTFWeb', value: 'DCTFWEB' },
  { label: 'MIT', value: 'MIT' }
] as const

/** Abas locais do hub Declarações (não entram na URL). Default: PGDAS. */
export const DECLARATIONS_TABS = [
  { label: 'PGDAS-D', value: 'PGDAS' },
  { label: 'DEFIS', value: 'DEFIS' },
  { label: 'DASN-SIMEI', value: 'DASN_SIMEI' },
  { label: 'DCTFWeb', value: 'DCTFWEB' },
  { label: 'MIT', value: 'MIT' },
  { label: 'FGTS Digital', value: 'FGTS' },
  { label: 'DIRF', value: 'DIRF' }
] as const

export type DeclarationsSubmodule = (typeof DECLARATIONS_TABS)[number]['value']

export function normalizeDeclarationsSubmodule(raw?: unknown): DeclarationsSubmodule {
  const value = String(Array.isArray(raw) ? raw[0] : raw || '')
    .trim()
    .toUpperCase()
    .replaceAll('-', '_')
  if (value === 'PGDASD' || value === 'PGDAS_D') return 'PGDAS'
  if (value === 'DASNSIMEI') return 'DASN_SIMEI'
  if (value === 'DCTF') return 'DCTFWEB'
  const found = DECLARATIONS_TABS.find(tab => tab.value === value)
  return found?.value ?? 'PGDAS'
}

export function declarationsSubmoduleLabel(submodule?: string | null): string {
  const normalized = normalizeDeclarationsSubmodule(submodule)
  return DECLARATIONS_TABS.find(tab => tab.value === normalized)?.label ?? normalized
}

/** Título da superfície: `PGDAS - Declarações`. */
export function declarationsSurfaceTitle(submodule?: string | null): string {
  return `${declarationsSubmoduleLabel(submodule)} - Declarações`
}

export const MAILBOX_TRIAGE_ITEMS = [
  { label: 'Todas', value: 'all' },
  { label: 'Nova', value: 'NEW' },
  { label: 'Em análise', value: 'IN_REVIEW' },
  { label: 'Resolvida', value: 'RESOLVED' }
] as const

export function fiscalKpiSituationFilter(key: FiscalKpiKey): string | null {
  switch (key) {
    case 'total':
      return null
    case 'up_to_date':
      return 'UP_TO_DATE'
    case 'processing':
      return 'PROCESSING'
    case 'pending':
      return 'PENDING'
    case 'attention':
      return 'ATTENTION'
    case 'error':
      return 'ERROR'
    case 'blocked':
      return 'BLOCKED'
    case 'unknown':
      return 'UNKNOWN'
    case 'unsupported':
      return 'UNSUPPORTED'
    case 'not_applicable':
      return 'NOT_APPLICABLE'
    default:
      return null
  }
}

/**
 * Situação da URL/filtro → chave de KPI acionável.
 * `all` / vazio → `total`. Códigos canônicos mapeiam 1:1.
 */
export function fiscalSituationToKpiKey(situation?: string | null): FiscalKpiKey {
  const sit = String(situation || '').trim().toUpperCase()
  if (!sit || sit === 'ALL') return 'total'
  switch (sit) {
    case 'UP_TO_DATE':
      return 'up_to_date'
    case 'PROCESSING':
      return 'processing'
    case 'PENDING':
      return 'pending'
    case 'ATTENTION':
      return 'attention'
    case 'ERROR':
      return 'error'
    case 'BLOCKED':
      return 'blocked'
    case 'UNKNOWN':
      return 'unknown'
    case 'UNSUPPORTED':
      return 'unsupported'
    case 'NOT_APPLICABLE':
      return 'not_applicable'
    default:
      return 'total'
  }
}

export function fiscalDataOriginLabel(origin?: string | null): string {
  const v = String(origin || '').trim().toUpperCase()
  switch (v) {
    case 'DEMO':
      return 'Dados demonstrativos'
    case 'SIMULATED':
      return 'Dados simulados'
    case 'TRIAL':
      return 'Demonstração SERPRO'
    case 'LIVE':
      return 'Fonte produtiva'
    case '':
      return 'Origem não informada'
    default:
      return 'Origem não informada'
  }
}

/** Frescor fiscal (`as_of`); nunca inventa timestamp de transporte. */
export function fiscalAsOfLabel(asOf?: string | null): string {
  if (asOf == null || String(asOf).trim() === '') return 'Sem observação oficial'
  return String(asOf).trim()
}

/** Elegibilidade pública de consulta manual (espelho backend ManualConsultEligibility). */
export type ManualConsultEligibility
  = | 'ready'
    | 'module_off'
    | 'capability_off'
    | 'token_missing'
    | 'power_missing'
    | 'power_refreshing'
    | 'adapter_missing'
    | 'mutating_blocked'
    | 'permission_denied'

export interface ManualConsultParamField {
  name: string
  type: string
  required: boolean
  label: string
  pattern?: string | null
}

export interface ManualConsultLastResultSummary {
  state?: 'IDLE' | 'QUEUED' | 'PROCESSING' | 'READY' | 'NO_DATA' | 'FAILED' | 'BLOCKED' | 'UNSUPPORTED'
  status?: string | null
  state_label?: string | null
  observed_at?: string | null
  source_provenance?: string | null
  coverage?: string | null
  reason_code?: string | null
  run_id?: number | null
  freshness?: {
    state: 'FRESH' | 'STALE' | 'UNKNOWN'
    age_seconds?: number | null
    ttl_seconds: number
  }
  last_snapshot?: {
    snapshot_id: number
    observed_at?: string | null
    source_provenance: string
    coverage: string
    freshness: {
      state: 'FRESH' | 'STALE' | 'UNKNOWN'
      age_seconds?: number | null
      ttl_seconds: number
    }
  } | null
  has_preserved_snapshot?: boolean
}

export interface ManualConsultAction {
  action_id: string
  label: string
  surface_key: string
  module_key: string
  module_route: string
  eligibility: ManualConsultEligibility | string
  eligibility_label: string
  executable: boolean
  async: boolean
  params_schema: ManualConsultParamField[]
  last_result_summary?: ManualConsultLastResultSummary | null
}

export interface ManualConsultInventory {
  actions: ManualConsultAction[]
  meta: {
    total: number
    ready: number
    client_id?: number | null
    serpro_called: false
  }
}

export interface ManualConsultExecuteResult {
  action_id: string
  eligibility: string
  async: boolean
  module_route: string
  result: Record<string, unknown>
  serpro_call?: string
}

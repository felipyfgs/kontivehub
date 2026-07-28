import type { components as PublicApiComponents } from '~/types/generated/public-api'

export type TenantRole = PublicApiComponents['schemas']['TenantRole']
export type PlatformRole = PublicApiComponents['schemas']['PlatformRole']
export type Tenant = PublicApiComponents['schemas']['Tenant']
export type TenantAccessMode = PublicApiComponents['schemas']['TenantAccessMode']
export type TenantMembership = PublicApiComponents['schemas']['TenantMembership']
export type MeUser = PublicApiComponents['schemas']['MeUser']
export type MeResponse = PublicApiComponents['schemas']['MeResponse']
export type FiscalRole
  = | 'ISSUER'
    | 'TAKER'
    | 'INTERMEDIARY'
    | 'SENDER'
    | 'RECIPIENT'
    | 'EXPEDITOR'
    | 'RECEIVER'
    | 'AUTXML'

/** Direção fiscal no catálogo: entrada / saída. */
export type DocumentDirection = 'IN' | 'OUT' | 'UNKNOWN'
export type RegistrationSource = 'MANUAL' | 'CNPJ_WS' | 'SERPRO_CONSULTA'

export interface CnaePayload {
  code: string
  name?: string | null
}

export interface StateRegistrationPayload {
  number: string
  state?: string | null
  active?: boolean | null
}

export interface ShareholderPayload {
  name: string
  type?: string | null
  qualification_code?: string | null
  qualification_name?: string | null
  entered_at?: string | null
  document_masked?: string | null
}
export type RegistrationStatus = 'ACTIVE' | 'VOID' | 'SUSPENDED' | 'UNFIT' | 'CLOSED' | 'UNKNOWN'
export type ClientTaxRegimeCode
  = | 'SIMPLES_NACIONAL'
    | 'MEI'
    | 'LUCRO_PRESUMIDO'
    | 'LUCRO_REAL'
    | 'IMUNE_ISENTO'
    | 'OUTRO'
    | 'UNKNOWN'
export type ClientCategoryColor
  = | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral'
    | 'rose'
    | 'pink'
    | 'fuchsia'
    | 'purple'
    | 'indigo'
    | 'sky'
    | 'cyan'
    | 'teal'
    | 'emerald'
    | 'lime'
    | 'yellow'

/** GET /api/v1/onboarding/status */
export interface OnboardingStatusResult {
  available: boolean
}

export interface CompleteInitialOnboardingBody {
  organization_name: string
  email: string
  password: string
  password_confirmation: string
  onboarding_token: string
}

export interface CompleteInitialOnboardingResult {
  authenticated: boolean
  user_id: number
  redirect: string
  platform_organization_name?: string | null
}

export interface AccountProfile {
  id: number
  name: string
  email: string
}

export interface UpdateAccountProfileBody {
  name: string
  email: string
}

export interface AddressPayload {
  postal_code?: string | null
  street_type?: string | null
  street?: string | null
  number?: string | null
  complement?: string | null
  district?: string | null
  city?: string | null
  city_ibge_code?: string | null
  state?: string | null
  country?: string | null
}

export interface CaptureEligibility {
  eligible: boolean
  reasons: string[]
  reasons_codes: string[]
}

export interface Establishment {
  id: number
  tenant_id?: number
  client_id: number
  cnpj: string
  trade_name?: string | null
  is_headquarters: boolean
  is_active: boolean
  registration_status?: RegistrationStatus | string | null
  registration_status_at?: string | null
  registration_status_reason?: string | null
  special_situation?: string | null
  special_situation_at?: string | null
  activity_started_at?: string | null
  main_cnae_code?: string | null
  main_cnae_name?: string | null
  secondary_cnaes?: CnaePayload[]
  state_registrations?: StateRegistrationPayload[]
  shareholders?: ShareholderPayload[]
  address?: AddressPayload | null
  public_email?: string | null
  public_phone?: string | null
  public_phone_secondary?: string | null
  public_fax?: string | null
  simples_optant?: boolean | null
  mei_optant?: boolean | null
  capture_enabled?: boolean
  registration_source?: RegistrationSource | string | null
  registration_refreshed_at?: string | null
  capture_eligibility?: CaptureEligibility
  created_at?: string | null
  updated_at?: string | null
}

export interface ClientContact {
  id: number
  client_id: number
  name: string
  role?: string | null
  email?: string | null
  phone?: string | null
  is_whatsapp: boolean
  is_primary: boolean
  receives_alerts: boolean
  notes?: string | null
  is_active: boolean
  created_at?: string | null
  updated_at?: string | null
}

export interface ClientCustomField {
  id: number
  label: string
  type: 'TEXT' | 'SECRET'
  is_active?: boolean
  value: string | null
  has_value: boolean
}

export interface ClientWorkDepartmentSummary {
  id: number
  name: string
  code: string
}

export interface ClientCredentialSummary {
  status: string
  valid_to?: string | null
  expires_alert_30: boolean
  expires_alert_7: boolean
  expires_alert_1: boolean
}

/** Resumo de captura ADN na listagem (sem material de certificado). */
export interface ClientCaptureSummary {
  enabled: boolean
  /** ON | OFF | PARTIAL | NONE */
  status: string
  establishments_total: number
  establishments_enabled: number
}

/** Pior status de cursor entre estabelecimentos do cliente. */
export interface ClientSyncSummary {
  /** IDLE | RUNNING | WAITING | BLOCKED | ERROR | NONE */
  status: string
  last_success_at?: string | null
  has_cursor: boolean
}

export interface ClientListStats {
  total: number
  active: number
  /** Clientes com credencial ACTIVE. */
  with_credential?: number
  /** Credencial ACTIVE, válida e fora das janelas de alerta. */
  credential_ok?: number
  without_credential: number
  credential_expiring_30d: number
  credential_expired: number
  /** Clientes com cursor BLOCKED/ERROR. */
  capture_problem?: number
  /** Série acumulada mensal dos últimos 12 meses, calculada no servidor. */
  client_growth_12m?: Array<{ month: string, total: number }>
}

export interface ClientCategorySummary {
  id: number
  name: string
  color: ClientCategoryColor
  is_active: boolean
}

export interface ClientCategory extends ClientCategorySummary {
  clients_count: number
}

export type TenantAutXmlEnrollmentStatus = 'NONE' | 'PENDING' | 'CONFIRMED' | 'INACTIVE'

export interface TenantAutXmlEnrollment {
  id: number | null
  establishment_id: number
  establishment_cnpj: string
  establishment_name: string | null
  trade_name: string | null
  client_id: number
  client_name: string | null
  status: TenantAutXmlEnrollmentStatus
  activated_at: string | null
  first_seen_at: string | null
  last_seen_at: string | null
  observed: boolean
  channel_coverage: string
  channel_coverage_label: string
  nfce_hint: string
  erp_instruction: string
}

export interface TenantAutXmlStream {
  stream_ready: boolean
  stream_reason: string | null
  quiet_hours: number
  activated_at: string | null
  ready_at: string | null
}

export interface TenantAutXmlCoverage {
  channel: string
  model: string
  label: string
  not_retroactive: boolean
  nfce_note: string
}

export interface TenantAutXmlOverview {
  identity: Record<string, unknown> | null
  tenant_cnpj: string | null
  cursor: Record<string, unknown> | null
  stream: TenantAutXmlStream
  coverage: TenantAutXmlCoverage
  enrollments: TenantAutXmlEnrollment[]
  checklist?: Record<string, unknown>
}

export interface Client {
  id: number
  tenant_id?: number
  legal_name: string
  display_name?: string | null
  root_cnpj: string
  legal_nature_code?: string | null
  legal_nature_name?: string | null
  company_size_code?: string | null
  company_size_name?: string | null
  capital_social?: string | null
  responsible_qualification_code?: string | null
  responsible_qualification_name?: string | null
  /** Projeção cadastral atual; histórico oficial permanece nos períodos fiscais. */
  tax_regime?: ClientTaxRegimeCode | null
  tax_regime_label?: string | null
  work_department_id?: number | null
  work_department?: ClientWorkDepartmentSummary | null
  categories?: ClientCategorySummary[]
  notes?: string | null
  is_active: boolean
  inactive_reason?: string | null
  registration_source?: RegistrationSource | string | null
  registration_refreshed_at?: string | null
  establishments_count?: number
  /** Unidades registradas da raiz CNPJ do cliente. */
  establishments?: Establishment[]
  contacts?: ClientContact[]
  custom_fields?: ClientCustomField[]
  credential_summary?: ClientCredentialSummary | null
  capture_summary?: ClientCaptureSummary | null
  sync_summary?: ClientSyncSummary | null
  /**
   * Estado sanitizado de procuração e-CAC; expiring é calculado localmente
   * da validade oficial, sem iniciar nova sincronização.
   */
  procuracao_status?: ClientProcuracaoStatus | null
  procuracao_checked_at?: string | null
  procuracao_valid_to?: string | null
  created_at?: string | null
  updated_at?: string | null
}

/** Estado sincronizado da procuração do cliente (evidência oficial). */
export type ClientProcuracaoStatus = 'authorized' | 'expiring' | 'missing' | 'expired' | 'unverified'

export interface CnpjLookupClient {
  root_cnpj: string
  legal_name: string
  legal_nature_code?: string | null
  legal_nature_name?: string | null
  company_size_code?: string | null
  company_size_name?: string | null
  capital_social?: string | null
  responsible_qualification_code?: string | null
  responsible_qualification_name?: string | null
}

export interface CnpjLookupEstablishment {
  cnpj: string
  trade_name?: string | null
  is_headquarters: boolean
  registration_status: RegistrationStatus | string
  registration_status_at?: string | null
  registration_status_reason?: string | null
  special_situation?: string | null
  special_situation_at?: string | null
  activity_started_at?: string | null
  main_cnae_code?: string | null
  main_cnae_name?: string | null
  secondary_cnaes?: CnaePayload[]
  state_registrations?: StateRegistrationPayload[]
  shareholders?: ShareholderPayload[]
  address?: AddressPayload | null
  public_email?: string | null
  public_phone?: string | null
  public_phone_secondary?: string | null
  public_fax?: string | null
  simples_optant?: boolean | null
  mei_optant?: boolean | null
  source_updated_at?: string | null
}

export interface CnpjLookupResult {
  source: string
  source_updated_at?: string | null
  sources_used?: string[]
  client: CnpjLookupClient
  establishment: CnpjLookupEstablishment
}

export interface CreateClientPayload {
  legal_name: string
  cnpj: string
  display_name?: string | null
  notes?: string | null
  is_active?: boolean
  inactive_reason?: string | null
  trade_name?: string | null
  is_headquarters?: boolean
  establishment_is_active?: boolean
  registration_status?: string | null
  registration_status_at?: string | null
  registration_status_reason?: string | null
  activity_started_at?: string | null
  main_cnae_code?: string | null
  main_cnae_name?: string | null
  secondary_cnaes?: CnaePayload[] | null
  state_registrations?: StateRegistrationPayload[] | null
  shareholders?: ShareholderPayload[] | null
  public_email?: string | null
  public_phone?: string | null
  public_phone_secondary?: string | null
  public_fax?: string | null
  special_situation?: string | null
  special_situation_at?: string | null
  simples_optant?: boolean | null
  mei_optant?: boolean | null
  capture_enabled?: boolean
  legal_nature_code?: string | null
  legal_nature_name?: string | null
  company_size_code?: string | null
  company_size_name?: string | null
  capital_social?: string | number | null
  responsible_qualification_code?: string | null
  responsible_qualification_name?: string | null
  tax_regime?: ClientTaxRegimeCode | string | null
  address?: AddressPayload | null
  initial_contact?: {
    name: string
    role?: string | null
    email?: string | null
    phone?: string | null
    is_whatsapp?: boolean
    is_primary?: boolean
    receives_alerts?: boolean
    notes?: string | null
  } | null
  custom_fields?: Array<{
    label: string
    type: 'TEXT' | 'SECRET'
    value?: string | null
  }>
}

export interface CreateClientResponse {
  client: Client
  establishment: Establishment
  contact: ClientContact | null
  custom_fields: ClientCustomField[]
}

export interface ClientCredential {
  id: number
  client_id: number
  status: string
  subject_name: string
  holder_cnpj: string
  fingerprint_sha256: string
  valid_from?: string | null
  valid_to?: string | null
  activated_at?: string | null
  expires_alert_30: boolean
  expires_alert_7: boolean
  expires_alert_1: boolean
}

export interface DfeDocumentMetadata {
  id: number
  sha256: string
  document_type: string
  schema_version?: string | null
  access_key?: string | null
  byte_size: number
  parse_status: string
  parse_alert?: string | null
}

/** Tipos DF-e pertencentes ao catálogo escritural. */
export type DocumentKind = 'NFSE' | 'NFE' | 'NFCE' | 'CTE'

export type DocumentSource = 'ADN' | 'SEFAZ' | string

/** Item do catálogo fiscal unificado. */
export interface FiscalDocument {
  id: number
  /** Tipo DF-e (NFSE, NFE, CTE, …). */
  kind?: DocumentKind | string | null
  kind_label?: string | null
  /** Fonte de captura (ADN, SEFAZ, …). */
  source?: DocumentSource | null
  /** Captura habilitada no backend para o tipo desta linha. */
  capture_available?: boolean
  access_key: string
  /** Número do documento (nNFSe / nNF / nCT). */
  number?: string | null
  issuer_cnpj?: string | null
  issuer_name?: string | null
  taker_cnpj?: string | null
  taker_name?: string | null
  intermediary_cnpj?: string | null
  intermediary_name?: string | null
  fiscal_role?: FiscalRole | null
  /** Entrada (IN) / Saída (OUT) / Indefinida. */
  direction?: DocumentDirection | null
  direction_label?: string | null
  competence?: string | null
  issued_at?: string | null
  service_amount?: string | null
  issue_location?: string | null
  service_location?: string | null
  status: string
  /** Label operacional (Autorizada / Cancelada / Em revisão). */
  status_label?: string | null
  /** cStat oficial do XML (ex.: 100). */
  official_status_code?: string | null
  /** Descrição oficial curta (cStat ou nuance granular). */
  official_status_label?: string | null
  /** NF-e DistDFe: true se só resNFe. */
  is_summary?: boolean | null
  /** true se procNFe (full) disponível no vault. */
  has_full_xml?: boolean | null
  /** FULL | SUMMARY_ONLY */
  xml_completeness?: string | null
  manifestation_status?: string | null
  /** COMMERCIAL | TECHNICAL (sonda/autorização inesperada). */
  purpose?: 'COMMERCIAL' | 'TECHNICAL' | string | null
  /** Proveniência (IMPORT, MA_OFFICIAL_PACKAGE, …). */
  acquisition_source?: string | null
  acquisition_source_label?: string | null
  /** Qualidade CT-e: original ou visão oficial redigida. */
  artifact_quality?: string | null
  artifact_quality_label?: string | null
  signature_result?: string | null
  signature_result_label?: string | null
  is_autxml_redacted?: boolean
  autxml_redacted_notice?: string | null
  /** Cobertura CT-e honesta do cliente/período. */
  coverage_status?: CteCoverageStatus | string | null
  coverage_status_label?: string | null
  document?: DfeDocumentMetadata
}

export interface FiscalDocumentEvent {
  id: number
  access_key: string
  event_type?: string | null
  event_at?: string | null
  status?: string | null
}

export interface FiscalDocumentDetail {
  fiscal_document: FiscalDocument
  events: FiscalDocumentEvent[]
  metadata: DfeDocumentMetadata | null
}

export type ExportScope = 'documents' | 'fiscal_portfolio'

export interface ExportFilters {
  access_key?: string
  /** Seleção em lote (teto no backend, ex. 100). */
  access_keys?: string[]
  issuer_cnpj?: string
  taker_cnpj?: string
  competence?: string
  status?: string
  issued_from?: string
  issued_to?: string
  fiscal_role?: FiscalRole | ''
  direction?: Exclude<DocumentDirection, 'UNKNOWN'> | ''
  client_id?: number
  establishment_id?: number
  /** documents (default) | fiscal_portfolio — carteira sanitizada. */
  export_scope?: ExportScope
  /** Módulo da carteira (obrigatório se export_scope=fiscal_portfolio). */
  module_key?: string
  situation?: string
  q?: string
  submodule?: string
  /** Marcação de demonstração (servidor também infere do tenant). */
  is_demo?: boolean
  data_origin?: 'DEMO' | 'SIMULATED' | 'LIVE' | string
}

/** Agregação por cliente do escritório (aba Clientes). */
export interface FiscalDocumentClientAggregate {
  client_id: number
  legal_name: string
  display_name?: string | null
  name: string
  root_cnpj: string
  cnpj?: string | null
  documents_count: number
  service_amount_sum?: string | null
  cancelled_count?: number
  review_count?: number
  last_issued_at?: string | null
}

/** Contagens reais de triagem (chips) no escopo dos filtros. */
export interface FiscalDocumentInsights {
  total: number
  active: number
  cancelled: number
  review: number
  missing_party_name: number
  competence_current: number
  competence_current_label: string
  superseded?: number
  substitute?: number
  /** Contagens brutas por kind no escritório (quando a API expõe). */
  by_kind?: Partial<Record<DocumentKind | string, number>> | null
}

export interface ExportJob {
  id: number
  status: 'PENDING' | 'PROCESSING' | 'READY' | 'FAILED' | 'EXPIRED' | string
  filters: ExportFilters
  include_events: boolean
  files_count: number
  byte_size?: number | null
  expires_at?: string | null
  completed_at?: string | null
  created_at?: string | null
  error_message?: string | null
}

export interface SyncRun {
  id: number
  sync_cursor_id?: number
  status: 'RUNNING' | 'COMPLETED' | 'FAILED' | string
  trigger: 'MANUAL' | 'SCHEDULED' | string
  pages_processed: number
  documents_persisted: number
  from_nsu: number
  to_nsu: number
  error_message?: string | null
  started_at?: string | null
  finished_at?: string | null
  created_at?: string | null
}

/** Alinhado a App\Enums\CteCoverageStatus. */
export type CteCoverageStatus
  = | 'CAPTURED_ORIGINAL'
    | 'CAPTURED_AUTXML_REDACTED'
    | 'PENDING_IMPORT'
    | 'HISTORICAL_GAP'
    | 'BLOCKED'
    | 'NO_ACTIVITY'

export interface CtePublicIdentity {
  id: number
  cnpj: string
  root_cnpj: string
  status: string
  legal_name?: string | null
  activated_at?: string | null
  deactivated_at?: string | null
}

export interface CtePublicCredential {
  id: number
  tenant_fiscal_identity_id: number
  purpose: string
  status: string
  subject_name: string
  holder_cnpj: string
  fingerprint_sha256: string
  valid_from?: string | null
  valid_to?: string | null
  activated_at?: string | null
  last_used_at?: string | null
  expires_alert_30: boolean
  expires_alert_7: boolean
  expires_alert_1: boolean
}

export interface CteOnboarding {
  tenant_cnpj?: string | null
  identity?: CtePublicIdentity | null
  credential?: CtePublicCredential | null
  enabled: boolean
  instructions: {
    include_before_authorization: boolean
    not_retroactive: boolean
    message: string
    issuer_fallback: string
  }
}

export interface CteChannelCursor {
  id: number
  channel: string
  status: string
  environment?: string | null
  establishment_id?: number | null
  client_id?: number | null
  client_name?: string | null
  interested_root_cnpj?: string | null
  query_cnpj?: string | null
  last_nsu: number
  max_nsu_seen: number
  last_cstat?: string | null
  next_sync_at?: string | null
  last_success_at?: string | null
  retry_allowed?: boolean
  circuit_open?: boolean
}

export interface CteHealth {
  channels: Record<'CTE_DISTDFE' | 'CTE_AUTXML_DISTDFE', CteChannelCursor[]>
  summary: { client_streams: number, tenant_streams: number, blocked: number }
}

export interface CteCoverageSnapshot {
  client_id: number
  client_name?: string | null
  period: string
  status: CteCoverageStatus | string
  status_label: string
  documents_count: number
  original_count: number
  autxml_redacted_count: number
  pending_import_count: number
  computed_at?: string | null
}

export interface CtePendingItem {
  id: number
  sha256: string
  byte_size: number
  access_key?: string | null
  issuer_cnpj?: string | null
  recipient_cnpj?: string | null
  model?: string | null
  schema_family?: string | null
  reason: string
  reason_label: string
  source: string
  channel?: string | null
  nsu?: number | null
  resolution_status: string
  created_at?: string | null
}

export type InboxSeverity = 'critical' | 'high' | 'medium' | 'low'

/** Captura de saídas MA (posição nNF). */
export interface OutboundCaptureProfile {
  id: number
  client_id: number
  establishment_id: number
  uf: string
  environment: string
  model: string
  mode: string
  status: string
  consent_recorded: boolean
  mandate_reference?: string | null
  allowlisted: boolean
  kill_switch: boolean
  csc: { configured: boolean, csc_id?: string | null, configured_at?: string | null }
  activated_at?: string | null
}

export interface OutboundSeriesCursor {
  id: number
  profile_id: number
  establishment_id: number
  environment: string
  model: string
  series: number
  seed_nnf: number
  discovery_position: number
  position_kind: 'nNF'
  highest_confirmed_nnf?: number | null
  status: string
  tp_emis?: string
  seed_access_key?: string | null
  seed_issued_at?: string | null
  next_run_at?: string | null
  last_run_at?: string | null
  series_closed_for_mutation?: boolean
}

export interface OutboundNumberState {
  id: number
  series: number
  nnf: number
  status: string
  candidate_access_key?: string | null
  discovered_access_key?: string | null
  last_cstat?: string | null
  attempts: number
  next_attempt_at?: string | null
  key_discovered_at?: string | null
  xml_captured_at?: string | null
  has_full_xml: boolean
}

export interface OutboundCaptureRun {
  id: number
  profile_id: number
  series_cursor_id?: number | null
  run_type: string
  status: string
  position_kind: 'nNF'
  nnf_start?: number | null
  nnf_end?: number | null
  numbers_consulted: number
  keys_discovered: number
  xml_persisted: number
  gaps_open: number
  attempts_total: number
  result_summary?: string | null
  started_at?: string | null
  finished_at?: string | null
  triggered_by?: string
}

export interface OutboundKillSwitchStatus {
  global_active: boolean
  config_flag: boolean
  enabled: boolean
  protocol_query_enabled: boolean
  m2m_status: string
  mutating_probe_enabled: boolean
}

export type InboxItemType
  = | 'cursor_blocked'
    | 'cursor_error'
    | 'sync_failed_recent'
    | 'credential_expired'
    | 'credential_expiring_7d'
    | 'credential_expiring_30d'
    | 'backup_stale'
    | 'backup_never'
    | 'outbound_gap_exhausted'
    | 'outbound_562_no_key'
    | 'outbound_656'
    | 'outbound_retrieval_expired'
    | 'outbound_xml_divergent'
    | 'outbound_authorized_unexpected'
    | 'outbound_cancel_failed'
    | 'svrs_nfce_certificate'
    | 'svrs_nfce_auth'
    | 'svrs_nfce_rate_limit'
    | 'svrs_nfce_multiple_queries'
    | 'svrs_nfce_budget'
    | 'svrs_nfce_contract_changed'
    | 'svrs_nfce_xml_signature'
    | 'svrs_nfce_divergent'
    | 'svrs_nfce_breaker'
    | 'svrs_nfce_exhausted'
    | 'cte_certificate_missing'
    | 'cte_593'
    | 'cte_656'
    | 'cte_decode_failures'
    | 'cte_heartbeat_stale'
    | 'cte_external_consumer'
    | 'cte_unexpected_own_issuer'
    | 'cte_redaction'
    | 'cte_conflict'
    | 'cte_pending_import'
    | 'sitfis_run_completed'
    | 'sitfis_run_failed'
    | 'serpro_termo_missing'
    | 'serpro_termo_expired'
    | 'serpro_token_expiring'
    | 'serpro_auth_action_required'
    | 'serpro_auth_blocked'
    | 'proxy_power_expired'
    | 'proxy_power_missing'
    | 'source_unavailable'
    | 'query_blocked'
    | 'usage_franchise_exceeded'
    | 'usage_high'

/** Recovery SVRS NFC-e (DTOs sanitizados — sem HTML/XML/PFX). */
export interface SvrsNfceChannelSummary {
  retrieval_enabled: boolean
  auto_queue_enabled: boolean
  nfe55_retrieval_enabled?: boolean
  nfe55_auto_queue_enabled?: boolean
  pilot_allowlist_only: boolean
  kill_switch: { active: boolean, source?: string | null }
  breaker_global: { state: string, open_until?: number | null, failures?: number }
  backlog: number
  oldest_pending_at?: string | null
  parser_version?: string
  host?: string
  egress_cohort?: SvrsEgressCohortHealth
}

export interface SvrsEgressCohortHealth {
  cohort_id: string
  state: string
  cause?: string | null
  tier?: number | null
  opened_at?: string | null
  next_probe_at?: string | null
  canary_key_mask?: string | null
  exchanges_hour?: number
  exchanges_day?: number
  exchanges_hour_remaining: number
  exchanges_day_remaining: number
  inflight: number
  budgets_are_preventive: boolean
  note?: string
}

export interface SvrsNfceRecovery {
  id: number
  profile_id: number
  number_state_id?: number | null
  establishment_id: number
  environment: string
  model: string
  origin?: string
  access_key_masked?: string | null
  recovery_status?: string | null
  failure_reason?: string | null
  failure_label?: string | null
  attempt_count?: number
  next_attempt_at?: string | null
  correlation_id?: string | null
  sha256?: string | null
}

export interface SvrsNfceProfileSummary {
  profile_id: number
  model: string
  eligible_model: boolean
  allowlisted: boolean
  flags: {
    retrieval_enabled: boolean
    auto_queue_enabled: boolean
    pilot_allowlist_only: boolean
    kill_switch: boolean
  }
  breaker_root: { state: string, open_until?: number | null, failures?: number }
  breaker_global: { state: string, open_until?: number | null, failures?: number }
  recent: SvrsNfceRecovery[]
  last_captured?: SvrsNfceRecovery | null
}

export interface InboxItemAction {
  type: 'open' | 'trigger_sync' | string
  label: string
  establishment_id?: number
}

export interface InboxItemLinks {
  client?: string
  sync?: string
  credential?: string
  serpro_authorization?: string
  usage?: string
  monitoring?: string
}

export interface InboxItem {
  id: string
  type: InboxItemType | string
  severity: InboxSeverity | string
  title: string
  body: string
  reasons: string[]
  client_id?: number | null
  establishment_id?: number | null
  occurred_at: string
  links: InboxItemLinks
  actions: InboxItemAction[]
}

export interface InboxMeta {
  next_cursor: string | null
  total_estimate?: number
  generated_at: string
}

export interface BackupStatus {
  last_success_at?: string | null
  last_full_success_at?: string | null
  last_kind?: string | null
  last_status?: string | null
  last_restore_drill_at?: string | null
  last_restore_drill_status?: string | null
  stale: boolean
  never: boolean
}

export interface OperationsSerproAuthorization {
  configured: boolean
  status: string | null
  actions_required?: Array<{ code?: string, message?: string }>
  has_termo?: boolean
  has_procurador_token?: boolean
  termo_valid_to?: string | null
  procurador_token_expires_at?: string | null
  next_action?: string | null
}

export interface OperationsProxyPowers {
  active: number
  expired: number
  revoked: number
  insufficient: number
  expiring_30d: number
}

export interface OperationsModulesSummary {
  kill_switch: boolean
  global_enabled: boolean
  modules: Record<string, { enabled: boolean, mutating_enabled: boolean }>
}

export interface OperationsFiscalPending {
  open_total: number
  open_critical: number
  open_high: number
  due_7d: number
}

export interface OperationsFiscalCoverage {
  by_situation: Record<string, number>
  by_coverage: Record<string, number>
  up_to_date_full_only: number
  note?: string
}

export interface OperationsUsageSummary {
  available: boolean
  period_year?: number | null
  period_month?: number | null
  used_quantity?: number
  reserved_open_quantity?: number
  franchise_quota?: number | null
  remaining?: number | null
  franchise_ratio?: number | null
  alert_threshold_reached?: boolean
  deep_link?: string
}

export interface OperationsSubscriptionSummary {
  plan?: string | null
  status?: string | null
  limits?: Record<string, unknown> | null
  allows_mutations?: boolean
  allows_external_calls?: boolean
}

export interface OperationsBlocks {
  blocked: boolean
  reasons: string[]
  next_action?: string | null
}

export interface OperationsUncertainResults {
  mutations_uncertain: number
  note?: string
}

export interface OperationsPlatformHealth {
  environment?: string
  available: boolean
  status?: string
  kill_switch: boolean
  circuit_open: boolean
  cert_expiring_soon: boolean
}

export interface OperationsCommunicationSummary {
  available: boolean
  global_enabled?: boolean
  gateway_enabled?: boolean
  tenant_enabled?: boolean
  inboxes_by_status?: Record<string, number>
  outbox_retry?: number
  outbox_dead?: number
  conversations_open?: number
  conversations_pending?: number
  deep_link?: string
}

export interface OperationsMeiAutomationSummary {
  available: boolean
  failed_24h?: number
  uncertain_24h?: number
  running?: number
  deep_link?: string
}

export interface OperationsFiscalRunsSummary {
  available: boolean
  failed_24h?: number
  running?: number
  deep_link?: string
}

export interface OperationsSummary {
  clients: number
  establishments: number
  notes: number
  exports_ready: number
  exports_pending: number
  sync_due: number
  sync_blocked: number
  sync_failures_24h: number
  credentials_expiring_30d: number
  inbox_critical?: number
  inbox_high?: number
  inbox_total?: number
  backup?: BackupStatus
  svrs_nfce?: {
    retrieval_enabled: boolean
    auto_queue_enabled: boolean
    kill_switch: boolean
    breaker_global: string
    backlog: number
  }
  serpro_authorization?: OperationsSerproAuthorization
  proxy_powers?: OperationsProxyPowers
  modules?: OperationsModulesSummary
  fiscal_pending?: OperationsFiscalPending
  fiscal_coverage?: OperationsFiscalCoverage
  usage?: OperationsUsageSummary
  subscription?: OperationsSubscriptionSummary | null
  blocks?: OperationsBlocks
  uncertain_results?: OperationsUncertainResults
  platform_health?: OperationsPlatformHealth
  guides_due_7d?: number
  communication?: OperationsCommunicationSummary
  mei_automation?: OperationsMeiAutomationSummary
  fiscal_runs?: OperationsFiscalRunsSummary
  generated_at: string
}

/** Faixas de urgência do fechamento mensal de saídas (prazo ≠ falha técnica). */
export type OutboundUrgencyBand
  = | 'PLANNED'
    | 'ATTENTION'
    | 'CONTINGENCY'
    | 'OVERDUE'
    | 'CAPTURED'
    | string

export type OutboundMonthlyReadinessStatus
  = | 'COMPLETE_KNOWN'
    | 'PARTIAL_CONFIRMED'
    | 'NOT_READY'
    | string

export interface OutboundMonthlyReadiness {
  competence: string
  status: OutboundMonthlyReadinessStatus
  status_label?: string
  known_total: number
  captured_total: number
  pending_total: number
  export_id?: number | null
  confirmed_at?: string | null
  summary?: Record<string, unknown> | null
  completeness_scope: 'known_documents_only' | string
}

export interface OutboundCompetenceSummary {
  competence: string
  known_total: number
  captured_total: number
  pending_total: number
  by_band: Record<string, number>
  by_capture_source?: Record<string, number>
  readiness: OutboundMonthlyReadiness
  completeness_scope: string
  sla_note?: string
}

export interface OutboundCapacityProjection {
  demand_exchanges: number
  safe_capacity_exchanges: number
  nominal_capacity_exchanges: number
  slack_exchanges: number
  at_risk: boolean
  items_capacity_at_risk: number
  safe_daily_exchanges?: number
  auto_queue_fraction: number
  estimated_completion_at?: string | null
  target_at?: string | null
  due_at?: string | null
}

export interface OutboundCapacityForecast {
  competence: string
  projection: OutboundCapacityProjection
  latest_snapshot?: Record<string, unknown> | null
}

export interface OutboundDeadlinePendingItem {
  id: number
  access_key_masked?: string | null
  competence?: string | null
  model?: string | null
  urgency_band?: OutboundUrgencyBand | null
  deadline_status?: string | null
  recovery_status?: string | null
  failure_reason?: string | null
  failure_label?: string | null
  capacity_at_risk?: boolean
  due_at?: string | null
  target_at?: string | null
  next_attempt_at?: string | null
  next_step?: string | null
  capture_source?: string | null
  svrs_transaction_count?: number
  root_cnpj?: string | null
}

export interface OutboundDeadlineMetrics {
  known_total: number
  captured_total: number
  pending_total: number
  by_band: Record<string, number>
  overdue: number
  contingency: number
  slots_due: number
  by_capture_source?: Record<string, number>
  capacity?: {
    demand_exchanges?: number
    safe_capacity_exchanges?: number
    slack_exchanges?: number
    at_risk?: boolean
    items_capacity_at_risk?: number
  } | null
  completeness_scope: string
  alerts: Array<{ code: string, severity: string, message: string }>
}

export interface CursorMeta {
  next_cursor: string | null
  /** Total no escopo dos filtros (catálogo de documentos). */
  total?: number
  per_page?: number
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  stats?: ClientListStats
}

export interface AppNotification {
  id: string
  title: string
  body: string
  date: string
  unread?: boolean
  to?: string
  color?: 'error' | 'warning' | 'info' | 'neutral'
}

// ─── Tenancy / memberships ───────────────────────────────────────────────────

export interface TenantMembershipsPayload {
  current_tenant_id: number | null
  memberships: TenantMembership[]
}

export interface TenantSwitchResult {
  tenant: Tenant
  role: TenantRole | null
}

// ─── Configuração unificada do escritório (OpenSpec) ─────────────────────────

/** Perfil institucional do Tenant (4 campos). */
export interface TenantInstitutionalProfile {
  cnpj: string | null
  legal_name: string | null
  institutional_email: string | null
  institutional_phone: string | null
  updated_at?: string | null
}

/** Consentimento técnico versionado para uso do certificado. */
export interface TenantTechnicalConsent {
  version: string
  accepted: boolean
  accepted_at?: string | null
  accepted_by_name?: string | null
  purposes: Array<{ code: string, label: string, description?: string | null }>
  requires_reacceptance: boolean
  text_summary?: string | null
}

/** Certificado do escritório (somente metadados públicos). */
export interface TenantCertificate {
  id?: number | null
  status: string
  subject_name?: string | null
  holder_cnpj?: string | null
  fingerprint_sha256?: string | null
  valid_from?: string | null
  valid_to?: string | null
  purposes?: string[]
  expires_alert_30?: boolean
  expires_alert_7?: boolean
  expires_alert_1?: boolean
  activated_at?: string | null
}

/** Estado de onboarding acionável (sem jargão técnico SERPRO). */
export type TenantOnboardingStatus
  = | 'incomplete'
    | 'configuring'
    | 'validating'
    | 'authorizing'
    | 'loading_proxy_powers'
    | 'syncing'
    | 'ready'
    | 'provisioning'
    | 'authorized'
    | 'action_required'
    | 'technical_error'
    | 'revoked'

export interface TenantOnboardingActionable {
  status: TenantOnboardingStatus | string
  stage?:
    | 'CONFIGURANDO'
    | 'VALIDANDO'
    | 'AUTORIZANDO'
    | 'CARREGANDO_PROCURACOES'
    | 'SINCRONIZANDO'
    | 'PRONTO'
    | 'ACAO_NECESSARIA'
    | 'FALHA_TECNICA'
    | 'REVOGADO'
    | string
  actions: Array<{
    code: string
    label: string
    description?: string | null
    href?: string | null
  }>
  /** Correlation id sanitizado quando houver falha técnica. */
  correlation_id?: string | null
  message?: string | null
  modules?: FiscalModuleAdminItem[]
  procuracoes?: {
    total_clients: number
    by_status: Record<string, number>
    verified: number
  }
  initial_collection?: {
    queued_at: string | null
    runs_total: number
    runs_pending: number
    runs_finished: number
  }
}

/** Política mensal de execução automática por monitor (dia 1–28). */
export interface TenantMonitorSchedulePolicy {
  monitor_key: string
  monitor_label?: string | null
  day_of_month: number
  is_default: boolean
  timezone?: string | null
  next_run_at?: string | null
  last_run_at?: string | null
}

/** Franquia comercial cliente+monitor no período da assinatura. */
export interface MonitorCommercialBalance {
  remaining?: number | null
  limit?: number | null
  used?: number | null
  period_starts_at?: string | null
  period_ends_at?: string | null
  block_reason?: string | null
  inaugural_available?: boolean
}

/** Tenant listado no seletor global da plataforma. */
export interface PlatformTenantSummary {
  id: number
  name: string
  slug: string
  is_active?: boolean
  status?: string
  selectable?: boolean
  plan?: string | null
}

/** Envelope canônico de GET /api/v1/platform/tenants */
export interface PlatformTenantsEnvelope {
  tenants: PlatformTenantSummary[]
  selected_tenant_id: number | null
  default_tenant_id: number | null
}

export interface PlatformTenantSelectResult {
  tenant: Tenant
  access_mode: TenantAccessMode
  tenant_role: TenantRole | null
  real_tenant_role: TenantRole | null
  has_real_membership: boolean
  default_tenant_id: number | null
}

export type FiscalModuleAvailabilityState
  = | 'AVAILABLE'
    | 'GLOBALLY_RESTRICTED'
    | 'TENANT_RESTRICTED'
    | 'AWAITING_CONFIGURATION'
    | 'TECHNICAL_FAILURE'

export interface FiscalModuleRestrictionControl {
  id: number
  restricted: boolean
  reason: string
  updated_by: { id: number, name: string } | null
  restricted_at: string | null
  updated_at: string | null
  blocked_jobs_count: number
}

export interface FiscalModuleAdminItem {
  module_key: string
  label: string
  profile: 'dev' | 'trial' | 'production' | string
  operation_class: 'READ' | 'DOCUMENT_GENERATION' | 'FISCAL_MUTATION' | string
  allowed: boolean
  state: FiscalModuleAvailabilityState
  reason_code: string | null
  reason: string | null
  control_id: number | null
  historical_data_visible: true
  global_restriction: FiscalModuleRestrictionControl | null
  tenant_restriction: FiscalModuleRestrictionControl | null
  blocked_jobs_count: number
}

export interface PlatformFiscalModulesEnvelope {
  profile: 'dev' | 'trial' | 'production' | string
  kill_switch: boolean
  modules: FiscalModuleAdminItem[]
}

export interface PlatformTenantFiscalModulesEnvelope extends PlatformFiscalModulesEnvelope {
  tenant: Pick<PlatformTenantSummary, 'id' | 'name' | 'slug' | 'is_active'>
}

export interface FiscalModuleRestrictionBody {
  restricted: boolean
  reason: string
}

// ─── Assinatura / Integra Contador (tenant) ──────────────────────────────────

export interface TenantSubscription {
  id: number
  tenant_id: number
  plan: string
  status: string
  trial_ends_at?: string | null
  starts_at?: string | null
  ends_at?: string | null
  current_period_starts_at?: string | null
  current_period_ends_at?: string | null
  limits: {
    monthly_api_quota?: number | null
    max_clients?: number | null
    max_users?: number | null
  }
  allows_mutations: boolean
  allows_external_calls: boolean
}

export interface TenantSerproAuthorization {
  id: number
  tenant_id: number
  environment: string
  status: string
  author_identity_type: string
  author_identity_masked: string | null
  author_name?: string | null
  certificate_mode: string
  has_termo: boolean
  termo_sha256?: string | null
  termo_valid_from?: string | null
  termo_valid_to?: string | null
  termo_destination_cnpj_masked?: string | null
  termo_signed_by_masked?: string | null
  termo_uploaded_at?: string | null
  termo_authorization_state?: 'LOCAL_VALIDATED' | 'SERPRO_ACCEPTED' | 'SIMULATED' | 'REJECTED' | string | null
  authorization_state?: string | null
  has_procurador_token: boolean
  has_procurador_etag?: boolean
  procurador_token_expires_at?: string | null
  last_token_refresh_at?: string | null
  last_validation_result?: string | null
  last_validation_message?: string | null
  last_validated_at?: string | null
  action_required_reason?: string | null
  actions_required?: string[] | Array<{ code?: string, message?: string } | string>
  created_at?: string | null
  updated_at?: string | null
}

export interface TaxProxyPower {
  id: number
  tenant_id: number
  client_id: number
  author_identity_masked?: string | null
  contributor_cnpj_masked?: string | null
  system_code: string
  service_code?: string | null
  power_code: string
  source: string
  status: string
  valid_from?: string | null
  valid_to?: string | null
  evidence_ref?: string | null
  evidence_sha256?: string | null
  verified_at?: string | null
  last_check_result?: string | null
  is_currently_valid?: boolean
  created_at?: string | null
  updated_at?: string | null
}

export interface SerproPlatformHealth {
  environment?: string
  status?: string
  healthy?: boolean
  available?: boolean
  message?: string | null
  kill_switch?: boolean | SerproKillSwitchStatus
  circuit_open?: boolean
  smoke_status?: string
  [key: string]: unknown
}

/** Ambiente Integra Contador. */
export type SerproEnvironmentCode = 'TRIAL' | 'PRODUCTION' | string

/** Badges de proveniência/billing honestos na UI. */
export type SerproProvenanceBadge
  = | 'simulado'
    | 'real'
    | 'estimado'
    | 'conciliado'
    | 'possivelmente_bilhetavel'
    | 'nao_bilhetavel'

export interface SerproContractSanitized {
  id: number
  environment: SerproEnvironmentCode
  status: string
  contractor_cnpj_masked?: string | null
  contractor_name?: string | null
  subject_name?: string | null
  fingerprint_sha256?: string | null
  cert_valid_from?: string | null
  cert_valid_to?: string | null
  activated_at?: string | null
  superseded_at?: string | null
  blocked_at?: string | null
  last_verified_at?: string | null
  last_auth_at?: string | null
  health_status?: string | null
  health_message?: string | null
  token_expires_at?: string | null
  consumer_key_hint?: string | null
  credentials_exposed?: boolean
  segregation_class?: string | null
  active_credential_version_id?: number | null
  has_pfx?: boolean
  has_oauth?: boolean
  has_trial_bearer?: boolean
  has_cached_token?: boolean
  created_at?: string | null
  updated_at?: string | null
}

/** Versão de credencial SERPRO (metadados sanitizados; sem vault id). */
export interface SerproCredentialVersionSanitized {
  id: number
  serpro_contract_id?: number | null
  environment: SerproEnvironmentCode
  version_number: number
  status: string
  was_exposed?: boolean
  consumer_key_hint?: string | null
  consumer_key_last4?: string | null
  fingerprint_sha256?: string | null
  contractor_cnpj_masked?: string | null
  subject_name?: string | null
  cert_valid_from?: string | null
  cert_valid_to?: string | null
  verified_at?: string | null
  activated_at?: string | null
  retired_at?: string | null
  compromised_at?: string | null
  has_pfx?: boolean
  has_oauth?: boolean
  has_cached_token?: boolean
  has_recent_connection_test?: boolean
  latest_connection_test?: Record<string, unknown> | null
  blocks_billable_egress?: boolean
  created_at?: string | null
  updated_at?: string | null
}

export type SerproProductionOnboardingStatus
  = | 'PENDING'
    | 'RUNNING'
    | 'ACTIVE_SYNC_PENDING'
    | 'ACTIVE'
    | 'ACTION_REQUIRED'
    | 'FAILED'
    | string

export type SerproProductionOnboardingStep
  = | 'VALIDATE_INPUT'
    | 'STORE_PENDING'
    | 'VERIFY_VAULT'
    | 'TEST_OAUTH'
    | 'CONFIRM_ACTIVATION'
    | 'ACTIVATE_AUTHORIZATION'
    | 'QUEUE_READ_SYNC'
    | 'COMPLETED'
    | string

export interface SerproProductionOnboardingState {
  id: number
  environment: 'PRODUCTION' | string
  status: SerproProductionOnboardingStatus
  current_step?: SerproProductionOnboardingStep | null
  completed_steps?: SerproProductionOnboardingStep[]
  idempotency_key_hash?: string | null
  credential_version_id?: number | null
  serpro_contract_id?: number | null
  authorization_id?: number | null
  initial_mailbox_run_id?: number | null
  consent?: {
    version?: string | null
    text_sha256?: string | null
    consented_at?: string | null
    actor_user_id?: number | null
  } | null
  hints?: {
    consumer_key_hint?: string | null
    certificate_fingerprint_sha256?: string | null
    contractor_cnpj_masked?: string | null
    certificate_valid_to?: string | null
  } | null
  error?: {
    code?: string | null
    message?: string | null
  } | null
  required_actions?: Array<Record<string, unknown>>
  created_at?: string | null
  updated_at?: string | null
  completed_at?: string | null
}

export interface SerproProductionOnboardingEnvelope {
  enabled: boolean
  tenant_id?: number | null
  consent?: {
    version: string
    text: string
    text_sha256: string
  } | null
  onboarding?: SerproProductionOnboardingState | null
}

export interface SerproExternalGateSanitized {
  id: number
  kind: string
  label?: string
  environment?: string
  status: string
  title?: string
  description?: string | null
  ticket_ref?: string | null
  evidence_ref?: string | null
  responsible_name?: string | null
  reference_date?: string | null
  blocks_production?: boolean
  is_complete?: boolean
  answer_summary?: string | null
  accepted_at?: string | null
  updated_at?: string | null
}

export interface SerproPlatformConfiguration {
  environment: SerproEnvironmentCode
  endpoints: {
    oauth_token_url?: string
    api_base_url?: string
    role_type?: string
  }
  contract?: SerproContractSanitized | null
  active_credential_version?: SerproCredentialVersionSanitized | null
  pending_credential_versions?: SerproCredentialVersionSanitized[]
  credential_history?: SerproCredentialVersionSanitized[]
  external_gates?: SerproExternalGateSanitized[]
  external_gates_blocking?: boolean
  usage_limits?: {
    config?: Record<string, unknown>
    tenant_limits?: Array<Record<string, unknown>>
    usage?: Record<string, unknown>
  }
  runtime_controls?: Array<Record<string, unknown>>
  kill_switch?: SerproKillSwitchStatus
  readiness?: SerproReadinessSnapshot | Record<string, unknown> | null
  pending_tenants?: {
    count: number
    items: Array<{
      tenant_id: number
      tenant_name?: string | null
      tenant_slug?: string | null
      status?: string
      actionable_code?: string | null
      settings_path?: string
      updated_at?: string | null
    }>
  }
  summary?: {
    has_active_credential?: boolean
    has_pending_credential?: boolean
    has_recent_connection_test?: boolean
    gates_blocking?: boolean
    kill_switch_active?: boolean
    kill_switch_source?: string | null
    usage_allowed?: boolean
    usage_alert_reached?: boolean
    configuration_ready?: boolean
  }
}

export interface SerproKillSwitchStatus {
  global: {
    active: boolean
    source?: string | null
  }
  solutions: Record<string, boolean>
}

export interface SerproGlobalHealth {
  environment: SerproEnvironmentCode
  kill_switch: SerproKillSwitchStatus
  circuit_breaker?: {
    state?: string
    open_until?: number | null
    failures?: number
    [key: string]: unknown
  }
  active_contract?: SerproContractSanitized | null
  contracts?: SerproContractSanitized[]
  smoke_status?: string
  readiness?: SerproReadinessSnapshot | null
}

/** Snapshot de readiness (API pode evoluir — campos opcionais). */
export interface SerproReadinessSnapshot {
  overall?: 'READY' | 'BLOCKED' | 'DEGRADED' | 'UNKNOWN' | string
  environment?: SerproEnvironmentCode
  gates?: Array<{
    code: string
    scope?: 'global' | 'tenant' | 'client' | 'operation' | string
    status: 'PASS' | 'FAIL' | 'SKIP' | 'WARN' | string
    message?: string | null
    expires_at?: string | null
  }>
  evidence_kind?: 'offline' | 'live' | string | null
  evaluated_at?: string | null
  expires_at?: string | null
}

export interface SerproCatalogEntry {
  id?: number
  operation_key?: string
  system_code?: string | null
  service_code?: string | null
  operation_code?: string | null
  power_code?: string | null
  platform_support?: string | null
  consumption_class?: string | null
  route?: string | null
  billable?: boolean | null
  label?: string | null
  [key: string]: unknown
}

export interface SerproUsageConsolidation {
  period_year: number
  period_month: number
  global?: Array<Record<string, unknown>>
  by_tenant?: Array<{
    tenant_id: number
    entry_count?: number
    total_quantity?: number
    total_estimated_cost_micros?: number
  }>
  reconciliations?: SerproUsageReconciliation[]
  cycle?: Record<string, unknown> | null
  [key: string]: unknown
}

export interface SerproUsageReconciliation {
  id?: number
  period_year?: number
  period_month?: number
  official_total_cost_micros?: number
  estimated_total_cost_micros?: number | null
  difference_micros?: number | null
  status?: string | null
  official_reference?: string | null
  difference_cause?: string | null
  notes?: string | null
  [key: string]: unknown
}

export interface SerproRolloutState {
  smoke_status?: string
  kill_switch?: SerproKillSwitchStatus
  free_smoke_ok?: boolean
  canary_enabled?: boolean
  notes?: string | null
  [key: string]: unknown
}

// ─── Consumo SERPRO (tenant) ─────────────────────────────────────────────────

export interface TenantUsageSummarySnapshot {
  tenant_id?: number
  period_year: number
  period_month: number
  used_quantity: number
  reserved_open_quantity?: number
  franchise_quota: number | null
  remaining: number | null
  franchise_ratio?: number | null
  alert_threshold_reached?: boolean
  /** Custo estimado do próprio tenant — UI NÃO exibe fatura global. */
  estimated_cost_micros?: number | null
  policy?: Record<string, unknown>
}

export interface TenantUsageServiceAggregate {
  scope?: string
  period_year: number
  period_month: number
  system_code?: string | null
  service_code?: string | null
  consumption_class?: string | null
  entry_count: number
  total_quantity: number
  total_estimated_cost_micros?: number | null
  unknown_class_count?: number
  billable_attempt_count?: number
  recomputed_at?: string | null
}

export interface TenantUsageSummary {
  summary: TenantUsageSummarySnapshot
  by_service: TenantUsageServiceAggregate[]
}

export interface TenantUsageEntry {
  id: number
  tenant_id: number
  client_id?: number | null
  system_code?: string | null
  service_code?: string | null
  operation_code?: string | null
  consumption_class?: string | null
  quantity: number
  result?: string | null
  correlation_id?: string | null
  estimated_cost_micros?: number | null
  is_billable_attempt?: boolean
  latency_ms?: number | null
  occurred_at?: string | null
}

// ─── Monitoramento fiscal ────────────────────────────────────────────────────

/** Vocabulário honesto de situação (FiscalSituation). */
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
    | string

export interface FiscalCategory {
  id: number
  code: string
  name: string
  module_key?: string | null
  default_coverage?: string | null
  default_mutability?: string | null
  system_code?: string | null
  service_code?: string | null
  is_active?: boolean
  sort_order?: number
  description?: string | null
}

export interface FiscalMonitoringRun {
  id: number
  tenant_id: number
  client_id?: number | null
  fiscal_category_id?: number | null
  competence_id?: number | null
  system_code?: string | null
  service_code?: string | null
  operation_code?: string | null
  operation_key?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | 'UNVERIFIED' | string | null
  verification_state?: 'VERIFIED' | 'UNVERIFIED' | 'PARSE_ALERT' | string | null
  trigger?: string | null
  status?: string | null
  result?: string | null
  situation?: FiscalSituationCode | null
  coverage?: string | null
  mutability?: string | null
  attempt?: number
  correlation_id?: string | null
  items_processed?: number
  pages_processed?: number
  skip_reason?: string | null
  error_code?: string | null
  error_message?: string | null
  started_at?: string | null
  finished_at?: string | null
  created_at?: string | null
}

export interface FiscalSnapshot {
  id: number
  tenant_id: number
  run_id?: number | null
  client_id?: number | null
  competence_id?: number | null
  evidence_artifact_id?: number | null
  system_code?: string | null
  service_code?: string | null
  operation_code?: string | null
  operation_key?: string | null
  source_provenance?: 'SERPRO_REAL' | 'SIMULATED' | 'UNVERIFIED' | string | null
  verification_state?: 'VERIFIED' | 'UNVERIFIED' | 'PARSE_ALERT' | string | null
  situation?: FiscalSituationCode | null
  coverage?: string | null
  version?: number | null
  is_current?: boolean
  normalized?: Record<string, unknown> | null
  observed_at?: string | null
  created_at?: string | null
}

export interface FiscalFinding {
  id: number
  tenant_id: number
  snapshot_id?: number | null
  run_id?: number | null
  client_id?: number | null
  code?: string | null
  severity?: string | null
  title?: string | null
  detail?: string | null
  situation?: FiscalSituationCode | null
  is_active?: boolean
  resolved_at?: string | null
  created_at?: string | null
}

export interface FiscalPendingItem {
  id: number
  tenant_id: number
  client_id?: number | null
  snapshot_id?: number | null
  run_id?: number | null
  fiscal_category_id?: number | null
  competence_id?: number | null
  code?: string | null
  title?: string | null
  detail?: string | null
  severity?: string | null
  status?: string | null
  situation?: FiscalSituationCode | null
  due_at?: string | null
  resolved_at?: string | null
  logical_key?: string | null
  created_at?: string | null
}

export interface FiscalEvidenceArtifact {
  id: number
  tenant_id: number
  run_id?: number | null
  content_sha256: string
  content_type?: string | null
  byte_size?: number | null
  source?: string | null
  source_version?: string | null
  observed_at?: string | null
  created_at?: string | null
}

export interface FgtsCoverageManifest {
  module: string
  coverage: string
  coverage_label: string
  system_code?: string
  service_code?: string
  source?: 'ESOCIAL_BX_OFFICIAL' | string
  source_available: boolean
  transport?: 'SOAP_1_1_MTLS' | string
  driver?: 'disabled' | 'official_bx' | string
  environment?: 'restricted' | 'production' | string
  accepted_events?: Array<{ code: string, label: string }>
  supported_events?: Array<{ code: string, label: string }>
  automatic_events?: Array<{ code: string, label: string }>
  context_required_events?: Array<{
    code: string
    label: string
    required_context: string
  }>
  independent_states?: Record<string, string>
  limitations?: string[] | Array<Record<string, unknown>>
  declares_fgts_digital_debt: boolean
  scraping_allowed: boolean
  portal_fallback: boolean
  totalizer_absence_window_hours?: number
  official_limits?: {
    blocked_days: number[]
    daily_accesses_per_employer: number
    max_ids_per_download: number
    minimum_lag_minutes: number
    max_query_interval_days: number
    parallel_requests_allowed: boolean
  }
  documentation_url?: string
  official_links?: {
    manual?: string
    announcement?: string
    communication_package?: string
  }
  wsdl_sha256?: {
    identifiers?: string
    downloads?: string
  }
}

export interface FgtsEsocialReadiness {
  ready: boolean
  driver: string
  environment: string
  blockers: Array<{ code: string, message: string }>
  daily_limit: number
  locally_consumed: number
  locally_remaining: number
  credential?: {
    fingerprint_suffix: string
    expires_at?: string | null
  } | null
  blocked_days: number[]
  quota_scope: string
}

export interface FgtsEsocialSyncRequest {
  client_id: number
  competence_period_key: string
  establishment_id?: number | null
  dispatch_job?: boolean
  create_run?: boolean
  correlation_id?: string
}

export interface FgtsEsocialSyncAccepted {
  queued: true
  client_id: number
  competence_period_key: string
  establishment_id?: number | null
  run?: FiscalMonitoringRun | null
  coverage: FgtsCoverageManifest
}

export interface FgtsDigitalCoverage {
  source: 'FGTS_DIGITAL_PORTAL'
  driver: 'disabled' | 'fixture' | 'portal_browser' | string
  official_portal: string
  capabilities: {
    query_guides: boolean
    query_payment: boolean
    download_pdf: boolean
    quick_guides: string[]
    parameterized_preview: boolean
    emit_after_explicit_authorization: boolean
    pix_payment: false
  }
  human_challenge_policy: 'PAUSE_AND_IMPORT_AUTHORIZED_SESSION' | string
  fail_closed: boolean
  portal_manifest_version: string
  scheduler: {
    enabled: boolean
    emissions_enabled: boolean
    max_amount_cents: number
  }
}

export interface FgtsDigitalSessionDescriptor {
  id: number
  tenant_id: number
  client_id: number
  credential_source: 'CLIENT' | 'TENANT'
  profile_type: 'EMPREGADOR' | 'PROCURADOR' | 'PROCURADOR_PJ' | 'RESPONSAVEL_LEGAL' | string
  status: 'READY' | 'CHALLENGE' | 'EXPIRED' | 'REVOKED' | string
  expires_at?: string | null
  last_used_at?: string | null
  created_at?: string | null
}

export interface FgtsDigitalReadiness {
  driver: 'disabled' | 'fixture' | 'portal_browser' | string
  ready_for_read: boolean
  ready_for_mutation: boolean
  mutations_enabled: boolean
  credential_source?: 'CLIENT' | 'TENANT' | null
  has_authorized_session: boolean
  session?: FgtsDigitalSessionDescriptor | null
  human_challenge_possible: boolean
  captcha: {
    driver: 'disabled' | 'nopecha' | string
    external_api?: boolean
    shared_proxy_configured?: boolean
    max_attempts?: number
    max_credits_per_run?: number
  }
  blockers: Array<{ code: string, message: string }>
  supports_pdf_download: boolean
  supports_pix_payment: false
}

export interface FgtsDigitalRun {
  id: number
  tenant_id: number
  client_id: number
  operation: 'READINESS' | 'AUTHENTICATE' | 'QUERY_GUIDES' | 'QUERY_PAYMENT' | 'PREVIEW' | 'EMIT_GUIDE' | 'DOWNLOAD_GUIDE' | string
  guide_type?: 'MONTHLY' | 'TERMINATION' | 'CONSIGNMENT' | 'MIXED' | 'PARAMETERIZED' | string | null
  status: 'PENDING' | 'PREVIEWED' | 'AUTHORIZED' | 'RUNNING' | 'SUCCEEDED' | 'REUSED' | 'HUMAN_CHALLENGE_REQUIRED' | 'PORTAL_CONTRACT_CHANGED' | 'RECONCILIATION_REQUIRED' | 'BLOCKED' | 'FAILED' | string
  code?: string | null
  confirmation_phrase?: string | null
  preview_expires_at?: string | null
  request?: Record<string, unknown> | null
  result?: Record<string, unknown> | null
  tax_guide_id?: number | null
  tax_guide_version_id?: number | null
  fiscal_mutation_operation_id?: number | null
  correlation_id?: string | null
  started_at?: string | null
  finished_at?: string | null
  created_at?: string | null
}

export interface FgtsDigitalPreviewResponse {
  run: FgtsDigitalRun
  preview_token: string | null
}

export interface FiscalMutationPreflight {
  eligible: boolean
  preflight_token?: string | null
  idempotency_key?: string | null
  preflight_expires_at?: string | null
  confirmation_phrase?: string | null
  effect_summary?: string | null
  cost_estimate?: Record<string, unknown> | string | null
  estimated_cost_micros?: number | null
  eligibility?: Record<string, unknown> | null
  denial_code?: string | null
  denial_message?: string | null
  requires_totp?: boolean
  client_id?: number
  competence_period_key?: string | null
  solution_code?: string
  service_code?: string
  operation_code?: string
  [key: string]: unknown
}

export interface FiscalMutationRequest {
  client_id: number
  operation_key: string
  solution_code: string
  service_code: string
  operation_code: string
  competence_period_key?: string | null
  idempotency_key?: string | null
  environment?: string | null
  module?: string | null
  payload?: Record<string, unknown>
}

export interface FiscalMutationExecutionRequest extends Omit<FiscalMutationRequest, 'idempotency_key'> {
  idempotency_key: string
  preflight_token: string
  confirmation_phrase: string
  confirmed: true
}

export interface FiscalMutationOperation {
  id: number
  tenant_id: number
  client_id: number
  status: string
  status_label?: string | null
  environment?: string | null
  solution_code?: string | null
  service_code?: string | null
  operation_code?: string | null
  module_key?: string | null
  competence_period_key?: string | null
  effect_summary?: string | null
  confirmation_phrase?: string | null
  cost_estimate?: Record<string, unknown> | string | null
  estimated_cost_micros?: number | null
  result_code?: string | null
  result_message?: string | null
  denial_code?: string | null
  denial_message?: string | null
  simulated?: boolean
  created_at?: string | null
  [key: string]: unknown
}

// ─── Ativação / cadastro de Tenants e equipe ─────────────────────────────────

export type ActivationMethod = 'MANUAL_LINK' | 'TEMPORARY_PASSWORD'
export type ActivationPurpose = 'TENANT_FIRST_ADMIN' | 'TENANT_MEMBER' | 'PLATFORM_ADMIN'
export type ActivationPublicStatus = 'pending' | 'consumed' | 'expired' | 'revoked' | string
export type CredentialDelivery = 'delivered' | 'regeneration_required' | 'not_required' | string
export type TenantLifecycleStatus
  = | 'PENDING_ACTIVATION'
    | 'ACTIVE'
    | 'SUSPENDED'
    | 'DEPROVISIONED'
    | string
export type SubscriptionPlanCode = 'STARTER' | 'PROFESSIONAL' | 'ENTERPRISE'
export type TenantMemberStatus = 'active' | 'pending' | 'expired' | 'deactivated' | string

/** Ativação sanitizada (sem hash/segredo). */
export interface ActivationSanitized {
  id: number
  purpose: ActivationPurpose | string
  method: ActivationMethod | string
  status: ActivationPublicStatus
  expires_at?: string | null
  consumed_at?: string | null
  revoked_at?: string | null
  generation?: number
  email_masked?: string | null
}

export interface ActivationInspectResult {
  valid: boolean
  email_masked?: string
  invite_name?: string
  purpose?: ActivationPurpose | string
  method?: ActivationMethod | string
  expires_at?: string
}

export interface ActivationCompleteResult {
  authenticated: boolean
  user_id: number
  purpose: ActivationPurpose | string
}

/** Item da lista admin de tenants (inclui pendentes). */
export interface PlatformTenantAdminSummary {
  id: number
  name: string
  slug: string
  is_active: boolean
  lifecycle_status: TenantLifecycleStatus
  subscription?: TenantSubscription | null
  activation?: ActivationSanitized | null
  created_at?: string | null
}

export interface PlatformTenantFirstAdmin {
  membership_id: number
  user_id: number
  name?: string | null
  email?: string | null
  is_active: boolean
}

export interface PlatformTenantInstitutionalProfile {
  id?: number
  cnpj: string
  legal_name: string
  institutional_email: string
  institutional_phone: string
  is_complete?: boolean
  updated_at?: string | null
}

/** Detalhe sanitizado de tenant (GET show / create payload.tenant). */
export interface PlatformTenantAdminDetail {
  id: number
  name: string
  slug: string
  is_active: boolean
  lifecycle_status: TenantLifecycleStatus
  created_at?: string | null
  profile?: PlatformTenantInstitutionalProfile | null
  subscription?: TenantSubscription | null
  first_admin?: PlatformTenantFirstAdmin | null
  activation?: ActivationSanitized | null
}

export interface CreatePlatformTenantBody {
  name: string
  profile: {
    cnpj: string
    legal_name: string
    institutional_email: string
    institutional_phone: string
  }
  plan: SubscriptionPlanCode | string
  admin_name: string
  admin_email: string
  method: ActivationMethod
  idempotency_key: string
}

/** Resposta de criação/regeneração com possível segredo único. */
export interface CredentialDeliveryPayload {
  credential_delivery: CredentialDelivery
  method?: ActivationMethod | string | null
  expires_at?: string | null
  activation_url?: string
  temporary_password?: string
  activation?: ActivationSanitized
}

export interface CreatePlatformTenantResult extends CredentialDeliveryPayload {
  tenant: PlatformTenantAdminDetail
}

/** Proprietário singleton da instalação (PLATFORM_ADMIN). */
export interface PlatformOwner {
  user_id: number
  name?: string | null
  email?: string | null
  is_active: boolean
  membership_active?: boolean
  password_change_required?: boolean
  default_tenant_id?: number | null
  default_tenant?: {
    id: number
    name: string
    slug: string
  } | null
  created_at?: string | null
}

export interface UpdatePlatformOwnerBody {
  name?: string
  email?: string
  default_tenant_id?: number | null
}

export interface TenantMember {
  id: number
  user_id: number
  name?: string | null
  email?: string | null
  role: TenantRole
  permission_profile: {
    id: number
    key: string
    name: string
  } | null
  is_active: boolean
  status: TenantMemberStatus
  activation?: ActivationSanitized | null
}

export interface TenantMembersMeta {
  occupied_seats: number
  max_users?: number | null
}

export interface CreateTenantMemberBody {
  name: string
  email: string
  role: TenantRole
  method: ActivationMethod
}

export interface CreateTenantMemberResult extends CredentialDeliveryPayload {
  membership?: TenantMember
  member?: TenantMember
}

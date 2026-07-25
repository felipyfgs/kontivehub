export type MeiAutomationStatus
  = | 'QUEUED'
    | 'RUNNING'
    | 'WAITING_USER_ACTION'
    | 'SUCCEEDED'
    | 'FAILED'
    | 'CANCELLED'
    | 'UNCERTAIN'
    | 'SYNC_LOST'

export type MeiProvider = 'RECEITA_PORTAL' | 'SERPRO' | 'FIXTURE'
export type MeiCoverage = 'SUMMARY' | 'FULL' | 'UNKNOWN'
export type MeiSourceProvenance = 'RECEITA_PORTAL' | 'SERPRO_REAL' | 'UNVERIFIED'

export interface MeiAutomationArtifact {
  id: string
  name: string | null
  content_type: string | null
  byte_size: number | null
  sha256: string | null
  href?: string | null
}

export interface MeiAutomationAttempt {
  id: number
  client_id: number
  operation_key: string
  provider: MeiProvider
  status: MeiAutomationStatus
  source_provenance: MeiSourceProvenance | string | null
  verification_kind: 'PORTAL_ARTIFACT' | string | null
  fallback_reason: string | null
  error_code: string | null
  error_message: string | null
  metadata: Record<string, unknown>
  artifacts: MeiAutomationArtifact[]
  started_at: string | null
  last_synced_at: string | null
  finished_at: string | null
  created_at: string | null
}

export interface GenerateMeiDasInput {
  client_id: number
  competencies: string[]
  output_format: 'PDF' | 'BARCODE'
  preflight_token: string
  confirmation_phrase: string
  confirmed: true
}

export interface GenerateMeiDasPreflightInput {
  client_id: number
  competencies: string[]
  output_format: 'PDF' | 'BARCODE'
}

export interface GenerateMeiDasResponse {
  data: {
    mutation: import('~/types/api').FiscalMutationOperation
    attempt: MeiAutomationAttempt | null
  }
}

export interface ConsultMeiDebtInput {
  client_ids: number[]
  calendar_year: number
  confirmed: true
}

export interface ConsultDasnHistoryInput {
  client_ids: number[]
  calendar_year?: number | null
  include_full_receipt?: boolean
  confirmed: true
}

export interface MeiOperationQueuedResponse {
  data: Array<Record<string, unknown>>
  enqueued_count?: number
}

export interface DasnDeclarationSummary {
  calendar_year: number
  status: string
  transmitted_at: string | null
  declaration_type?: string | null
  special_situation?: string | null
  special_situation_date?: string | null
  pending?: boolean
  coverage: MeiCoverage
  artifact_attempt_id?: number | null
  receipt_available: boolean
  artifact?: MeiAutomationArtifact | null
}

export interface DasnHistoryPayload {
  client_id: number
  coverage: MeiCoverage
  declarations: DasnDeclarationSummary[]
  attempt?: MeiAutomationAttempt | null
}

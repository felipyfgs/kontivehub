import type { ClientCategoryColor, ClientTaxRegimeCode } from './api'

/** Tipos do módulo operacional (Work) — alinhados à API /api/v1/work/* */

export type ProcessOrigin = 'TEMPLATE' | 'MANUAL'
export type ProcessStatus = 'A_FAZER' | 'EM_PROGRESSO' | 'IMPEDIDO' | 'CONCLUIDO' | 'ARQUIVADO'
export type TaskStatus = 'A_FAZER' | 'EM_PROGRESSO' | 'IMPEDIDA' | 'CONCLUIDA' | 'DISPENSADA'
export type DueRuleType
  = | 'FIXED_DAY_OF_COMPETENCE'
    | 'DAYS_AFTER_COMPETENCE_START'
    | 'DAYS_BEFORE_PROCESS_DUE'
export type WorkRisk = 'ATRASADA' | 'EM_MULTA' | 'SEM_PRAZO' | 'SEM_RESPONSAVEL'
export type QueueBucket
  = | 'EM_MULTA'
    | 'ATRASADA'
    | 'VENCE_HOJE'
    | 'VENCE_EM_TRES_DIAS'
    | 'IMPEDIDA'
    | 'SEM_RESPONSAVEL'
    | 'DEMAIS_ABERTAS'
    | 'CONCLUIDAS'

export type WorkMonitoringModuleKey
  = | 'PGDASD'
    | 'PGMEI'
    | 'INSTALLMENTS'
    | 'DCTFWEB'
    | 'FGTS'
    | 'MAILBOX'
    | 'SITFIS'
    | 'DECLARATIONS'
    | 'GUIDES'
    | 'TAX_PROCESSES'

/** Granularidade do Período de referência do Processo. */
export type ReferencePeriodType = 'MONTHLY' | 'QUARTERLY' | 'ANNUAL'

export type RecurrenceFrequency = 'MONTHLY' | 'QUARTERLY' | 'YEARLY'
export type RecurrencePeriodOffset = 'PREVIOUS' | 'CURRENT'

export interface ProcessAudienceRules {
  tax_regimes: ClientTaxRegimeCode[]
  category_ids: number[]
  category_match: 'ANY' | 'ALL'
  excluded_category_ids: number[]
}

export interface WorkDepartment {
  id: number
  name: string
  code: string
  color?: string | null
  is_active: boolean
  created_at?: string
  updated_at?: string
}

export interface ProcessTemplateTask {
  id?: number
  sort_order: number
  title: string
  description?: string | null
  due_rule_type?: DueRuleType | null
  due_rule_value?: number | null
  default_department_id?: number | null
  default_assignee_membership_id?: number | null
  is_required: boolean
  is_critical: boolean
  requires_evidence: boolean
}

/** Agenda de recorrência da Rotina (contrato GET/PATCH …/recurrence). */
export interface ProcessTemplateRecurrence {
  recurrence_enabled: boolean
  recurrence_frequency: RecurrenceFrequency | null
  generation_day: number
  anchor_month: number | null
  period_offset: RecurrencePeriodOffset
  next_run_at: string | null
  recurrence_owner_membership_id: number | null
}

export interface ProcessTemplate {
  id: number
  catalog_key?: string | null
  catalog_version?: number | null
  name: string
  description?: string | null
  monitoring_module_key?: WorkMonitoringModuleKey | null
  audience_rules: ProcessAudienceRules
  default_department_id?: number | null
  default_due_rule_type?: DueRuleType | null
  default_due_rule_value?: number | null
  is_active: boolean
  recurrence_enabled?: boolean
  recurrence_frequency?: RecurrenceFrequency | null
  generation_day?: number | null
  anchor_month?: number | null
  period_offset?: RecurrencePeriodOffset | null
  next_run_at?: string | null
  recurrence_owner_membership_id?: number | null
  lock_version: number
  tasks: ProcessTemplateTask[]
  created_at?: string
  updated_at?: string
}

export interface ProcessTemplateCatalogItem {
  key: string
  version: number
  name: string
  description?: string | null
  department_role?: string | null
  monitoring_module_key?: WorkMonitoringModuleKey | null
  default_due_rule_type?: DueRuleType | null
  default_due_rule_value?: number | null
  audience_rules: ProcessAudienceRules
  tasks: ProcessTemplateTask[]
  installed: boolean
  installed_template_id?: number | null
  installed_version?: number | null
  update_available: boolean
}

export interface WorkMonitoringContext {
  key: WorkMonitoringModuleKey
  label: string
  to: string
}

export interface OperationalTaskSummary {
  id: number
  title: string
  status: TaskStatus
  due_date?: string | null
  effective_due_date?: string | null
  is_critical: boolean
  is_required: boolean
  requires_evidence: boolean
  block_reason?: string | null
  lock_version: number
  bucket?: QueueBucket
  risks?: WorkRisk[]
  evidence_count?: number | null
  department?: { id: number, name: string, code: string } | null
  assignee?: { membership_id: number, name: string } | null
  process?: {
    id: number
    title: string
    competence: string
    status: ProcessStatus
    subject_to_fine?: boolean
    client?: { id: number, name: string } | null
  } | null
}

export interface OperationalProcess {
  id: number
  title: string
  description?: string | null
  monitoring_module_key?: WorkMonitoringModuleKey | null
  competence: string
  origin: ProcessOrigin
  status: ProcessStatus
  due_date?: string | null
  target_due_date?: string | null
  subject_to_fine: boolean
  work_department_id?: number | null
  assignee_membership_id?: number | null
  client_id: number
  process_template_id?: number | null
  lock_version: number
  client?: { id: number, name: string, cnpj_masked?: string | null } | null
  links?: { client: string, monitoring: string } | null
  monitoring_context?: WorkMonitoringContext | null
  department?: { id: number, name: string, code: string } | null
  assignee?: { membership_id: number, name: string } | null
  task_count?: number | null
  completed_task_count?: number | null
  open_task_count?: number | null
  progress_percent?: number | null
  risks?: WorkRisk[]
  tasks?: OperationalProcessTask[]
  comments?: OperationalComment[]
}

export interface OperationalProcessTask extends OperationalTaskSummary {
  sort_order: number
  description?: string | null
  assignee_membership_id?: number | null
  work_department_id?: number | null
}

export interface OperationalTaskDetail extends OperationalProcessTask {
  operational_process_id: number
  started_at?: string | null
  completed_at?: string | null
  evidences?: OperationalEvidence[]
  comments?: OperationalComment[]
}

export interface OperationalEvidence {
  id: number
  original_filename: string
  mime_type: string
  byte_size: number
  sha256: string
  created_at?: string
}

export interface OperationalComment {
  id: number
  body: string
  author_membership_id?: number
  created_at?: string
}

export interface GenerationBatch {
  id: number
  process_template_id: number
  template_lock_version: number
  competence: string
  status: string
  payload_hash: string
  idempotency_key?: string
  preview_summary?: GenerationSummary
  expires_at?: string | null
  items: GenerationItem[]
}

export interface GenerationSummary {
  total: number
  blocked: number
  ready: number
  matched_by_rule?: number
  included_manually?: number
  excluded_manually?: number
  invalid_references?: number
  excluded_items?: Array<{
    client_id: number
    client_name: string
    cnpj_masked?: string | null
    reason: string
  }>
}

export interface GenerationSelection {
  rules: ProcessAudienceRules
  include_client_ids: number[]
  exclude_client_ids: number[]
}

export interface GenerationPreviewCategory {
  id: number
  name: string
  color: ClientCategoryColor
}

export interface GenerationPreviewPayload {
  title?: string
  description?: string | null
  due_date?: string | null
  target_due_date?: string | null
  subject_to_fine?: boolean
  monitoring_module_key?: WorkMonitoringModuleKey | null
  work_department_id?: number | null
  selection?: {
    client_name?: string
    cnpj_masked?: string | null
    tax_regime?: string
    regime_source?: string
    categories?: GenerationPreviewCategory[]
    selection_source?: string
  }
  tasks?: Array<{
    sort_order: number
    title: string
    due_date?: string | null
  }>
}

export interface GenerationItem {
  id: number
  client_id: number
  status: string
  is_blocked: boolean
  preview_payload?: GenerationPreviewPayload
  alerts?: Array<{ code?: string, message?: string }>
  conflicts?: Array<{ code: string, message: string }>
  created_process_id?: number | null
  error_message?: string | null
}

export interface DepartmentWorkProgress {
  work_department_id: number | null
  open: number
  completed: number
  overdue: number
  fine: number
  unassigned: number
  total_relevant: number
  completed_percent: number
  /** Compat legado = open */
  total: number
}

export interface WorkKpis {
  generated_at: string
  office_timezone: string
  today: string
  kpis: {
    total_open: number
    atrasadas: number
    em_multa: number
    vence_hoje: number
    em_progresso: number
    concluidas: number
    sem_responsavel: number
  }
  by_department: DepartmentWorkProgress[]
  by_assignee: Array<{ assignee_membership_id: number | null, total: number }>
  top_risks: Array<{
    task_id: number
    title: string
    process_id: number
    risks: WorkRisk[]
    effective_due_date?: string | null
  }>
  processes_without_owner: Array<{
    id: number
    title: string
    competence: string
    due_date?: string | null
    client_id: number
  }>
}

export interface OperationalExportJob {
  id: number
  status: string
  filters_snapshot: Record<string, unknown>
  byte_size?: number | null
  row_count: number
  error_message?: string | null
  expires_at?: string | null
  completed_at?: string | null
}

/** Seletor Cliente | Processo | Tarefa (navegação de superfície). */
export type WorkEntityLevel = 'client' | 'process' | 'task'

/**
 * Modo de listagem na URL de `/work/processes`.
 * `client` → `?group=client` (API `process-groups` / `group_by=client`);
 * `process` → `group` omitido (API `process-groups` / `group_by=routine`).
 */
export type WorkProcessGroupMode = 'client' | 'process'

/** Parâmetro `group_by` do endpoint de grupos. */
export type WorkProcessGroupBy = 'client' | 'routine'

/** Sort whitelist da lista flat de processos (legado; não path principal). */
export type WorkProcessFlatSort = 'title' | 'status' | 'due_date' | 'competence'

export type WorkProcessGroupSort
  = | 'label'
    | 'process_count'
    | 'open_task_count'
    | 'next_due_date'
    | 'progress_percent'

export interface OperationalProcessGroupClient {
  id: number
  name: string
  cnpj_masked?: string | null
}

export interface OperationalProcessGroupRoutine {
  id: number
  name: string
}

/** Item de `GET /api/v1/work/process-groups`. */
export interface OperationalProcessGroup {
  key: string
  label: string
  client?: OperationalProcessGroupClient | null
  routine?: OperationalProcessGroupRoutine | null
  client_count: number
  process_count: number
  task_count: number
  open_task_count: number
  completed_task_count: number
  progress_percent: number
  status_counts: Partial<Record<ProcessStatus, number>> | Record<string, number>
  next_due_date?: string | null
}

/** Chave estável do grupo de processos manuais (sem rotina). */
export const WORK_PROCESS_GROUP_MANUAL_KEY = 'manual' as const

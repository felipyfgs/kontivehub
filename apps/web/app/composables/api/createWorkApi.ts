import type { PageMeta } from '~/types/api'
import type {
  GenerationBatch,
  OperationalExportJob,
  OperationalProcess,
  OperationalProcessGroup,
  OperationalTaskDetail,
  OperationalTaskSummary,
  GenerationSelection,
  ProcessTemplate,
  ProcessTemplateCatalogItem,
  ProcessTemplateRecurrence,
  WorkDepartment,
  WorkKpis,
  WorkProcessGroupBy,
  WorkProcessGroupSort
} from '~/types/work'
import type { ApiClient, ApiUrl } from './types'

export type WorkProcessesListParams = {
  page?: number
  per_page?: number
  q?: string
  competence?: string
  status?: string
  client_id?: number
  department_id?: number
  assignee_membership_id?: number
  include_archived?: boolean | 0 | 1
  active_only?: boolean | 0 | 1
  sort?: string
  direction?: 'asc' | 'desc'
  /** Default API `1` / true — omitir array de tarefas com `0`. */
  include_tasks?: boolean | 0 | 1
  process_template_id?: number
  /** Processos sem rotina (`process_template_id` nulo). Não combinar com `process_template_id`. */
  without_template?: boolean | 0 | 1
} & Record<string, unknown>

export type WorkProcessGroupsListParams = {
  group_by: WorkProcessGroupBy
  page?: number
  per_page?: number
  q?: string
  competence?: string
  status?: string
  client_id?: number
  department_id?: number
  assignee_membership_id?: number
  include_archived?: boolean | 0 | 1
  sort?: WorkProcessGroupSort
  direction?: 'asc' | 'desc'
} & Record<string, unknown>

export function createWorkApi(client: ApiClient, apiUrl: ApiUrl) {
  return {
    work: {
      departments: {
        list: (params?: { page?: number, per_page?: number, is_active?: boolean }) =>
          client<{ data: WorkDepartment[], meta: PageMeta }>('/api/v1/work/departments', { query: params }),
        create: (body: { name: string, code: string, color?: string, is_active?: boolean }) =>
          client<{ data: WorkDepartment }>('/api/v1/work/departments', { method: 'POST', body }),
        update: (id: number, body: Partial<WorkDepartment>) =>
          client<{ data: WorkDepartment }>(`/api/v1/work/departments/${id}`, { method: 'PATCH', body }),
        assignMembership: (id: number, membershipId: number) =>
          client<{ data: { membership_id: number, work_department_id: number } }>(
            `/api/v1/work/departments/${id}/assign-membership`,
            { method: 'POST', body: { membership_id: membershipId } }
          )
      },
      templates: {
        catalog: () =>
          client<{ data: ProcessTemplateCatalogItem[] }>('/api/v1/work/template-catalog'),
        installCatalog: (
          catalogKey: string,
          body?: { name?: string, default_department_id?: number | null }
        ) => client<{ data: ProcessTemplate }>(
          `/api/v1/work/template-catalog/${encodeURIComponent(catalogKey)}/install`,
          { method: 'POST', body: body || {} }
        ),
        list: (params?: {
          page?: number
          per_page?: number
          is_active?: boolean
          q?: string
          sort?: 'name' | 'is_active' | 'id'
          direction?: 'asc' | 'desc'
        }) =>
          client<{ data: ProcessTemplate[], meta: PageMeta }>('/api/v1/work/templates', { query: params }),
        get: (id: number) =>
          client<{ data: ProcessTemplate }>(`/api/v1/work/templates/${id}`),
        create: (body: Record<string, unknown>) =>
          client<{ data: ProcessTemplate }>('/api/v1/work/templates', { method: 'POST', body }),
        update: (id: number, body: Record<string, unknown>) =>
          client<{ data: ProcessTemplate }>(`/api/v1/work/templates/${id}`, { method: 'PATCH', body }),
        preview: (id: number, body: {
          competence: string
          client_ids?: number[]
          selection?: GenerationSelection
          overrides?: Record<string, unknown>
          idempotency_key?: string
        }) =>
          client<{ data: GenerationBatch }>(`/api/v1/work/templates/${id}/preview`, { method: 'POST', body }),
        getRecurrence: (id: number) =>
          client<{ data: ProcessTemplateRecurrence & { lock_version: number } }>(
            `/api/v1/work/templates/${id}/recurrence`
          ),
        updateRecurrence: (id: number, body: Record<string, unknown>) =>
          client<{ data: ProcessTemplateRecurrence & { lock_version: number } }>(
            `/api/v1/work/templates/${id}/recurrence`,
            { method: 'PATCH', body }
          ),
        generationBatches: (id: number, params?: {
          page?: number
          per_page?: number
          status?: string
          competence?: string
        }) =>
          client<{ data: GenerationBatch[], meta: PageMeta }>(
            `/api/v1/work/templates/${id}/generation-batches`,
            { query: params }
          )
      },
      generation: {
        get: (id: number) =>
          client<{ data: GenerationBatch }>(`/api/v1/work/generation-batches/${id}`),
        confirm: (id: number, body?: { idempotency_key?: string }) =>
          client<{ data: GenerationBatch }>(`/api/v1/work/generation-batches/${id}/confirm`, {
            method: 'POST',
            body: body || {}
          }),
        retry: (id: number) =>
          client<{ data: GenerationBatch }>(`/api/v1/work/generation-batches/${id}/retry`, {
            method: 'POST',
            body: {}
          })
      },
      queue: (params?: Record<string, unknown>) =>
        client<{ data: OperationalTaskSummary[], meta: PageMeta }>('/api/v1/work/queue', { query: params }),
      processGroups: {
        list: (params: WorkProcessGroupsListParams) =>
          client<{ data: OperationalProcessGroup[], meta: PageMeta }>(
            '/api/v1/work/process-groups',
            { query: params }
          )
      },
      processes: {
        list: (params?: WorkProcessesListParams) =>
          client<{ data: OperationalProcess[], meta: PageMeta }>('/api/v1/work/processes', { query: params }),
        get: (id: number) =>
          client<{ data: OperationalProcess }>(`/api/v1/work/processes/${id}`),
        create: (body: Record<string, unknown>) =>
          client<{ data: OperationalProcess }>('/api/v1/work/processes', { method: 'POST', body }),
        update: (id: number, body: Record<string, unknown>) =>
          client<{ data: OperationalProcess }>(`/api/v1/work/processes/${id}`, { method: 'PATCH', body }),
        archive: (id: number, lockVersion: number) =>
          client<{ data: OperationalProcess }>(`/api/v1/work/processes/${id}/archive`, {
            method: 'POST',
            body: { lock_version: lockVersion }
          }),
        bulk: (body: {
          items: Array<{ id: number, lock_version: number }>
          changes: { action: string } & Record<string, unknown>
        }) =>
          client<{
            data: OperationalProcess[]
            meta: { succeeded: number, failed: Array<{ id: number, message: string }> }
          }>('/api/v1/work/processes/bulk', { method: 'POST', body }),
        comment: (id: number, body: string) =>
          client<{ data: { id: number, body: string } }>(`/api/v1/work/processes/${id}/comments`, {
            method: 'POST',
            body: { body }
          }),
        timeline: (id: number) =>
          client<{ data: Array<Record<string, unknown>> }>(`/api/v1/work/processes/${id}/timeline`)
      },
      tasks: {
        get: (id: number) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}`),
        bulk: (body: {
          items: Array<{ id: number, lock_version: number }>
          changes: Record<string, unknown>
        }) =>
          client<{
            data: OperationalTaskDetail[]
            meta: { succeeded: number, failed: Array<{ id: number, message: string }> }
          }>('/api/v1/work/tasks/bulk', { method: 'POST', body }),
        start: (id: number, lockVersion: number) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/start`, {
            method: 'POST',
            body: { lock_version: lockVersion }
          }),
        block: (id: number, lockVersion: number, reason: string) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/block`, {
            method: 'POST',
            body: { lock_version: lockVersion, reason }
          }),
        resume: (id: number, lockVersion: number) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/resume`, {
            method: 'POST',
            body: { lock_version: lockVersion }
          }),
        complete: (id: number, lockVersion: number) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/complete`, {
            method: 'POST',
            body: { lock_version: lockVersion }
          }),
        dispense: (id: number, lockVersion: number, justification: string) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/dispense`, {
            method: 'POST',
            body: { lock_version: lockVersion, justification }
          }),
        reopen: (id: number, lockVersion: number, justification: string) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/reopen`, {
            method: 'POST',
            body: { lock_version: lockVersion, justification }
          }),
        claim: (id: number, lockVersion: number) =>
          client<{ data: OperationalTaskDetail }>(`/api/v1/work/tasks/${id}/claim`, {
            method: 'POST',
            body: { lock_version: lockVersion }
          }),
        comment: (id: number, body: string) =>
          client<{ data: { id: number, body: string } }>(`/api/v1/work/tasks/${id}/comments`, {
            method: 'POST',
            body: { body }
          }),
        uploadEvidence: (id: number, file: File) => {
          const fd = new FormData()
          fd.append('file', file)
          return client<{ data: { id: number, original_filename: string } }>(
            `/api/v1/work/tasks/${id}/evidences`,
            { method: 'POST', body: fd }
          )
        },
        downloadEvidenceUrl: (taskId: number, evidenceId: number) =>
          apiUrl(`/api/v1/work/tasks/${taskId}/evidences/${evidenceId}/download`)
      },
      kpis: () => client<{ data: WorkKpis }>('/api/v1/work/kpis'),
      calendar: (from: string, to: string, params?: Record<string, string | number>) =>
        client<{
          data: {
            office_timezone: string
            today?: string
            from: string
            to: string
            days: Array<{
              date: string
              total: number
              overdue?: number
              fine?: number
              completed?: number
              open?: number
              max_severity?: number
              items?: OperationalTaskSummary[]
            }>
          }
        }>(
          '/api/v1/work/calendar',
          { query: { from, to, ...params } }
        ),
      calendarDay: (date: string, params?: Record<string, string | number>) =>
        client<{ data: OperationalTaskSummary[], meta: PageMeta }>('/api/v1/work/calendar/day', {
          query: { date, ...params }
        }),
      exports: {
        create: (filters?: Record<string, unknown>) =>
          client<{ data: OperationalExportJob }>('/api/v1/work/exports', {
            method: 'POST',
            body: { filters: filters || {} }
          }),
        get: (id: number) =>
          client<{ data: OperationalExportJob }>(`/api/v1/work/exports/${id}`),
        downloadUrl: (id: number) => apiUrl(`/api/v1/work/exports/${id}/download`)
      }
    }
  }
}

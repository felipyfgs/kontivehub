import type {
  CteCoverageSnapshot,
  CteHealth,
  CteOnboarding,
  CtePendingItem,
  CursorMeta,
  ExportFilters,
  ExportJob,
  InboxItem,
  InboxMeta,
  OperationsSummary,
  PageMeta,
  SyncRun
} from '~/types/api'
import type { ApiClient, ApiUrl, InboxListParams } from './types'

export function createOperationsApi(client: ApiClient, apiUrl: ApiUrl) {
  return {
    quarantine: {
      list: (params?: { reason?: string, limit?: number }) =>
        client<{ data: Array<Record<string, unknown>> }>('/api/v1/operations/quarantine', { query: params }),
      resolve: (id: number, body: { resolution_status: 'RESOLVED' | 'DISMISSED', resolution_code?: string, resolution_notes?: string }) =>
        client<{ data: Record<string, unknown> }>(`/api/v1/operations/quarantine/${id}/resolve`, {
          method: 'POST',
          body
        })
    },
    sync: {
      history: (params?: { cursor?: string, limit?: number }) =>
        client<{ data: SyncRun[], meta: CursorMeta }>('/api/v1/sync-runs', { query: params }),
      trigger: (establishmentId: number) =>
        client<{ data: { sync_cursor_id: number } }>('/api/v1/sync-runs', {
          method: 'POST',
          body: { establishment_id: establishmentId }
        })
    },
    cte: {
      onboarding: () =>
        client<{ data: CteOnboarding }>('/api/v1/cte/onboarding'),
      health: () =>
        client<{ data: CteHealth }>('/api/v1/cte/health'),
      coverage: (params?: { period?: string, client_id?: number, status?: string }) =>
        client<{
          data: CteCoverageSnapshot[]
          meta: { period: string, statuses: Array<{ value: string, label: string }> }
        }>('/api/v1/cte/coverage', { query: params }),
      pending: () =>
        client<{ data: CtePendingItem[] }>('/api/v1/cte/pending'),
      /** Reparo por consNSU conhecido — recusado em quiet/circuito (ADMIN/OPERATOR). */
      repair: (body: { cursor_id: number, nsu: number }) =>
        client<{
          data: {
            queued: boolean
            cursor_id: number
            nsu: number
            correlation_id: string
            cursor_last_nsu: number
          }
        }>('/api/v1/cte/repairs', { method: 'POST', body })
    },
    exports: {
      list: (params?: {
        page?: number
        per_page?: number
        sort?: 'id' | 'status' | 'created_at' | 'files_count'
        direction?: 'asc' | 'desc'
      }) =>
        client<{ data: ExportJob[], meta: PageMeta }>('/api/v1/exports', { query: params }),
      create: (body: { filters?: ExportFilters, include_events?: boolean }) =>
        client<{ data: ExportJob }>('/api/v1/exports', { method: 'POST', body }),
      downloadUrl: (id: number) => apiUrl(`/api/v1/exports/${id}/download`)
    },
    operations: {
      summary: () => client<{ data: OperationsSummary }>('/api/v1/operations/summary'),
      inbox: (params?: InboxListParams) =>
        client<{ data: InboxItem[], meta: InboxMeta }>('/api/v1/operations/inbox', { query: params })
    }
  }
}

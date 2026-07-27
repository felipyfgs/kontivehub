import type {
  CursorMeta,
  DfeDocumentMetadata,
  FiscalDocument,
  FiscalDocumentClientAggregate,
  FiscalDocumentInsights,
  PageMeta
} from '~/types/api'
import type { ApiClient, ApiUrl, DocumentListParams } from './types'

export function createDocumentsApi(client: ApiClient, apiUrl: ApiUrl) {
  return {
    documents: {
      list: (params?: DocumentListParams) =>
        client<{ data: FiscalDocument[], meta: CursorMeta }>('/api/v1/documents', { query: params }),
      byClient: (params?: DocumentListParams) =>
        client<{ data: FiscalDocumentClientAggregate[], meta: PageMeta & { total_clients: number } }>(
          '/api/v1/documents/by-client',
          { query: params }
        ),
      insights: (params?: DocumentListParams) =>
        client<{ data: FiscalDocumentInsights }>('/api/v1/documents/insights', { query: params }),
      get: (accessKey: string) => client<{
        data: {
          fiscal_document: FiscalDocument
          events: Array<{
            id: number
            access_key: string
            event_type?: string | null
            event_at?: string | null
            status?: string | null
          }>
          metadata: DfeDocumentMetadata | null
        }
      }>(`/api/v1/documents/${encodeURIComponent(accessKey)}`),
      xmlUrl: (accessKey: string) => apiUrl(`/api/v1/documents/${encodeURIComponent(accessKey)}/xml`),
      /** Desbloqueio de XML completo (ciência 210210). */
      unlockXml: (accessKey: string) =>
        client<{
          data: {
            status: string
            has_full_xml: boolean
            message: string
            manifestation_status?: string | null
            protocol?: string | null
          }
        }>(`/api/v1/documents/${encodeURIComponent(accessKey)}/unlock-xml`, { method: 'POST' }),
      /** Manifestação do destinatário (ciência / conclusivas). */
      manifest: (
        accessKey: string,
        body: {
          type: 'CIENCIA' | 'CONFIRMACAO' | 'DESCONHECIMENTO' | 'NAO_REALIZADA'
          justification?: string
          purpose?: 'UNLOCK_XML' | 'FISCAL'
        }
      ) =>
        client<{
          data: {
            status: string
            has_full_xml: boolean
            message: string
            manifestation_status?: string | null
            protocol?: string | null
            c_stat?: string | null
          }
        }>(`/api/v1/documents/${encodeURIComponent(accessKey)}/manifestations`, {
          method: 'POST',
          body
        }),
      /** Lote assíncrono de XML/ZIP de saídas (NF-e / NFC-e). */
      importBatch: (files: File[], opts?: { clientId?: number | null, establishmentId?: number | null, idempotencyKey?: string }) => {
        const body = new FormData()
        for (const file of files) {
          body.append('files[]', file)
        }
        if (opts?.clientId != null && opts.clientId > 0) {
          body.append('client_id', String(opts.clientId))
        }
        if (opts?.establishmentId != null && opts.establishmentId > 0) {
          body.append('establishment_id', String(opts.establishmentId))
        }
        if (opts?.idempotencyKey) {
          body.append('idempotency_key', opts.idempotencyKey)
        }
        return client<{
          data: {
            public_id: string
            status: string
            imported_count?: number
            duplicate_count?: number
            failed_count?: number
            unmatched_count?: number
            item_count?: number
            file_count?: number
            created?: boolean
          }
        }>('/api/v1/documents/import-batches', { method: 'POST', body })
      },
      importBatches: (params?: {
        page?: number
        per_page?: number
        sort?: 'id' | 'status' | 'created_at' | 'file_count' | 'imported_count'
        direction?: 'asc' | 'desc'
      }) =>
        client<{ data: Array<Record<string, unknown>>, meta?: PageMeta }>('/api/v1/documents/import-batches', { query: params }),
      importBatchGet: (publicId: string) =>
        client<{ data: Record<string, unknown> }>(`/api/v1/documents/import-batches/${encodeURIComponent(publicId)}`),
      importBatchItems: (publicId: string, params?: {
        page?: number
        per_page?: number
        status?: string
        sort?: 'item_index' | 'status' | 'source_name' | 'id'
        direction?: 'asc' | 'desc'
      }) =>
        client<{ data: Array<Record<string, unknown>>, meta?: PageMeta }>(
          `/api/v1/documents/import-batches/${encodeURIComponent(publicId)}/items`,
          { query: params }
        ),
      importBatchRetryItem: (publicId: string, itemId: number) =>
        client<{ data: Record<string, unknown> }>(
          `/api/v1/documents/import-batches/${encodeURIComponent(publicId)}/items/${itemId}/retry`,
          { method: 'POST' }
        ),
      importBatchCsvUrl: (publicId: string) =>
        apiUrl(`/api/v1/documents/import-batches/${encodeURIComponent(publicId)}/export.csv`)
    }
  }
}

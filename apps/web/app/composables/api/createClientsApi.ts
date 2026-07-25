import type {
  Client,
  ClientCategory,
  ClientCategoryColor,
  ClientContact,
  ClientCredential,
  ClientCustomField,
  CnpjLookupResult,
  CreateClientPayload,
  CreateClientResponse,
  Establishment,
  PageMeta
} from '~/types/api'
import type { ApiClient, ClientListParams } from './types'

export function createClientsApi(client: ApiClient) {
  return {
    clients: {
      list: (params?: ClientListParams) =>
        client<{ data: Client[], meta: PageMeta }>('/api/v1/clients', { query: params }),
      create: (body: CreateClientPayload) =>
        client<{ data: CreateClientResponse }>('/api/v1/clients', { method: 'POST', body }),
      get: (id: number) => client<{ data: Client }>(`/api/v1/clients/${id}`),
      update: (id: number, body: Partial<{
        legal_name: string
        display_name: string | null
        notes: string | null
        is_active: boolean
        inactive_reason: string | null
        legal_nature_code: string | null
        legal_nature_name: string | null
        company_size_code: string | null
        company_size_name: string | null
        tax_regime: string | null
        work_department_id: number | null
      }>) =>
        client<{ data: Client }>(`/api/v1/clients/${id}`, { method: 'PATCH', body }),
      updateCustomField: (
        clientId: number,
        fieldId: number,
        body: Partial<{ label: string, is_active: boolean, value: string | null }>
      ) =>
        client<{ data: ClientCustomField }>(
          `/api/v1/clients/${clientId}/custom-fields/${fieldId}`,
          { method: 'PATCH', body }
        ),
      refreshRegistration: (id: number, body?: { lookup?: CnpjLookupResult }) =>
        client<{ data: Client & { lookup?: CnpjLookupResult } }>(
          `/api/v1/clients/${id}/refresh-registration`,
          { method: 'POST', body: body ?? {} }
        ),
      bulkStatus: (body: {
        client_ids: number[]
        is_active: boolean
        inactive_reason?: string | null
      }) => client<{
        data: { updated: number, client_ids: number[], is_active: boolean }
      }>('/api/v1/clients/bulk-status', { method: 'PATCH', body }),
      replaceCategories: (id: number, categoryIds: number[]) =>
        client<{
          data: { client_id: number, categories: Client['categories'], added: number, removed: number }
        }>(`/api/v1/clients/${id}/categories`, {
          method: 'PUT',
          body: { category_ids: categoryIds }
        }),
      bulkCategories: (body: {
        operation: 'add' | 'remove'
        client_ids: number[]
        category_ids: number[]
      }) => client<{
        data: {
          operation: 'add' | 'remove'
          updated_clients: number
          client_ids: number[]
          category_ids: number[]
          created_links: number
          removed_links: number
        }
      }>('/api/v1/clients/bulk-categories', { method: 'PATCH', body })
    },
    clientCategories: {
      list: (includeArchived = false) => client<{ data: ClientCategory[] }>(
        '/api/v1/client-categories',
        { query: includeArchived ? { include_archived: 1 } : undefined }
      ),
      create: (body: { name: string, color: ClientCategoryColor }) =>
        client<{ data: ClientCategory }>('/api/v1/client-categories', { method: 'POST', body }),
      update: (id: number, body: Partial<{
        name: string
        color: ClientCategoryColor
        is_active: boolean
      }>) => client<{ data: ClientCategory }>(`/api/v1/client-categories/${id}`, {
        method: 'PATCH',
        body
      })
    },
    cnpj: {
      lookup: (cnpj: string) => client<{ data: CnpjLookupResult }>(
        `/api/v1/cnpj/${encodeURIComponent(cnpj)}/lookup`
      )
    },
    establishments: {
      create: (clientId: number, body: Record<string, unknown>) =>
        client<{ data: Establishment }>(`/api/v1/clients/${clientId}/establishments`, { method: 'POST', body }),
      update: (id: number, body: Record<string, unknown>) =>
        client<{ data: Establishment }>(`/api/v1/establishments/${id}`, { method: 'PATCH', body })
    },
    contacts: {
      list: (clientId: number) =>
        client<{ data: ClientContact[] }>(`/api/v1/clients/${clientId}/contacts`),
      create: (clientId: number, body: Record<string, unknown>) =>
        client<{ data: ClientContact }>(`/api/v1/clients/${clientId}/contacts`, { method: 'POST', body }),
      update: (clientId: number, contactId: number, body: Record<string, unknown>) =>
        client<{ data: ClientContact }>(`/api/v1/clients/${clientId}/contacts/${contactId}`, { method: 'PATCH', body }),
      remove: (clientId: number, contactId: number) =>
        client(`/api/v1/clients/${clientId}/contacts/${contactId}`, { method: 'DELETE' })
    },
    credentials: {
      get: (clientId: number) =>
        client<{ data: ClientCredential | null }>(`/api/v1/clients/${clientId}/credential`),
      activate: (clientId: number, pfx: File, password: string) => {
        const body = new FormData()
        body.append('pfx', pfx)
        body.append('password', password)
        return client<{ data: ClientCredential }>(`/api/v1/clients/${clientId}/credential`, {
          method: 'POST',
          body
        })
      }
    }
  }
}

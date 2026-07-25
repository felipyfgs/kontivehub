import type {
  CreateSavedListFilterBody,
  SavedListFilter,
  UpdateSavedListFilterBody
} from '~/types/saved-list-filters'
import type { ApiClient } from './types'

/**
 * Presets nomeados de filtros de lista (tenant via CurrentOffice).
 * Nunca envia office_id — escopo só no servidor.
 */
export function createSavedListFiltersApi(client: ApiClient) {
  const base = '/api/v1/list-filters'
  return {
    savedListFilters: {
      /** GET ?surface= — personal do user + office do CurrentOffice. */
      list: (params: { surface: string }) =>
        client<{ data: SavedListFilter[] }>(base, {
          query: { surface: params.surface }
        }),

      create: (body: CreateSavedListFilterBody) =>
        client<{ data: SavedListFilter }>(base, {
          method: 'POST',
          body: {
            surface: body.surface,
            name: body.name,
            visibility: body.visibility,
            payload: body.payload,
            schema_version: body.schema_version ?? 1
          }
        }),

      update: (id: number, patch: UpdateSavedListFilterBody) =>
        client<{ data: SavedListFilter }>(`${base}/${id}`, {
          method: 'PATCH',
          body: patch
        }),

      delete: (id: number) =>
        client<null | undefined>(`${base}/${id}`, {
          method: 'DELETE'
        })
    }
  }
}

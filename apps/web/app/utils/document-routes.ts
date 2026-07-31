import { parsePositiveRouteId } from '~/utils/route-params'

export const DOCUMENTS_INDEX_PATH = '/docs'
export const DOCUMENTS_CATALOG_PATH = '/docs/catalog'
export const DOCUMENT_IMPORT_CREATE_PATH = '/docs/imports/new'

export const DOCUMENT_CONTEXT_KINDS = ['NFSE', 'NFE', 'NFCE', 'CTE'] as const
export type DocumentContextKind = typeof DOCUMENT_CONTEXT_KINDS[number]

export function normalizeDocumentContextKind(value: unknown): DocumentContextKind | null {
  const raw = Array.isArray(value) ? value[0] : value
  const kind = typeof raw === 'string' ? raw.trim().toUpperCase() : ''
  return (DOCUMENT_CONTEXT_KINDS as readonly string[]).includes(kind)
    ? kind as DocumentContextKind
    : null
}

export function parseDocumentClientId(value: unknown): number | null {
  return parsePositiveRouteId(value)
}

export function documentCatalogTypePath(value: unknown): string {
  const kind = normalizeDocumentContextKind(value)
  return kind ? `${DOCUMENTS_CATALOG_PATH}/type/${kind}` : DOCUMENTS_CATALOG_PATH
}

export function documentCatalogClientPath(value: unknown): string {
  const clientId = parseDocumentClientId(value)
  return clientId ? `${DOCUMENTS_CATALOG_PATH}/client/${clientId}` : DOCUMENTS_CATALOG_PATH
}

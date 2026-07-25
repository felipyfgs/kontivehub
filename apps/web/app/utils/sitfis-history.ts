import type { SitfisHistorySearch } from '~/types/fiscal-modules'

export function sitfisHistoryDownloadPath(search: SitfisHistorySearch): string | null {
  const link = search.links?.evidence_download?.trim()
  if (link) return link
  return search.evidence_artifact_id != null
    ? `/api/v1/fiscal/evidence/${search.evidence_artifact_id}/download`
    : null
}

export function sitfisHistoryFilename(search: SitfisHistorySearch): string {
  const date = String(search.observed_at || '').slice(0, 10) || 'historico'
  return `relatorio-sitfis-${date}.pdf`
}

import type {
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking,
  PgdasdDeclarationState,
  PgdasdHistoryPayload,
  PgdasdHistoryPeriod
} from '~/types/fiscal-modules'
import { pgdasdDeclarationMeta } from '~/utils/pgdasd'

/**
 * Cliente HTTP local-only para histórico, preferências e comunicação TEMPLATE_ONLY.
 * Abrir histórico/prévia/rastreio NÃO dispara consulta SERPRO.
 */
export function usePgdasdMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(
    clientId: number,
    params?: { year?: number }
  ): Promise<PgdasdHistoryPayload | PgdasdHistoryPeriod[]> {
    const res = await fiscal.pgdasd.history(clientId, params)
    return res.data
  }

  async function updatePreferences(
    clientId: number,
    body: {
      email_enabled: boolean
      whatsapp_enabled: boolean
      automatic_requested: boolean
      lock_version: number
    }
  ): Promise<PgdasdCommunicationPreference> {
    const res = await fiscal.pgdasd.communication.updatePreference(clientId, body)
    return res.data
  }

  async function batchAutomatic(
    clientIds: number[],
    automaticRequested: boolean
  ): Promise<PgdasdCommunicationPreference[]> {
    const res = await fiscal.pgdasd.communication.updateBulk({
      client_ids: clientIds,
      automatic_requested: automaticRequested
    })
    return res.data
  }

  async function fetchPreview(clientId: number): Promise<PgdasdCommunicationPreview> {
    const res = await fiscal.pgdasd.communication.preview(clientId)
    return res.data
  }

  async function fetchTracking(clientId: number): Promise<PgdasdCommunicationTracking> {
    const res = await fiscal.pgdasd.communication.tracking(clientId)
    return res.data
  }

  async function requestSend(clientId: number) {
    const res = await fiscal.pgdasd.communication.send(clientId)
    return res.data
  }

  async function collectDocuments(
    clientId: number,
    body: { period_key: string, declaration_number?: string | null, confirmed: true }
  ) {
    return fiscal.pgdasd.collectDocuments(clientId, body)
  }

  function artifactDownloadUrl(artifactId: number): string {
    return fiscal.pgdasd.artifactDownloadUrl(artifactId)
  }

  return {
    fetchHistory,
    updatePreferences,
    batchAutomatic,
    fetchPreview,
    fetchTracking,
    requestSend,
    collectDocuments,
    artifactDownloadUrl
  }
}

export function pgdasdStateMeta(state: string | null | undefined): {
  label: string
  color: 'success' | 'warning' | 'error' | 'neutral'
  icon: string
  tooltip: string
} {
  const meta = pgdasdDeclarationMeta(state)
  return {
    label: meta.label,
    color: meta.color === 'info' ? 'neutral' : meta.color,
    icon: meta.icon,
    tooltip: meta.description
  }
}

export type { PgdasdDeclarationState }

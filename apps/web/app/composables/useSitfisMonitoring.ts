import type {
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking,
  SitfisHistoryPayload,
  SitfisRefreshResponse,
  SitfisShowResponse
} from '~/types/fiscal-modules'

/**
 * Cliente HTTP local para show/refresh SITFIS e comunicação TEMPLATE_ONLY.
 * Abrir prévia/rastreio NÃO dispara consulta SERPRO.
 */
export function useSitfisMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<SitfisHistoryPayload> {
    const res = await fiscal.sitfis.history(clientId)
    return res.data
  }

  async function show(clientId: number): Promise<SitfisShowResponse> {
    const res = await fiscal.sitfis.show(clientId)
    return res.data
  }

  async function refresh(body: { client_id: number, force?: boolean }): Promise<SitfisRefreshResponse> {
    const res = await fiscal.sitfis.refresh(body)
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
    const res = await fiscal.sitfis.communication.updatePreference(clientId, body)
    return res.data
  }

  async function fetchPreview(clientId: number): Promise<PgdasdCommunicationPreview> {
    const res = await fiscal.sitfis.communication.preview(clientId)
    return res.data
  }

  async function fetchTracking(clientId: number): Promise<PgdasdCommunicationTracking> {
    const res = await fiscal.sitfis.communication.tracking(clientId)
    return res.data
  }

  async function requestSend(clientId: number) {
    const res = await fiscal.sitfis.communication.send(clientId)
    return res.data
  }

  function evidenceDownloadUrl(artifactId: number): string {
    return `/api/v1/fiscal/evidence/${artifactId}/download`
  }

  return {
    fetchHistory,
    show,
    refresh,
    updatePreferences,
    fetchPreview,
    fetchTracking,
    requestSend,
    evidenceDownloadUrl
  }
}

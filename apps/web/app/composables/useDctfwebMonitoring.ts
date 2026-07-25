import type {
  DctfwebHistoryPayload,
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking
} from '~/types/fiscal-modules'

/**
 * Cliente HTTP local-only para histórico, preferências e comunicação TEMPLATE_ONLY DCTFWeb.
 * Abrir histórico/prévia/rastreio NÃO dispara consulta SERPRO.
 */
export function useDctfwebMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(
    clientId: number,
    params?: { year?: number }
  ): Promise<DctfwebHistoryPayload> {
    const res = await fiscal.dctfweb.history(clientId, params)
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
    const res = await fiscal.dctfweb.communication.updatePreference(clientId, body)
    return res.data
  }

  async function batchAutomatic(
    clientIds: number[],
    automaticRequested: boolean
  ): Promise<PgdasdCommunicationPreference[]> {
    const res = await fiscal.dctfweb.communication.updateBulk({
      client_ids: clientIds,
      automatic_requested: automaticRequested
    })
    return res.data
  }

  async function fetchPreview(clientId: number): Promise<PgdasdCommunicationPreview> {
    const res = await fiscal.dctfweb.communication.preview(clientId)
    return res.data
  }

  async function fetchTracking(clientId: number): Promise<PgdasdCommunicationTracking> {
    const res = await fiscal.dctfweb.communication.tracking(clientId)
    return res.data
  }

  async function requestSend(clientId: number) {
    const res = await fiscal.dctfweb.communication.send(clientId)
    return res.data
  }

  function evidenceDownloadUrl(clientId: number, evidenceId: number): string {
    return fiscal.dctfweb.evidenceDownloadUrl(clientId, evidenceId)
  }

  return {
    fetchHistory,
    updatePreferences,
    batchAutomatic,
    fetchPreview,
    fetchTracking,
    requestSend,
    evidenceDownloadUrl
  }
}

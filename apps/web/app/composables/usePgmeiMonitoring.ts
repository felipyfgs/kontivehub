import type {
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking,
  PgmeiHistoryPayload
} from '~/types/fiscal-modules'

/**
 * Leitura local do PGMEI e comunicação TEMPLATE_ONLY.
 * Histórico, prévia e rastreio não disparam consulta SERPRO.
 */
export function usePgmeiMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number, year: number): Promise<PgmeiHistoryPayload> {
    const response = await fiscal.pgmei.history(clientId, { year })
    return response.data
  }

  async function requestConsult(clientIds: number[], year: number) {
    return fiscal.pgmei.consult({ client_ids: clientIds, year, confirmed: true })
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
    const response = await fiscal.pgmei.communication.updatePreference(clientId, body)
    return response.data
  }

  async function batchAutomatic(
    clientIds: number[],
    automaticRequested: boolean
  ): Promise<PgdasdCommunicationPreference[]> {
    const response = await fiscal.pgmei.communication.updateBulk({
      client_ids: clientIds,
      automatic_requested: automaticRequested
    })
    return response.data
  }

  async function fetchPreview(clientId: number): Promise<PgdasdCommunicationPreview> {
    const response = await fiscal.pgmei.communication.preview(clientId)
    return response.data
  }

  async function fetchTracking(clientId: number): Promise<PgdasdCommunicationTracking> {
    const response = await fiscal.pgmei.communication.tracking(clientId)
    return response.data
  }

  async function requestSend(clientId: number) {
    const response = await fiscal.pgmei.communication.send(clientId)
    return response.data
  }

  return {
    fetchHistory,
    requestConsult,
    updatePreferences,
    batchAutomatic,
    fetchPreview,
    fetchTracking,
    requestSend
  }
}

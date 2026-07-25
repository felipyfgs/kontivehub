import type { DefisDeclarationsHistoryPayload } from '~/types/fiscal-modules'

/** Leitura local e solicitação explícita da lista DEFIS 142. */
export function useDefisDeclarationsMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<DefisDeclarationsHistoryPayload> {
    const response = await fiscal.simplesMei.defis.history(clientId)
    return response.data
  }

  async function requestConsult(clientId: number) {
    return fiscal.simplesMei.defis.consult(clientId)
  }

  return { fetchHistory, requestConsult }
}

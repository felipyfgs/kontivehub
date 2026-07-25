import type { CcmeiHistoryPayload } from '~/types/fiscal-modules'

/** Consulta explícita e histórico local sanitizado do CCMEI. */
export function useCcmeiMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<CcmeiHistoryPayload> {
    const response = await fiscal.ccmei.history(clientId)
    return response.data
  }

  async function requestConsult(clientId: number) {
    return fiscal.ccmei.consult(clientId)
  }

  return { fetchHistory, requestConsult }
}

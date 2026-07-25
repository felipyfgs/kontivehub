import type { SicalcRevenueSupportHistoryPayload } from '~/types/fiscal-modules'

/** Consulta explícita e histórico local sanitizado do SICALC 5.2. */
export function useSicalcRevenueSupportMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number, revenueCode?: string): Promise<SicalcRevenueSupportHistoryPayload> {
    const response = await fiscal.sicalcRevenueSupport.history(clientId, revenueCode)
    return response.data
  }

  async function requestConsult(clientId: number, revenueCode: string) {
    return fiscal.sicalcRevenueSupport.consult(clientId, revenueCode)
  }

  return { fetchHistory, requestConsult }
}

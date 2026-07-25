import type { PagtowebPaymentCountHistoryPayload } from '~/types/fiscal-modules'

/** Histórico e disparo confirmado da contagem agregada PAGTOWEB 7.3. */
export function usePagtowebPaymentCountMonitoring() {
  const { fiscal } = useApi()
  async function fetchHistory(clientId: number): Promise<PagtowebPaymentCountHistoryPayload> {
    return (await fiscal.pagtowebPaymentCount.history(clientId)).data
  }
  async function requestConsult(clientId: number, filters: Record<string, unknown>) {
    return fiscal.pagtowebPaymentCount.consult(clientId, filters)
  }
  return { fetchHistory, requestConsult }
}

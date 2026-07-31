import type { PagtoWebPaymentCountHistoryPayload } from '~/types/fiscal-modules'

/** Histórico e disparo confirmado da contagem agregada PAGTOWEB 7.3. */
export function usePagtoWebPaymentCount() {
  const { fiscal } = useApi()
  async function fetchHistory(clientId: number): Promise<PagtoWebPaymentCountHistoryPayload> {
    return (await fiscal.pagtoWebPaymentCount.history(clientId)).data
  }
  async function requestConsult(clientId: number, filters: Record<string, unknown>) {
    return fiscal.pagtoWebPaymentCount.consult(clientId, filters)
  }
  return { fetchHistory, requestConsult }
}

import type { PagtowebPaymentListHistoryPayload } from '~/types/fiscal-modules'

/** Histórico e disparo confirmado da listagem sanitizada PAGTOWEB 7.1. */
export function usePagtowebPaymentListMonitoring() {
  const { fiscal } = useApi()
  async function fetchHistory(clientId: number, page = 1, perPage = 50): Promise<PagtowebPaymentListHistoryPayload> {
    return (await fiscal.pagtowebPaymentList.history(clientId, { page, per_page: perPage })).data
  }
  async function requestConsult(clientId: number, filters: Record<string, unknown>) {
    return fiscal.pagtowebPaymentList.consult(clientId, filters)
  }
  return { fetchHistory, requestConsult }
}

import type { PagtoWebPaymentListHistoryPayload } from '~/types/fiscal-modules'

/** Histórico e disparo confirmado da listagem sanitizada PAGTOWEB 7.1. */
export function usePagtoWebPaymentList() {
  const { fiscal } = useApi()
  async function fetchHistory(clientId: number, page = 1, perPage = 50): Promise<PagtoWebPaymentListHistoryPayload> {
    return (await fiscal.pagtoWebPaymentList.history(clientId, { page, per_page: perPage })).data
  }
  async function requestConsult(clientId: number, filters: Record<string, unknown>) {
    return fiscal.pagtoWebPaymentList.consult(clientId, filters)
  }
  return { fetchHistory, requestConsult }
}

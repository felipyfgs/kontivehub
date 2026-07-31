import type { PagtoWebArrecadacaoReceiptHistoryPayload } from '~/types/fiscal-modules'

/** Histórico local e consulta manual confirmada do comprovante PAGTOWEB 7.2. */
export function usePagtoWebArrecadacaoReceipt() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<PagtoWebArrecadacaoReceiptHistoryPayload> {
    return (await fiscal.pagtoWebArrecadacaoReceipt.history(clientId)).data
  }

  async function requestReceipt(clientId: number, numeroDocumento: string) {
    return (await fiscal.pagtoWebArrecadacaoReceipt.request(clientId, numeroDocumento)).data
  }

  function downloadPath(clientId: number, receiptId: number): string {
    return fiscal.pagtoWebArrecadacaoReceipt.downloadPath(clientId, receiptId)
  }

  return { fetchHistory, requestReceipt, downloadPath }
}

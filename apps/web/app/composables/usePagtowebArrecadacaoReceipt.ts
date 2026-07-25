import type { PagtowebArrecadacaoReceiptHistoryPayload } from '~/types/fiscal-modules'

/** Histórico local e consulta manual confirmada do comprovante PAGTOWEB 7.2. */
export function usePagtowebArrecadacaoReceipt() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<PagtowebArrecadacaoReceiptHistoryPayload> {
    return (await fiscal.pagtowebArrecadacaoReceipt.history(clientId)).data
  }

  async function requestReceipt(clientId: number, numeroDocumento: string) {
    return (await fiscal.pagtowebArrecadacaoReceipt.request(clientId, numeroDocumento)).data
  }

  function downloadPath(clientId: number, receiptId: number): string {
    return fiscal.pagtowebArrecadacaoReceipt.downloadPath(clientId, receiptId)
  }

  return { fetchHistory, requestReceipt, downloadPath }
}

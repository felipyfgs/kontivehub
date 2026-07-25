import type { RegimeOptionPayload } from '~/types/fiscal-modules'

/** Leitura local e solicitação explícita da opção anual do serviço 103. */
export function useRegimeOptionMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<RegimeOptionPayload> {
    return fiscal.simplesMei.regimeOption.list(clientId)
  }

  async function requestConsult(clientId: number, year: number) {
    return fiscal.simplesMei.regimeOption.consult({ client_id: clientId, year })
  }

  return { fetchHistory, requestConsult }
}

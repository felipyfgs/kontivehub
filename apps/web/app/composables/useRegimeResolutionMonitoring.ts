import type { RegimeResolutionPayload } from '~/types/fiscal-modules'

/** Leitura local e solicitação explícita da resolução 104. */
export function useRegimeResolutionMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<RegimeResolutionPayload> {
    return fiscal.simplesMei.regimeResolution.list(clientId)
  }

  async function requestConsult(clientId: number, year: number) {
    return fiscal.simplesMei.regimeResolution.consult({ client_id: clientId, year })
  }

  return { fetchHistory, requestConsult }
}

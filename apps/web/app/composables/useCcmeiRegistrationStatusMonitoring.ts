import type { CcmeiRegistrationStatusHistoryPayload } from '~/types/fiscal-modules'

/** Consulta explícita e histórico local sanitizado de CCMEISITCADASTRAL123. */
export function useCcmeiRegistrationStatusMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<CcmeiRegistrationStatusHistoryPayload> {
    const response = await fiscal.ccmei.registrationStatus.history(clientId)
    return response.data
  }

  async function requestConsult(clientId: number) {
    return fiscal.ccmei.registrationStatus.consult(clientId)
  }

  return { fetchHistory, requestConsult }
}

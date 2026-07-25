import type { DefisLatestDeclarationHistoryPayload } from '~/types/fiscal-modules'

/** Histórico local e consulta explicitamente confirmada da DEFIS 143. */
export function useDefisLatestDeclarationMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number, year?: number): Promise<DefisLatestDeclarationHistoryPayload> {
    return (await fiscal.simplesMei.defisLatestDeclaration.history(clientId, year)).data
  }

  function requestConsult(clientId: number, calendarYear: number) {
    return fiscal.simplesMei.defisLatestDeclaration.consult(clientId, calendarYear)
  }

  return { fetchHistory, requestConsult }
}

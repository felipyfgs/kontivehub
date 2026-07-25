import type { DefisSpecificDeclarationHistoryPayload } from '~/types/fiscal-modules'

/** Histórico local e consulta confirmada da declaração DEFIS selecionada. */
export function useDefisSpecificDeclarationMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number, referenceId?: number): Promise<DefisSpecificDeclarationHistoryPayload> {
    return (await fiscal.simplesMei.defisSpecificDeclaration.history(clientId, referenceId)).data
  }

  function requestConsult(clientId: number, referenceId: number) {
    return fiscal.simplesMei.defisSpecificDeclaration.consult(clientId, referenceId)
  }

  return { fetchHistory, requestConsult }
}

import type { MitListaApuracoes317Payload } from '~/types/fiscal-modules'

/**
 * Leitor local da lista MIT 317. A consulta oficial é sempre explícita no
 * backend; abrir esta interface nunca inicia job nem chama o SERPRO.
 */
export function useMitListaApuracoes() {
  const { fiscal } = useApi()

  async function fetchLocalList(
    clientId: number,
    params?: { year?: number }
  ): Promise<MitListaApuracoes317Payload> {
    return fiscal.mit.listaApuracoes(clientId, params)
  }

  async function enqueueList(
    clientId: number,
    filters: { year: number, month?: number, situation?: number }
  ) {
    return fiscal.mit.enqueueListaApuracoes({
      client_id: clientId,
      anoApuracao: filters.year,
      ...(filters.month ? { mesApuracao: filters.month } : {}),
      ...(filters.situation != null ? { situacaoApuracao: filters.situation } : {})
    })
  }

  return { fetchLocalList, enqueueList }
}

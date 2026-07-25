import type { RegimeCalendarPayload } from '~/types/fiscal-modules'

/** Leitura local e solicitação explícita da consulta 102. */
export function useRegimeCalendarMonitoring() {
  const { fiscal } = useApi()

  async function fetchHistory(clientId: number): Promise<RegimeCalendarPayload> {
    return fiscal.simplesMei.regimeCalendar.list(clientId)
  }

  async function requestConsult(clientId: number) {
    return fiscal.simplesMei.regimeCalendar.consult({ client_id: clientId })
  }

  return { fetchHistory, requestConsult }
}

/**
 * Ponte canônica CRM ↔ detalhe fiscal do mesmo cliente.
 * Evita strings soltas (`/clients` sem id) e mantém um único mapa de destino.
 *
 * Sem import de client-detail-tabs (evita ciclo: tabs → cross-links → tabs).
 */
import type { ClientFiscalSectionKey } from '~/utils/client-fiscal-detail-navigation'

export type ClientCrmTab
  = 'cadastro'
    | 'dados-adicionais'
    | 'contato'
    | 'departamento'
    | 'observacoes'
    | 'contratos'

/** Destino CRM: `/clients/:id/:tab`. */
export function clientCrmHref(
  clientId: string | number,
  tab: ClientCrmTab = 'cadastro'
): string {
  return `/clients/${clientId}/${tab}`
}

/** Destino fiscal: `/monitoring/clients/:id` ou `.../:section`. */
export function clientFiscalHref(
  clientId: string | number,
  section: ClientFiscalSectionKey = 'overview'
): string {
  const base = `/monitoring/clients/${clientId}`
  return section === 'overview' ? base : `${base}/${section}`
}

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
    /** @deprecated use dados-adicionais */
    | 'configuracao'

/** Destino CRM: `/clients/:id/:tab` (configuracao → dados-adicionais). */
export function clientCrmHref(
  clientId: string | number,
  tab: ClientCrmTab = 'cadastro'
): string {
  const segment = tab === 'configuracao' ? 'dados-adicionais' : tab
  return `/clients/${clientId}/${segment}`
}

/** Destino fiscal: `/monitoring/clients/:id` ou `.../:section`. */
export function clientFiscalHref(
  clientId: string | number,
  section: ClientFiscalSectionKey = 'overview'
): string {
  const base = `/monitoring/clients/${clientId}`
  return section === 'overview' ? base : `${base}/${section}`
}

/**
 * Segmentos de path legado em `/clients/:id/:segment` que eram hubs fiscais
 * e agora vivem no detalhe de monitoramento.
 */
const LEGACY_FISCAL_SEGMENT_MAP: Record<string, ClientFiscalSectionKey> = {
  fiscal: 'overview',
  ccmei: 'ccmei',
  renuncias: 'renunciations',
  pagamentos: 'overview',
  comprovantes: 'overview',
  sicalc: 'overview'
}

export function legacyFiscalSegmentToHref(
  clientId: string | number,
  segment?: string | null
): string | null {
  if (!segment) return null
  const section = LEGACY_FISCAL_SEGMENT_MAP[segment]
  if (!section) return null
  return clientFiscalHref(clientId, section)
}

export function isLegacyFiscalSegment(segment?: string | null): boolean {
  return Boolean(segment && segment in LEGACY_FISCAL_SEGMENT_MAP)
}

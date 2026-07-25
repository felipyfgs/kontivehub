/** Catálogo plano dos contextos fiscais globais de Monitoramento. */
import type { MeUser } from '~/types/api'
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import { MONITORING_NAV_ITEMS } from '~/utils/monitoring-nav'
import { canViewFiscal } from '~/utils/permissions'
import { filterNavItems } from '~/utils/navigation-hierarchy'

export const MONITORING_CONTEXT_ITEMS: NavLeafDestination[] = MONITORING_NAV_ITEMS.map(item => ({
  id: item.id,
  label: item.label,
  icon: item.icon,
  to: item.to,
  exact: item.exact,
  capability: 'view-fiscal'
}))

/**
 * Catálogo fiscal filtrado por capacidade.
 * Enquanto `me` ainda não hidratou, devolve o catálogo estático para não
 * esvaziar o sidebar; middleware e backend continuam autorizando a rota.
 */
export function fiscalNavigationItems(user?: MeUser | null): NavLayerItem[] {
  if (user == null) return MONITORING_CONTEXT_ITEMS
  if (!canViewFiscal(user)) return []
  return filterNavItems(MONITORING_CONTEXT_ITEMS, () => true)
}

/** Folhas na ordem canônica do sidebar e da busca global. */
export function fiscalNavLeaves(): NavLeafDestination[] {
  return MONITORING_CONTEXT_ITEMS.map(item => ({ ...item }))
}

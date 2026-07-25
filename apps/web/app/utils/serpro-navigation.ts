/**
 * Taxonomia do console SERPRO (Admin).
 * Sidebar global: um destino Admin → SERPRO.
 * Shell: Visão geral / Configuração via SectionNavigation.
 * Consumo·Liberação·Contratos·Cobertura·Canário permanecem deep-links.
 */
import type { NavLayerItem } from '~/utils/navigation-hierarchy'
import { validateNavCatalog } from '~/utils/navigation-hierarchy'

export const SERPRO_NAV_ITEMS: NavLayerItem[] = [
  {
    id: 'serpro-overview',
    label: 'Visão geral',
    icon: 'i-lucide-gauge',
    to: '/admin/serpro',
    exact: true,
    isActive: (path) => {
      if (path.startsWith('/admin/serpro/configuration')) return false
      if (path.startsWith('/admin/serpro/contracts')) return false
      if (path.startsWith('/admin/serpro/catalog')) return false
      if (path.startsWith('/admin/serpro/dte-canary')) return false
      return path === '/admin/serpro'
        || path === '/admin/serpro/'
        || path.startsWith('/admin/serpro/usage')
        || path.startsWith('/admin/serpro/rollout')
    }
  },
  {
    id: 'serpro-configuration',
    label: 'Configuração',
    icon: 'i-lucide-settings-2',
    to: '/admin/serpro/configuration',
    isActive: path =>
      path.startsWith('/admin/serpro/configuration')
      || path.startsWith('/admin/serpro/contracts')
      || path.startsWith('/admin/serpro/catalog')
  }
]

validateNavCatalog(SERPRO_NAV_ITEMS)

/**
 * Taxonomia Documentos (com Processamento) e Operações.
 */
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import { flattenNavLeaves, validateNavCatalog } from '~/utils/navigation-hierarchy'

export const DOCS_NAV_ITEMS: NavLayerItem[] = [
  {
    id: 'docs-by-client',
    label: 'Por cliente',
    icon: 'i-lucide-building-2',
    to: '/docs',
    exact: true,
    isActive: path => path === '/docs'
  },
  {
    id: 'docs-catalog',
    label: 'Catálogo',
    icon: 'i-lucide-file-stack',
    to: '/docs/catalog',
    isActive: (path) => {
      if (path === '/docs/catalog') return true
      if (!path.startsWith('/docs/')) return false
      if (path.startsWith('/docs/imports')) return false
      if (path === '/docs') return false
      return true
    }
  },
  {
    id: 'docs-processing',
    label: 'Processamento',
    icon: 'i-lucide-cog',
    children: [
      {
        id: 'docs-imports',
        label: 'Importações',
        icon: 'i-lucide-upload',
        to: '/docs/imports',
        isActive: path => path === '/docs/imports' || path.startsWith('/docs/imports/')
      },
      {
        id: 'docs-exports',
        label: 'Exportações',
        icon: 'i-lucide-package',
        to: '/exports',
        isActive: path => path === '/exports' || path.startsWith('/exports/')
      }
    ]
  }
]

validateNavCatalog(DOCS_NAV_ITEMS)

export const OPERATIONS_NAV_ITEMS: NavLayerItem[] = [
  {
    id: 'health',
    label: 'Saúde',
    icon: 'i-lucide-heart-pulse',
    to: '/health'
  },
  {
    id: 'syncs',
    label: 'Sincronizações',
    icon: 'i-lucide-refresh-cw',
    to: '/syncs'
  },
  {
    id: 'closing',
    label: 'Fechamento',
    icon: 'i-lucide-calendar-clock',
    to: '/closing'
  }
]

validateNavCatalog(OPERATIONS_NAV_ITEMS)

export function docsNavLeaves(): NavLeafDestination[] {
  return flattenNavLeaves(DOCS_NAV_ITEMS)
}

export function operationsNavLeaves(): NavLeafDestination[] {
  return flattenNavLeaves(OPERATIONS_NAV_ITEMS)
}

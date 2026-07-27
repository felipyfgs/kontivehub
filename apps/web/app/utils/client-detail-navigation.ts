/**
 * Navegação do detalhe do cliente — abas do layout master (main + sidebar).
 */
import type { NavigationMenuItem } from '@nuxt/ui'
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import { flattenNavLeaves, validateNavCatalog } from '~/utils/navigation-hierarchy'
import {
  CLIENT_DETAIL_TABS,
  clientDetailHref,
  clientToolbarTabForPath
} from '~/utils/client-detail-tabs'

export function clientDetailNav(clientId: string | number): NavLayerItem[] {
  const items: NavLayerItem[] = CLIENT_DETAIL_TABS.map((tab): NavLeafDestination => ({
    id: `client-${tab.value}`,
    label: tab.label,
    icon: tab.icon,
    to: clientDetailHref(clientId, tab.value),
    exact: true,
    isActive: (path: string) => clientToolbarTabForPath(path) === tab.value
  }))
  validateNavCatalog(items, 6)
  return items
}

export function clientDetailLeaves(clientId: string | number): NavLeafDestination[] {
  return flattenNavLeaves(clientDetailNav(clientId))
}

export function clientNavigationMenu(
  clientId: string | number,
  currentPath?: string
): NavigationMenuItem[][] {
  const path = currentPath || ''
  const activeTab = path ? clientToolbarTabForPath(path) : null
  const items = CLIENT_DETAIL_TABS.map((tab): NavigationMenuItem => ({
    label: tab.label,
    icon: tab.icon,
    to: tab.disabled ? undefined : clientDetailHref(clientId, tab.value),
    exact: true,
    active: activeTab === tab.value,
    disabled: tab.disabled,
    badge: tab.badge
      ? { label: tab.badge, color: 'success' as const, variant: 'subtle' as const }
      : undefined
  }))
  return [items]
}

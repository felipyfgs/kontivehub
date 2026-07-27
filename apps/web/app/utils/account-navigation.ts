import type { NavigationMenuItem } from '@nuxt/ui'
import type { MeUser } from '~/types/api'
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import { filterNavItems, flattenNavLeaves, validateNavCatalog } from '~/utils/navigation-hierarchy'
import {
  canAccessTenantSettings,
  canAccessPlatformAdmin,
  canManageTenantTeam,
  canManageWorkCatalog
} from '~/utils/permissions'

export interface AccountNavigationItem {
  id: string
  label: string
  icon: string
  to: string
  exact?: boolean
}

/**
 * Vocabulário canônico da Conta — uma seção = um path (como o template settings).
 * Perfil pertence ao User; demais seções pertencem ao Tenant.
 */
export const ACCOUNT_NAVIGATION = {
  profile: {
    id: 'account-profile',
    label: 'Perfil',
    icon: 'i-lucide-user-round',
    to: '/conta',
    exact: true
  },
  tenant: {
    id: 'account-tenant',
    label: 'Escritório',
    icon: 'i-lucide-building-2',
    to: '/conta/escritorio',
    exact: true
  },
  departments: {
    id: 'account-departments',
    label: 'Departamentos',
    icon: 'i-lucide-building',
    to: '/conta/departamentos'
  },
  team: {
    id: 'account-team',
    label: 'Equipe',
    icon: 'i-lucide-users-round',
    to: '/conta/equipe'
  },
  subscription: {
    id: 'account-subscription',
    label: 'Assinatura',
    icon: 'i-lucide-badge-check',
    to: '/conta/assinatura'
  },
  usage: {
    id: 'account-usage',
    label: 'Consumo',
    icon: 'i-lucide-chart-pie',
    to: '/conta/consumo'
  }
} satisfies Record<string, AccountNavigationItem>

function leaf(item: AccountNavigationItem, capability?: string): NavLeafDestination {
  return {
    id: item.id,
    label: item.label,
    icon: item.icon,
    to: item.to,
    exact: item.exact,
    capability
  }
}

/**
 * Catálogo plano (folhas) — toolbar UNavigationMenu + sidebar/busca.
 * Espelha `.local/reference/nuxt-dashboard-template/app/pages/settings.vue`.
 */
export const ACCOUNT_NAV_ITEMS: NavLayerItem[] = [
  leaf(ACCOUNT_NAVIGATION.profile, 'profile'),
  leaf(ACCOUNT_NAVIGATION.tenant, 'tenant-settings'),
  leaf(ACCOUNT_NAVIGATION.departments, 'work-catalog'),
  leaf(ACCOUNT_NAVIGATION.team, 'manage-team'),
  leaf(ACCOUNT_NAVIGATION.subscription, 'tenant-settings'),
  leaf(ACCOUNT_NAVIGATION.usage, 'tenant-settings')
]

/** Flat settings: até 8 folhas na toolbar (sem grupos/subtabs). */
validateNavCatalog(ACCOUNT_NAV_ITEMS, 8)

function allowAccountLeaf(user: MeUser | null | undefined, leafItem: NavLeafDestination): boolean {
  switch (leafItem.capability) {
    case 'profile':
      return true
    case 'tenant-settings':
      return canAccessTenantSettings(user)
    case 'manage-team':
      return canManageTenantTeam(user)
    case 'work-catalog':
      return canManageWorkCatalog(user)
    default:
      return true
  }
}

/** Catálogo filtrado por capacidade. */
export function accountNavigationTree(user?: MeUser | null): NavLayerItem[] {
  if (!user) return []
  const tenant = user.current_tenant
  if (canAccessPlatformAdmin(user) && !tenant) return []

  return filterNavItems(ACCOUNT_NAV_ITEMS, leafItem => allowAccountLeaf(user, leafItem))
}

/** Folhas planas para sidebar e busca. */
export function accountNavigationItems(user?: MeUser | null): AccountNavigationItem[] {
  return flattenNavLeaves(accountNavigationTree(user)).map(item => ({
    id: item.id,
    label: item.label,
    icon: item.icon || 'i-lucide-circle',
    to: item.to,
    exact: item.exact
  }))
}

/** Links para UNavigationMenu da toolbar (grupo único, como o template). */
export function accountNavigationMenu(user?: MeUser | null): NavigationMenuItem[][] {
  const items = accountNavigationItems(user).map((item): NavigationMenuItem => ({
    label: item.label,
    icon: item.icon,
    to: item.to,
    exact: item.exact === true
  }))
  return items.length ? [items] : []
}

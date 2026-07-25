import type { NavigationMenuItem } from '@nuxt/ui'
import type { MeUser } from '~/types/api'
import { accountNavigationItems, accountNavigationTree } from '~/utils/account-navigation'
import { lacksOfficeContext } from '~/utils/auth-redirect'
import { DOCS_NAV_ITEMS, OPERATIONS_NAV_ITEMS } from '~/utils/docs-operations-navigation'
import { fiscalNavigationItems } from '~/utils/fiscal-navigation'
import {
  flattenNavLeaves,
  isNavLeaf,
  pathMatchesLeaf,
  type NavLeafDestination
} from '~/utils/navigation-hierarchy'
import {
  COMMUNICATION_CONTACTS_PATH,
  COMMUNICATION_FLOWS_PATH,
  COMMUNICATION_INDEX_PATH,
  COMMUNICATION_QUICK_RESPONSES_PATH,
  isCommunicationContactsNavActive,
  isCommunicationFlowsNavActive,
  isCommunicationInboxNavActive,
  isCommunicationNavActive,
  isCommunicationQuickResponsesNavActive
} from '~/utils/communication-routes'
import {
  canAccessPlatformAdmin,
  canCreateExport,
  canManageClients,
  canViewCommunication,
  canViewWork
} from '~/utils/permissions'
import { workNavigationItems } from '~/utils/work-navigation'

export interface NavDestination {
  id: string
  label: string
  icon: string
  to?: string
  target?: string
  external?: boolean
  exact?: boolean
  /** Força estado ativo em destinos com detalhe dinâmico. */
  active?: boolean
  /** Item estrutural nativo do UNavigationMenu vertical. */
  type?: 'trigger' | 'label'
  defaultOpen?: boolean
  children?: NavDestination[]
}

export interface QuickAction {
  id: string
  label: string
  icon: string
  to?: string
}

function leafToDestination(leaf: NavLeafDestination, path: string): NavDestination {
  return {
    id: leaf.id,
    label: leaf.label,
    icon: leaf.icon || 'i-lucide-circle',
    to: leaf.to,
    exact: leaf.exact,
    active: path ? pathMatchesLeaf(path, leaf) : undefined
  }
}

/** Contextos fiscais globais sob um único acordeão Monitoramento. */
export function monitoringDestinations(
  user?: MeUser | null,
  path = ''
): NavDestination[] {
  const contexts = fiscalNavigationItems(user).filter(isNavLeaf)
  if (!contexts.length) return []
  const monitoringActive = path === '/monitoring' || path.startsWith('/monitoring/')

  return [{
    id: 'monitoring',
    label: 'Monitoramento',
    icon: 'i-lucide-radar',
    type: 'trigger',
    defaultOpen: monitoringActive,
    children: contexts.map((leaf) => {
      const destination = leafToDestination(leaf, path)
      if (leaf.to === '/monitoring' && path.startsWith('/monitoring/clients')) {
        destination.active = true
      }
      return destination
    })
  }]
}

function platformAdminDestinations(path = ''): NavDestination[] {
  return [{
    id: 'platform-admin',
    label: 'Admin',
    icon: 'i-lucide-shield',
    type: 'trigger',
    defaultOpen: path === '/admin' || path.startsWith('/admin/'),
    children: [
      {
        id: 'platform-offices',
        label: 'Escritórios',
        icon: 'i-lucide-building-2',
        to: '/admin/offices'
      },
      {
        id: 'platform-fiscal-modules',
        label: 'Módulos fiscais',
        icon: 'i-lucide-blocks',
        to: '/admin/fiscal-modules'
      },
      {
        id: 'platform-serpro',
        label: 'SERPRO',
        icon: 'i-lucide-gauge',
        to: '/admin/serpro',
        active: path === '/admin/serpro' || path.startsWith('/admin/serpro/')
      }
    ]
  }]
}

export function mainDestinations(
  user?: MeUser | null,
  options?: { path?: string }
): NavDestination[] {
  const path = options?.path || ''

  // PLATFORM_ADMIN sem Office: somente superfícies globais /admin.
  if (lacksOfficeContext(user) && canAccessPlatformAdmin(user)) {
    return platformAdminDestinations(path)
  }

  const clientsOpen = !path || path === '/clients' || path.startsWith('/clients/')
  const docsOpen = !path
    || path === '/docs'
    || path.startsWith('/docs/')
    || path.startsWith('/exports')
  const operationsOpen = !path
    || path.startsWith('/closing')
    || path.startsWith('/syncs')
    || path.startsWith('/health')
  const workOpen = !path || path === '/work' || path.startsWith('/work/')
  const accountOpen = path === '/conta' || path.startsWith('/conta/')

  const items: NavDestination[] = [
    {
      id: 'home',
      label: 'Início',
      icon: 'i-lucide-house',
      to: '/',
      exact: true
    }
  ]

  if (canViewWork(user)) {
    items.push({
      id: 'work',
      label: 'Trabalho',
      icon: 'i-lucide-list-todo',
      type: 'trigger',
      defaultOpen: workOpen,
      children: flattenNavLeaves(workNavigationItems(user)).map(leaf => leafToDestination(leaf, path))
    })
  }

  if (canViewCommunication(user)) {
    const communicationOpen = isCommunicationNavActive(path)
    items.push({
      id: 'communication',
      label: 'Atendimento',
      icon: 'i-lucide-messages-square',
      type: 'trigger',
      defaultOpen: communicationOpen,
      children: [
        {
          id: 'communication-inbox',
          label: 'Conversas',
          icon: 'i-lucide-messages-square',
          to: COMMUNICATION_INDEX_PATH,
          exact: true,
          active: isCommunicationInboxNavActive(path)
        },
        {
          id: 'communication-contacts',
          label: 'Contatos',
          icon: 'i-lucide-contact',
          to: COMMUNICATION_CONTACTS_PATH,
          active: isCommunicationContactsNavActive(path)
        },
        {
          id: 'communication-quick-responses',
          label: 'Respostas rápidas',
          icon: 'i-lucide-zap',
          to: COMMUNICATION_QUICK_RESPONSES_PATH,
          active: isCommunicationQuickResponsesNavActive(path)
        },
        {
          id: 'communication-flows',
          label: 'Fluxos',
          icon: 'i-lucide-git-branch',
          to: COMMUNICATION_FLOWS_PATH,
          active: isCommunicationFlowsNavActive(path)
        }
      ]
    })
  }

  items.push(
    {
      id: 'clients',
      label: 'Clientes',
      icon: 'i-lucide-users',
      type: 'trigger',
      defaultOpen: clientsOpen,
      children: [
        {
          id: 'clients-list',
          label: 'Lista',
          icon: 'i-lucide-list',
          to: '/clients',
          exact: true
        },
        {
          id: 'clients-dashboard',
          label: 'Dashboard',
          icon: 'i-lucide-layout-dashboard',
          to: '/clients/dashboard'
        }
      ]
    },
    ...monitoringDestinations(user, path),
    {
      id: 'docs',
      label: 'Documentos',
      icon: 'i-lucide-file-stack',
      type: 'trigger',
      defaultOpen: docsOpen,
      children: flattenNavLeaves(DOCS_NAV_ITEMS).map(leaf => leafToDestination(leaf, path))
    },
    {
      id: 'operations',
      label: 'Operações',
      icon: 'i-lucide-settings',
      type: 'trigger',
      defaultOpen: operationsOpen,
      children: flattenNavLeaves(OPERATIONS_NAV_ITEMS).map(leaf => leafToDestination(leaf, path))
    }
  )

  const accountTree = accountNavigationTree(user)
  if (accountTree.length) {
    // Sidebar: folhas canônicas (busca/atalhos indexam o mesmo flatten).
    const accountLeaves = accountNavigationItems(user)
    items.push({
      id: 'settings',
      label: 'Conta',
      icon: 'i-lucide-sliders-horizontal',
      type: 'trigger',
      defaultOpen: accountOpen,
      children: accountLeaves
    })
  }

  if (canAccessPlatformAdmin(user)) {
    items.push(...platformAdminDestinations(path))
  }

  return items
}

/** Links inferiores (mt-auto), como Feedback / Help do template. */
export function secondaryDestinations(): NavDestination[] {
  return [{
    id: 'docs-adn',
    label: 'Documentação ADN',
    icon: 'i-lucide-book-open',
    to: 'https://www.gov.br/nfse',
    target: '_blank',
    external: true
  }, {
    id: 'help',
    label: 'Ajuda e suporte',
    icon: 'i-lucide-info',
    to: 'https://www.gov.br/nfse',
    target: '_blank',
    external: true
  }]
}

/** Ações rápidas da command palette — somente as autorizadas. */
export function quickActions(user?: MeUser | null): QuickAction[] {
  if (lacksOfficeContext(user)) {
    return []
  }

  const actions: QuickAction[] = []

  if (canManageClients(user)) {
    actions.push({
      id: 'new-client',
      label: 'Novo cliente',
      icon: 'i-lucide-user-plus'
    })
  }

  if (canCreateExport(user)) {
    actions.push({
      id: 'new-export',
      label: 'Nova exportação',
      icon: 'i-lucide-download'
    })
  }

  if (canViewWork(user)) {
    actions.push({
      id: 'work-queue',
      label: 'Tarefas',
      icon: 'i-lucide-inbox',
      to: '/work/tasks'
    })
  }

  return actions
}

/** Achata árvore de destinos (command palette / testes). */
export function flattenDestinations(destinations: NavDestination[]): NavDestination[] {
  const out: NavDestination[] = []
  for (const item of destinations) {
    if (item.children?.length) {
      out.push(...flattenDestinations(item.children))
    } else {
      out.push(item)
    }
  }
  return out
}

/**
 * Destinos folha para busca global / atalhos.
 * Usa as mesmas folhas do sidebar para manter busca, permissões e navegação
 * visual sincronizadas.
 */
export function searchableDestinations(
  user?: MeUser | null,
  options?: { path?: string }
): NavDestination[] {
  const tree = mainDestinations(user, options)
  const out: NavDestination[] = []

  for (const item of tree) {
    if (item.children?.length) {
      out.push(...flattenDestinations(item.children))
    } else {
      out.push(item)
    }
  }

  return out
}

/** Conta e Admin permanecem no grupo de gestão; Clientes é operacional. */
const SIDEBAR_MANAGEMENT_IDS = new Set(['settings', 'platform-admin'])

/**
 * Separa navegação operacional e gestão usando os grupos nativos do
 * UNavigationMenu. Grupos vazios são removidos para não gerar divisores órfãos.
 */
export function sidebarDestinationGroups(
  destinations: NavDestination[]
): NavDestination[][] {
  const operational = destinations.filter(item => !SIDEBAR_MANAGEMENT_IDS.has(item.id))
  const management = destinations.filter(item => SIDEBAR_MANAGEMENT_IDS.has(item.id))
  return [operational, management].filter(group => group.length > 0)
}

export function toNavigationItems(
  destinations: NavDestination[],
  onSelect?: () => void,
  nested = false
): NavigationMenuItem[] {
  return destinations.map((item) => {
    const base: NavigationMenuItem = {
      label: item.label,
      // Como no shell oficial: ícones identificam destinos/grupos principais;
      // submenus usam somente o rótulo para manter a hierarquia limpa.
      ...(nested ? {} : { icon: item.icon }),
      to: item.to,
      target: item.target,
      exact: item.exact,
      ...(item.active !== undefined ? { active: item.active } : {}),
      onSelect: item.to && !item.external
        ? onSelect
        : item.external
          ? onSelect
          : undefined
    }

    if (item.type === 'label') {
      return {
        ...base,
        type: 'label' as const,
        to: undefined,
        onSelect: undefined
      }
    }

    if (item.type === 'trigger' && item.children?.length) {
      return {
        ...base,
        // value estável p/ Accordion (defaultOpen + type multiple no shell)
        value: item.id,
        type: 'trigger' as const,
        defaultOpen: item.defaultOpen,
        // grupo pai não navega
        to: undefined,
        children: toNavigationItems(item.children, onSelect, true)
      }
    }

    return base
  })
}

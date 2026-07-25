/**
 * Itens de navegação horizontal do Monitoramento (toolbar Settings-like).
 * Separado de components para testes unitários sem montar Vue.
 */
import type { NavigationMenuItem } from '@nuxt/ui'
import type { FiscalModuleKey } from '~/types/fiscal-modules'
import { FISCAL_MODULE_PATHS } from '~/types/fiscal-modules'

/**
 * `mei` é uma superfície de navegação própria, mas reutiliza o módulo API
 * `simples_mei` com o submódulo PGMEI.
 */
export type MonitoringModuleKey = FiscalModuleKey | 'mei' | 'registrations' | 'tax_processes'
export type RoutedMonitoringModuleKey = 'simples_mei' | 'dctfweb'

const MONITORING_EXTRA_PATHS: Record<Exclude<MonitoringModuleKey, FiscalModuleKey>, string> = {
  mei: '/monitoring/mei',
  registrations: '/monitoring/registrations',
  tax_processes: '/monitoring/tax-processes'
}

export interface MonitoringNavItem {
  id: string
  /** Rótulo canônico compartilhado por sidebar, busca e título da superfície. */
  label: string
  icon: string
  to: string
  moduleKey: MonitoringModuleKey
  exact?: boolean
  /** Prefixo compartilhado por rotas canônicas com submódulo. */
  pathPrefix?: string
}

export const MONITORING_NAV_ITEMS: readonly MonitoringNavItem[] = [
  {
    id: 'monitoring-dashboard',
    label: 'Dashboard',
    icon: 'i-lucide-gauge',
    to: '/monitoring',
    moduleKey: 'dashboard',
    exact: true
  },
  {
    id: 'monitoring-simples',
    label: 'Simples Nacional',
    icon: 'i-lucide-badge-percent',
    to: '/monitoring/simples',
    moduleKey: 'simples_mei',
    pathPrefix: '/monitoring/simples'
  },
  {
    id: 'monitoring-mei',
    label: 'MEI',
    icon: 'i-lucide-badge-check',
    to: '/monitoring/mei',
    moduleKey: 'mei'
  },
  {
    id: 'monitoring-dctfweb',
    label: 'DCTFWeb',
    icon: 'i-lucide-file-input',
    to: '/monitoring/dctfweb',
    moduleKey: 'dctfweb',
    pathPrefix: '/monitoring/dctfweb'
  },
  {
    id: 'monitoring-fgts',
    label: 'FGTS Digital',
    icon: 'i-lucide-landmark',
    to: '/monitoring/fgts',
    moduleKey: 'fgts'
  },
  {
    id: 'monitoring-installments',
    label: 'Parcelamentos',
    icon: 'i-lucide-calendar-range',
    to: '/monitoring/installments',
    moduleKey: 'installments'
  },
  {
    id: 'monitoring-sitfis',
    label: 'Situação Fiscal',
    icon: 'i-lucide-clipboard-check',
    to: '/monitoring/sitfis',
    moduleKey: 'sitfis'
  },
  {
    id: 'monitoring-mailbox',
    label: 'Caixas Postais',
    icon: 'i-lucide-mail',
    to: '/monitoring/mailbox',
    moduleKey: 'mailbox'
  },
  {
    id: 'monitoring-declarations',
    label: 'Declarações',
    icon: 'i-lucide-file-check-2',
    to: '/monitoring/declarations',
    moduleKey: 'declarations'
  },
  {
    id: 'monitoring-guides',
    label: 'Guias',
    icon: 'i-lucide-receipt',
    to: '/monitoring/guides',
    moduleKey: 'guides'
  },
  {
    id: 'monitoring-registrations',
    label: 'Cadastro e Vínculos',
    icon: 'i-lucide-link-2',
    to: '/monitoring/registrations',
    moduleKey: 'registrations'
  },
  {
    id: 'monitoring-tax-processes',
    label: 'Processos Fiscais',
    icon: 'i-lucide-scale',
    to: '/monitoring/tax-processes',
    moduleKey: 'tax_processes'
  }
] as const

const ROUTED_SUBMODULES = {
  simples_mei: {
    defaultValue: 'PGDASD',
    entries: [
      { value: 'PGDASD', slug: 'pgdasd' },
      { value: 'PGMEI', slug: 'pgmei' }
    ]
  },
  dctfweb: {
    defaultValue: 'DCTFWEB',
    entries: [
      { value: 'DCTFWEB', slug: 'dctfweb' },
      { value: 'MIT', slug: 'mit' }
    ]
  }
} as const satisfies Record<RoutedMonitoringModuleKey, {
  defaultValue: string
  entries: readonly { value: string, slug: string }[]
}>

function firstQueryValue(raw: unknown): unknown {
  return Array.isArray(raw) ? raw[0] : raw
}

export function monitoringNavItemForModule(moduleKey: MonitoringModuleKey): MonitoringNavItem {
  return MONITORING_NAV_ITEMS.find(item => item.moduleKey === moduleKey)!
}

export function normalizeMonitoringSubmodule(
  moduleKey: RoutedMonitoringModuleKey,
  raw: unknown
): string {
  const definition = ROUTED_SUBMODULES[moduleKey]
  const candidate = String(firstQueryValue(raw) || '').trim().toLowerCase().replaceAll('_', '-')
  return definition.entries.find(entry =>
    entry.slug === candidate || entry.value.toLowerCase().replaceAll('_', '-') === candidate
  )?.value ?? definition.defaultValue
}

/** Path do módulo (1:1 com item da sidebar). Tabs internas NÃO entram na URL. */
export function monitoringModuleBasePath(moduleKey: RoutedMonitoringModuleKey): string {
  return moduleKey === 'simples_mei' ? '/monitoring/simples' : '/monitoring/dctfweb'
}

/**
 * Location canônica: sempre só o path do módulo da sidebar.
 * Submódulo (PGDASD, MIT, …) é estado local da página — sem path e sem query.
 */
export function monitoringSubmoduleLocation(
  moduleKey: RoutedMonitoringModuleKey,
  _raw?: unknown
): { path: string, query: Record<string, never> } {
  return { path: monitoringModuleBasePath(moduleKey), query: {} }
}

/** Path do módulo (ignora raw — tabs não navegáveis por URL). */
export function monitoringSubmodulePath(
  moduleKey: RoutedMonitoringModuleKey,
  _raw?: unknown
): string {
  return monitoringModuleBasePath(moduleKey)
}

/** Filtros/tabs nunca vão na query da URL de monitoramento. */
export function monitoringCanonicalQuery(
  _query: Record<string, unknown> = {}
): Record<string, never> {
  return {}
}

/** Redirect legado `/modulo/:submodule` → superfície canônica correspondente. */
export function monitoringLegacySubmoduleLocation(
  moduleKey: RoutedMonitoringModuleKey,
  _query: Record<string, unknown> = {},
  pathSegment?: unknown
) {
  if (
    moduleKey === 'simples_mei'
    && normalizeMonitoringSubmodule('simples_mei', pathSegment) === 'PGMEI'
  ) {
    return { path: '/monitoring/mei', query: {} }
  }
  return monitoringSubmoduleLocation(moduleKey)
}

export function monitoringNavActiveModule(path: string): MonitoringModuleKey {
  const p = path.split('?')[0] || path
  if (p === '/monitoring' || p === '/monitoring/') return 'dashboard'
  // detalhe de cliente fiscal não é item de nav — cai no dashboard
  if (p.startsWith('/monitoring/clients')) return 'dashboard'
  // Path legado pré-desacoplamento MEI
  if (p === '/monitoring/simples-mei' || p.startsWith('/monitoring/simples-mei/')) {
    return 'simples_mei'
  }
  for (const item of MONITORING_NAV_ITEMS) {
    if (item.exact) {
      if (p === item.to) return item.moduleKey
      continue
    }
    const prefix = item.pathPrefix ?? item.to
    if (p === item.to || p === prefix || p.startsWith(`${prefix}/`)) {
      return item.moduleKey
    }
  }
  return 'dashboard'
}

/**
 * Itens do UNavigationMenu.
 * @param path rota atual (fallback se `activeOverride` omitido)
 * @param activeOverride módulo forçado (ex.: prop `active` das páginas)
 */
export function monitoringNavMenuItems(
  path = '',
  activeOverride?: MonitoringModuleKey | string | null
): NavigationMenuItem[] {
  const active = activeOverride
    ? String(activeOverride) as MonitoringModuleKey
    : monitoringNavActiveModule(path)
  return MONITORING_NAV_ITEMS.map(item => ({
    label: item.label,
    to: item.to,
    exact: item.exact === true,
    active: item.moduleKey === active
  }))
}

export function monitoringPathForModule(key: MonitoringModuleKey): string {
  if (key === 'mei' || key === 'registrations' || key === 'tax_processes') {
    return MONITORING_EXTRA_PATHS[key]
  }
  return FISCAL_MODULE_PATHS[key]
}

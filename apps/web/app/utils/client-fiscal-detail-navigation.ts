/**
 * Navegação do detalhe fiscal do cliente (`/monitoring/clients/:id/:section?`).
 * Labels/ordem canônicos = MONITORING_NAV_ITEMS (sidebar Monitoramento).
 * Seções internas ocultas; MEI (`ccmei`) só para cliente MEI.
 */
import type { NavigationMenuItem } from '@nuxt/ui'
import type { MonitoringModuleKey } from '~/utils/monitoring-nav'
import { MONITORING_NAV_ITEMS } from '~/utils/monitoring-nav'
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import { flattenNavLeaves, validateNavCatalog } from '~/utils/navigation-hierarchy'

export type ClientFiscalSectionKey
  = | 'overview'
    | 'runs'
    | 'findings'
    | 'pending'
    | 'installments'
    | 'declarations'
    | 'pgdasd'
    | 'dctfweb'
    | 'guides'
    | 'fgts'
    | 'sitfis'
    | 'mailbox'
    | 'registrations'
    | 'ccmei'
    | 'renunciations'
    | 'tax_processes'

export const CLIENT_FISCAL_SECTION_KEYS: ClientFiscalSectionKey[] = [
  'overview',
  'runs',
  'findings',
  'pending',
  'pgdasd',
  'ccmei',
  'dctfweb',
  'fgts',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'registrations',
  'renunciations',
  'tax_processes'
]

/** Ocultas no rail/overview (deep-link → overview). */
export const CLIENT_FISCAL_HIDDEN_SECTION_KEYS = [
  'runs',
  'findings',
  'pending',
  'renunciations'
] as const satisfies readonly ClientFiscalSectionKey[]

export type ClientFiscalNavOptions = {
  /** MEI (`ccmei`) só quando true. */
  isMei?: boolean
}

/** moduleKey do Monitoramento global → seção do detalhe (paths estáveis). */
const MODULE_TO_SECTION: Partial<Record<MonitoringModuleKey, ClientFiscalSectionKey>> = {
  dashboard: 'overview',
  simples_mei: 'pgdasd',
  mei: 'ccmei',
  dctfweb: 'dctfweb',
  fgts: 'fgts',
  installments: 'installments',
  sitfis: 'sitfis',
  mailbox: 'mailbox',
  declarations: 'declarations',
  guides: 'guides',
  registrations: 'registrations',
  tax_processes: 'tax_processes'
}

interface FiscalSectionDef {
  key: ClientFiscalSectionKey
  id: string
  label: string
  icon: string
}

function navItemForSection(key: ClientFiscalSectionKey) {
  const moduleKey = (Object.entries(MODULE_TO_SECTION) as [MonitoringModuleKey, ClientFiscalSectionKey][])
    .find(([, section]) => section === key)?.[0]
  return moduleKey
    ? MONITORING_NAV_ITEMS.find(item => item.moduleKey === moduleKey)
    : undefined
}

function sectionDefFromMonitoring(key: ClientFiscalSectionKey, fallbackId: string): FiscalSectionDef {
  const item = navItemForSection(key)
  return {
    key,
    id: fallbackId,
    label: item?.label ?? key,
    icon: item?.icon ?? 'i-lucide-circle'
  }
}

/**
 * Ordem canônica do rail = MONITORING_NAV_ITEMS.
 * Inclui ocultas no catálogo completo só para deep-link/redirect.
 */
const FISCAL_SECTIONS: FiscalSectionDef[] = [
  sectionDefFromMonitoring('overview', 'cf-overview'),
  { key: 'runs', id: 'cf-runs', label: 'Execuções', icon: 'i-lucide-play' },
  { key: 'findings', id: 'cf-findings', label: 'Achados', icon: 'i-lucide-search' },
  { key: 'pending', id: 'cf-pending', label: 'Pendências', icon: 'i-lucide-circle-alert' },
  sectionDefFromMonitoring('pgdasd', 'cf-pgdasd'),
  sectionDefFromMonitoring('ccmei', 'cf-ccmei'),
  sectionDefFromMonitoring('dctfweb', 'cf-dctfweb'),
  sectionDefFromMonitoring('fgts', 'cf-fgts'),
  sectionDefFromMonitoring('installments', 'cf-installments'),
  sectionDefFromMonitoring('sitfis', 'cf-sitfis'),
  sectionDefFromMonitoring('mailbox', 'cf-mailbox'),
  sectionDefFromMonitoring('declarations', 'cf-declarations'),
  sectionDefFromMonitoring('guides', 'cf-guides'),
  sectionDefFromMonitoring('registrations', 'cf-registrations'),
  { key: 'renunciations', id: 'cf-renunciations', label: 'Renúncias', icon: 'i-lucide-unlink' },
  sectionDefFromMonitoring('tax_processes', 'cf-tax-processes')
]

/** Ordem visível no rail (sem ocultas; MEI filtrado em runtime). */
const CANONICAL_VISIBLE_ORDER: ClientFiscalSectionKey[] = [
  'overview',
  'pgdasd',
  'ccmei',
  'dctfweb',
  'fgts',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'registrations',
  'tax_processes'
]

export function isClientFiscalSectionVisible(
  key: ClientFiscalSectionKey,
  options: ClientFiscalNavOptions = {}
): boolean {
  if ((CLIENT_FISCAL_HIDDEN_SECTION_KEYS as readonly string[]).includes(key)) {
    return false
  }
  if (key === 'ccmei' && options.isMei !== true) {
    return false
  }
  return (CANONICAL_VISIBLE_ORDER as readonly string[]).includes(key)
}

function visibleFiscalSections(options: ClientFiscalNavOptions = {}): FiscalSectionDef[] {
  const byKey = new Map(FISCAL_SECTIONS.map(def => [def.key, def]))
  return CANONICAL_VISIBLE_ORDER
    .filter(key => isClientFiscalSectionVisible(key, options))
    .map(key => byKey.get(key)!)
}

export function sectionPath(clientId: string | number, section: ClientFiscalSectionKey): string {
  const base = `/monitoring/clients/${clientId}`
  return section === 'overview' ? base : `${base}/${section}`
}

/**
 * Path ao trocar de empresa: preserva seção se visível no destino; senão overview.
 */
export function clientFiscalSwitchPath(
  targetClientId: string | number,
  currentSection: ClientFiscalSectionKey,
  options: ClientFiscalNavOptions = {}
): string {
  const section = isClientFiscalSectionVisible(currentSection, options)
    ? currentSection
    : 'overview'
  return sectionPath(targetClientId, section)
}

function sectionActive(section: ClientFiscalSectionKey, clientId: string | number) {
  const target = sectionPath(clientId, section)
  return (path: string) => {
    if (section === 'overview') {
      return path === target || path === `${target}/`
    }
    return path === target || path.startsWith(`${target}/`)
  }
}

function sectionLeaf(
  clientId: string | number,
  def: FiscalSectionDef
): NavLeafDestination {
  return {
    id: def.id,
    label: def.label,
    icon: def.icon,
    to: sectionPath(clientId, def.key),
    exact: def.key === 'overview',
    isActive: sectionActive(def.key, clientId)
  }
}

/** Catálogo plano (folhas) — sidebar interno / busca. */
export function clientFiscalDetailNav(
  clientId: string | number,
  options: ClientFiscalNavOptions = {}
): NavLayerItem[] {
  const sections = visibleFiscalSections(options)
  const items = sections.map(def => sectionLeaf(clientId, def))
  validateNavCatalog(items, sections.length)
  return items
}

export function clientFiscalDetailLeaves(
  clientId: string | number,
  options: ClientFiscalNavOptions = {}
): NavLeafDestination[] {
  return flattenNavLeaves(clientFiscalDetailNav(clientId, options))
}

/** Labels canônicos visíveis (contrato anti-drift com Monitoramento). */
export function clientFiscalCanonicalLabels(options: ClientFiscalNavOptions = {}): string[] {
  return visibleFiscalSections(options).map(def => def.label)
}

/** Links para UNavigationMenu vertical do sidebar interno (grupo único). */
export function clientFiscalNavigationMenu(
  clientId: string | number,
  currentPath?: string,
  options: ClientFiscalNavOptions = {}
): NavigationMenuItem[][] {
  const path = currentPath || ''
  const items = visibleFiscalSections(options).map((def): NavigationMenuItem => {
    const leaf = sectionLeaf(clientId, def)
    return {
      label: leaf.label,
      icon: leaf.icon,
      to: leaf.to,
      exact: leaf.exact === true,
      active: path ? sectionActive(def.key, clientId)(path) : undefined
    }
  })
  return [items]
}

export function sectionKeyFromFiscalPath(path: string): ClientFiscalSectionKey {
  const bare = path.replace(/\/$/, '')
  const match = bare.match(/\/monitoring\/clients\/[^/]+(?:\/([^/]+))?$/)
  const raw = match?.[1] || 'overview'
  return (CLIENT_FISCAL_SECTION_KEYS as string[]).includes(raw)
    ? raw as ClientFiscalSectionKey
    : 'overview'
}

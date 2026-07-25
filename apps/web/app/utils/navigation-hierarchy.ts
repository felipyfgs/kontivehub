/**
 * Utilitários genéricos da hierarquia Tabs → Subtabs.
 * Independentes de catálogos de domínio e de tenancy (`office_id`).
 */

export type NavMatchMode = 'exact' | 'prefix'

export type NavResponsiveVariant = 'tabs' | 'select'

export interface NavLeafDestination {
  id: string
  label: string
  icon?: string
  to: string
  /** Match exact do path (default: false → prefix). */
  exact?: boolean
  /**
   * Override de ativo quando prefix/exact não bastam
   * (detalhe dinâmico, query `section`, exclusões de irmãos, etc.).
   * `path` é normalizado sem query; `location` preserva a URL completa.
   */
  isActive?: (path: string, location?: string) => boolean
  /** Capacidade opaca — filtragem via predicate externo. */
  capability?: string
}

export interface NavTabGroup {
  id: string
  label: string
  icon?: string
  /** Destinos folha do grupo (subtabs). Máx. 5. */
  children: NavLeafDestination[]
  capability?: string
}

/** Item de primeira camada: folha direta ou grupo com subtabs. */
export type NavLayerItem = NavLeafDestination | NavTabGroup

export interface NavAreaCatalog {
  id: string
  label: string
  icon?: string
  items: NavLayerItem[]
}

export function isNavTabGroup(item: NavLayerItem): item is NavTabGroup {
  return Array.isArray((item as NavTabGroup).children)
}

export function isNavLeaf(item: NavLayerItem): item is NavLeafDestination {
  return typeof (item as NavLeafDestination).to === 'string' && !isNavTabGroup(item)
}

/** Normaliza path para comparação (sem query/hash; sem barra final exceto `/`). */
export function normalizeNavPath(path: string): string {
  const bare = (path.split('?')[0] || path).split('#')[0] || path
  if (bare.length > 1 && bare.endsWith('/')) return bare.slice(0, -1)
  return bare || '/'
}

export function pathMatchesLeaf(location: string, leaf: NavLeafDestination): boolean {
  const p = normalizeNavPath(location)
  if (leaf.isActive) return leaf.isActive(p, location)

  if (leaf.to.includes('?')) {
    const [leafPath = '', leafQuery = ''] = leaf.to.split('?')
    const [locPath = '', locQuery = ''] = location.split('?')
    if (normalizeNavPath(locPath) !== normalizeNavPath(leafPath)) return false
    const wanted = new URLSearchParams(leafQuery)
    const actual = new URLSearchParams(locQuery)
    for (const [key, value] of wanted.entries()) {
      const current = actual.get(key)
      if (key === 'section' && value === 'resumo') {
        if (current && current !== 'resumo') return false
        continue
      }
      if (current !== value) return false
    }
    return true
  }

  const target = normalizeNavPath(leaf.to)
  if (leaf.exact) return p === target
  return p === target || p.startsWith(`${target}/`)
}

/** Achata folhas na ordem do catálogo (grupos expandem filhos). Não muta entrada. */
export function flattenNavLeaves(items: readonly NavLayerItem[]): NavLeafDestination[] {
  const out: NavLeafDestination[] = []
  for (const item of items) {
    if (isNavTabGroup(item)) {
      out.push(...item.children.map(child => ({ ...child })))
    } else {
      out.push({ ...item })
    }
  }
  return out
}

/**
 * Filtra itens e filhos por predicate (ex.: capacidade).
 * Remove grupos que ficarem sem filhos. Não muta o catálogo de entrada.
 */
export function filterNavItems(
  items: readonly NavLayerItem[],
  allow: (leaf: NavLeafDestination, group?: NavTabGroup) => boolean
): NavLayerItem[] {
  const out: NavLayerItem[] = []
  for (const item of items) {
    if (isNavTabGroup(item)) {
      const children = item.children.filter(child => allow(child, item))
      if (!children.length) continue
      out.push({
        ...item,
        children: children.map(child => ({ ...child }))
      })
      continue
    }
    if (allow(item)) {
      out.push({ ...item })
    }
  }
  return out
}

export interface ResolvedNavSelection {
  group: NavTabGroup | null
  leaf: NavLeafDestination | null
  /** Itens da primeira camada (grupos ou folhas). */
  tabs: NavLayerItem[]
  /** Subtabs do grupo ativo; vazio se folha direta ou grupo unitário oculto. */
  subtabs: NavLeafDestination[]
  /** Se true, não renderizar camada de subtabs (grupo com 1 destino). */
  hideSubtabs: boolean
}

/**
 * Resolve grupo/folha ativos para um path.
 * Empate: primeira folha que casar na ordem estável do catálogo.
 */
export function resolveNavSelection(
  items: readonly NavLayerItem[],
  path: string
): ResolvedNavSelection {
  const tabs = items.map(item =>
    isNavTabGroup(item)
      ? { ...item, children: item.children.map(c => ({ ...c })) }
      : { ...item }
  )

  let activeGroup: NavTabGroup | null = null
  let activeLeaf: NavLeafDestination | null = null

  for (const item of tabs) {
    if (isNavLeaf(item)) {
      if (pathMatchesLeaf(path, item)) {
        activeLeaf = item
        activeGroup = null
        break
      }
      continue
    }
    for (const child of item.children) {
      if (pathMatchesLeaf(path, child)) {
        activeGroup = item
        activeLeaf = child
        break
      }
    }
    if (activeLeaf) break
  }

  const subtabs = activeGroup?.children ?? []
  const hideSubtabs = Boolean(activeGroup && activeGroup.children.length <= 1)

  return {
    group: activeGroup,
    leaf: activeLeaf,
    tabs,
    subtabs: hideSubtabs ? [] : subtabs.map(c => ({ ...c })),
    hideSubtabs
  }
}

/** Destino de navegação do grupo (primeira folha ou folha ativa). */
export function groupEntryTo(group: NavTabGroup, activeLeafId?: string | null): string {
  if (activeLeafId) {
    const hit = group.children.find(c => c.id === activeLeafId)
    if (hit) return hit.to
  }
  return group.children[0]?.to ?? '#'
}

export const NAV_LAYER_MAX_ITEMS = 5

/** Valida limite de itens por camada (tabs ou subtabs de um grupo). */
export function assertNavLayerLimit(
  items: readonly unknown[],
  label = 'camada',
  maxItems = NAV_LAYER_MAX_ITEMS
): void {
  if (items.length > maxItems) {
    throw new Error(
      `Navegação: ${label} excede ${maxItems} itens (recebido ${items.length}).`
    )
  }
}

/**
 * Valida catálogo Tabs→Subtabs.
 * `maxItems` default = 5 (SectionNavigation). Catálogos flat (settings/UNavigationMenu)
 * podem passar um teto maior.
 */
export function validateNavCatalog(
  items: readonly NavLayerItem[],
  maxItems = NAV_LAYER_MAX_ITEMS
): void {
  assertNavLayerLimit(items, 'tabs', maxItems)
  for (const item of items) {
    if (isNavTabGroup(item)) {
      assertNavLayerLimit(item.children, `subtabs:${item.id}`, maxItems)
    }
  }
}

/**
 * Variante responsiva: em viewport estreita usa seletor quando há 2+ itens.
 * (A decisão final de “não cabe” fica no componente; aqui só a regra tipada.)
 */
export function resolveResponsiveVariant(
  itemCount: number,
  isCompactViewport: boolean
): NavResponsiveVariant {
  if (isCompactViewport && itemCount >= 2) return 'select'
  return 'tabs'
}

/** Opções de seletor mobile a partir de folhas já autorizadas. */
export function navLeavesToSelectItems(leaves: readonly NavLeafDestination[]) {
  return leaves.map(leaf => ({
    label: leaf.label,
    value: leaf.id,
    to: leaf.to,
    icon: leaf.icon
  }))
}

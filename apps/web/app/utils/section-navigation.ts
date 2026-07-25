/**
 * Mappers puros Tabs → Subtabs para SectionNavigation.
 * Sem dropdown: grupos da 1ª faixa são links; folhas do grupo ativo vão à 2ª faixa.
 */
import type {
  NavLayerItem,
  NavLeafDestination,
  ResolvedNavSelection
} from '~/utils/navigation-hierarchy'
import {
  groupEntryTo,
  isNavLeaf,
  pathMatchesLeaf
} from '~/utils/navigation-hierarchy'

export interface SectionNavLinkItem {
  id: string
  label: string
  icon?: string
  active: boolean
  exact?: boolean
  to: string
  /** Folha de destino (grupo → entry leaf). */
  leaf: NavLeafDestination
}

export interface SectionNavSelectOption {
  id: string
  label: string
  icon?: string
  leaf: NavLeafDestination
}

function leafIsActive(
  selection: ResolvedNavSelection,
  path: string,
  leaf: NavLeafDestination
): boolean {
  if (selection.leaf?.id === leaf.id) return true
  if (selection.leaf) return false
  return pathMatchesLeaf(path, leaf)
}

/** Faixa 1: folhas diretas e grupos (link, sem children/dropdown). */
export function toDesktopTabItems(
  selection: ResolvedNavSelection,
  path: string
): SectionNavLinkItem[] {
  return selection.tabs.map((item) => {
    if (isNavLeaf(item)) {
      return {
        id: item.id,
        label: item.label,
        icon: item.icon,
        active: leafIsActive(selection, path, item),
        exact: item.exact === true,
        to: item.to,
        leaf: item
      }
    }

    const entryTo = groupEntryTo(
      item,
      selection.group?.id === item.id ? selection.leaf?.id : null
    )
    const entryLeaf = item.children.find(child => child.to === entryTo) ?? item.children[0]!

    return {
      id: item.id,
      label: item.label,
      icon: item.icon || entryLeaf.icon,
      active: selection.group?.id === item.id,
      to: entryTo,
      leaf: entryLeaf
    }
  })
}

/** Faixa 2: folhas do grupo ativo (vazio quando hideSubtabs / grupo unitário). */
export function toDesktopSubtabItems(
  selection: ResolvedNavSelection,
  path: string
): SectionNavLinkItem[] {
  return selection.subtabs.map(leaf => ({
    id: leaf.id,
    label: leaf.label,
    icon: leaf.icon,
    active: leafIsActive(selection, path, leaf),
    exact: leaf.exact === true,
    to: leaf.to,
    leaf
  }))
}

/** Opções do select mobile: todas as folhas autorizadas. */
export function toMobileSelectOptions(
  items: readonly NavLayerItem[]
): SectionNavSelectOption[] {
  const out: SectionNavSelectOption[] = []

  for (const item of items) {
    if (isNavLeaf(item)) {
      out.push({ id: item.id, label: item.label, icon: item.icon, leaf: item })
      continue
    }

    for (const leaf of item.children) {
      out.push({
        id: leaf.id,
        label: item.children.length === 1
          ? item.label
          : `${item.label} · ${leaf.label}`,
        icon: leaf.icon || item.icon,
        leaf
      })
    }
  }

  return out
}

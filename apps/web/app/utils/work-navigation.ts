/**
 * Taxonomia canônica da área Trabalho.
 */
import type { MeUser } from '~/types/api'
import type { NavLayerItem } from '~/utils/navigation-hierarchy'
import { filterNavItems, validateNavCatalog } from '~/utils/navigation-hierarchy'
import { canCreateWorkProcesses, canManageWorkCatalog, canViewWork } from '~/utils/permissions'

export const WORK_NAV_ITEMS: NavLayerItem[] = [
  {
    id: 'work-overview',
    label: 'Visão geral',
    icon: 'i-lucide-layout-dashboard',
    to: '/work',
    exact: true,
    capability: 'view-work'
  },
  {
    id: 'work-queue',
    label: 'Tarefas',
    icon: 'i-lucide-inbox',
    to: '/work/tasks',
    capability: 'view-work'
  },
  {
    id: 'work-processes',
    label: 'Processos',
    icon: 'i-lucide-folder-kanban',
    to: '/work/processes',
    capability: 'view-work'
  },
  {
    id: 'work-calendar',
    label: 'Calendário',
    icon: 'i-lucide-calendar-days',
    to: '/work/calendar',
    capability: 'view-work'
  },
  {
    id: 'work-templates',
    label: 'Rotinas',
    icon: 'i-lucide-layout-template',
    to: '/work/templates',
    capability: 'manage-or-create-work-routines'
  }
]

validateNavCatalog(WORK_NAV_ITEMS)

/** Contexto do detalhe de processo (substitui tabs da área). */
export function workProcessContextNav(processId: string | number): NavLayerItem[] {
  const base = `/work/processes/${processId}`
  const querySectionActive = (expected: string) => (path: string, location?: string) => {
    if (path !== base) return false
    const params = new URLSearchParams((location?.split('?')[1] || '').split('#')[0] || '')
    return (params.get('section') || 'resumo') === expected
  }
  return [
    {
      id: 'process-resumo',
      label: 'Resumo',
      icon: 'i-lucide-layout-dashboard',
      to: `${base}?section=resumo`,
      isActive: querySectionActive('resumo')
    },
    {
      id: 'process-tarefas',
      label: 'Tarefas',
      icon: 'i-lucide-list-checks',
      to: `${base}?section=tarefas`,
      isActive: querySectionActive('tarefas')
    },
    {
      id: 'process-comentarios',
      label: 'Comentários',
      icon: 'i-lucide-message-square',
      to: `${base}?section=comentarios`,
      isActive: querySectionActive('comentarios')
    },
    {
      id: 'process-historico',
      label: 'Histórico',
      icon: 'i-lucide-history',
      to: `${base}?section=historico`,
      isActive: querySectionActive('historico')
    }
  ]
}

export function workNavigationItems(user?: MeUser | null): NavLayerItem[] {
  if (!canViewWork(user)) return []
  return filterNavItems(WORK_NAV_ITEMS, (leaf) => {
    if (leaf.capability === 'manage-or-create-work-routines') {
      return canManageWorkCatalog(user) || canCreateWorkProcesses(user)
    }
    return true
  })
}

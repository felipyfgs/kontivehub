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

export type WorkProcessSection = 'summary' | 'tasks' | 'comments' | 'history'

export function workProcessSectionPath(
  processId: string | number,
  section: WorkProcessSection = 'summary'
): string {
  const base = `/work/processes/${processId}`
  return section === 'summary' ? base : `${base}/${section}`
}

/** Contexto do detalhe de processo (substitui tabs da área). */
export function workProcessContextNav(processId: string | number): NavLayerItem[] {
  const base = workProcessSectionPath(processId)
  const pathSectionActive = (expected: string) => (path: string) =>
    path === (expected === 'resumo' ? base : `${base}/${expected}`)
  return [
    {
      id: 'process-resumo',
      label: 'Resumo',
      icon: 'i-lucide-layout-dashboard',
      to: base,
      isActive: pathSectionActive('resumo')
    },
    {
      id: 'process-tarefas',
      label: 'Tarefas',
      icon: 'i-lucide-list-checks',
      to: workProcessSectionPath(processId, 'tasks'),
      isActive: pathSectionActive('tasks')
    },
    {
      id: 'process-comentarios',
      label: 'Comentários',
      icon: 'i-lucide-message-square',
      to: workProcessSectionPath(processId, 'comments'),
      isActive: pathSectionActive('comments')
    },
    {
      id: 'process-historico',
      label: 'Histórico',
      icon: 'i-lucide-history',
      to: workProcessSectionPath(processId, 'history'),
      isActive: pathSectionActive('history')
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

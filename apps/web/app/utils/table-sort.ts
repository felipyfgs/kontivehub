import UButton from '@nuxt/ui/components/Button.vue'
import { h } from 'vue'

/**
 * Header ordenável canônico para colunas de UTable (TanStack).
 *
 * Fonte: apps/web/app/pages/clients/index.vue (produto) +
 * `.local/reference/nuxt-dashboard-template/app/pages/customers.vue` (template).
 *
 * Uso:
 *   import { sortHeader } from '~/utils/table-sort'
 *   { header: ({ column }) => sortHeader('Razão social', column) }
 */
export type SortableColumn = {
  getIsSorted: () => false | 'asc' | 'desc'
  toggleSorting: (desc?: boolean) => void
}

export function sortHeaderAriaLabel(label: string, isSorted: ReturnType<SortableColumn['getIsSorted']>) {
  if (isSorted === 'asc') {
    return `${label}: coluna ordenável, ordenada em ordem crescente. Ative para ordenar em ordem decrescente.`
  }

  if (isSorted === 'desc') {
    return `${label}: coluna ordenável, ordenada em ordem decrescente. Ative para ordenar em ordem crescente.`
  }

  return `${label}: coluna ordenável, sem ordenação. Ative para ordenar em ordem crescente.`
}

export function sortHeader(label: string, column: SortableColumn) {
  const isSorted = column.getIsSorted()
  const accessibleName = {
    'aria-label': sortHeaderAriaLabel(label, isSorted)
  }

  return h(UButton, {
    color: 'neutral',
    variant: 'ghost',
    label,
    icon: isSorted
      ? (isSorted === 'asc' ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-wide-narrow')
      : 'i-lucide-arrow-up-down',
    class: '-mx-2.5',
    ...accessibleName,
    onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
  })
}

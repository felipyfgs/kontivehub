/**
 * Menu de ações da seleção PGDAS-D.
 * Só consulta em lote (com confirmação no caller) + limpar seleção.
 * Associar/excluir NÃO entram aqui — membership usa modal dedicado / linha.
 */
import type { DropdownMenuItem } from '@nuxt/ui'

export type PgdasdActionHandlers = {
  /** Consulta PGDAS-D em lote (abre confirmação; não enfileira no onSelect). */
  onConsult?: () => void
}

/**
 * Menu curto: Solicitar consulta + Limpar seleção.
 */
export function buildPgdasdSelectionMenu(args: {
  clientIds: number[]
  handlers: PgdasdActionHandlers
  onClear: () => void
  busy?: boolean
}): DropdownMenuItem[][] {
  const { clientIds, handlers, onClear, busy = false } = args
  const count = clientIds.length
  if (count < 1) return []

  const actions: DropdownMenuItem[] = []

  if (handlers.onConsult) {
    actions.push({
      label: 'Solicitar consulta',
      icon: 'i-lucide-cloud-download',
      disabled: busy,
      onSelect: () => handlers.onConsult?.()
    })
  }

  return [
    actions,
    [{
      label: 'Limpar seleção',
      icon: 'i-lucide-x',
      disabled: busy,
      onSelect: onClear
    }]
  ].filter(group => group.length > 0)
}

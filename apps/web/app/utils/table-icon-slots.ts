/**
 * Slots de ícone em células densas — largura fixa + grupo visual.
 * Ícones inativos/indisponíveis ocupam o mesmo espaço (alinhamento entre linhas).
 *
 * Imports diretos de `@nuxt/ui/components/*` — não usar `#components` aqui.
 * O barrel virtual invalida no HMR e pode deixar UDropdownMenu em TDZ
 * (ReferenceError) ao re-renderizar células da UTable.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import UButton from '@nuxt/ui/components/Button.vue'
import UDropdownMenu from '@nuxt/ui/components/DropdownMenu.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import { h, type VNode } from 'vue'

export const TABLE_ICON_SLOT_CLASS = 'inline-flex size-8 shrink-0 items-center justify-center'

export const TABLE_ICON_GROUP_CLASS
  = 'inline-flex items-center rounded-md ring-1 ring-inset ring-default divide-x divide-default'

export const TABLE_ICON_GROUP_FILL_CLASS
  = 'flex w-full min-w-0 items-center rounded-md ring-1 ring-inset ring-default divide-x divide-default'

const TABLE_ICON_SLOT_FILL_CLASS = 'flex h-8 min-w-0 flex-1 items-center justify-center'

type IconColor = 'neutral' | 'primary' | 'success' | 'warning' | 'error' | 'info'
type IconVariant = 'ghost' | 'subtle' | 'outline' | 'soft' | 'solid'

export function tableIconSlot(child: VNode | VNode[] | null, fill = false) {
  return h(
    'div',
    { class: fill ? TABLE_ICON_SLOT_FILL_CLASS : TABLE_ICON_SLOT_CLASS },
    child ? (Array.isArray(child) ? child : [child]) : []
  )
}

export function tableIconGroup(
  slots: Array<VNode | null>,
  testId?: string,
  options: { fill?: boolean } = {}
) {
  const fill = options.fill === true
  return h('div', {
    class: fill ? TABLE_ICON_GROUP_FILL_CLASS : TABLE_ICON_GROUP_CLASS,
    ...(testId ? { 'data-testid': testId } : {})
  }, slots.map(slot => tableIconSlot(slot, fill)))
}

export function tableIconButton(args: {
  label: string
  icon: string
  testId: string
  color?: IconColor
  variant?: IconVariant
  disabled?: boolean
  onClick?: () => void
  href?: string | null
  target?: string
  rel?: string
  fill?: boolean
}) {
  const disabled = args.disabled === true || (!args.onClick && !args.href)
  // UButton icon + square — padrão Nuxt UI Dashboard (ghost, ícone centrado).
  return h(UTooltip, { text: args.label }, {
    default: () => h(UButton, {
      'size': 'sm',
      'color': args.color || 'neutral',
      'variant': args.variant || 'ghost',
      'icon': args.icon,
      'square': true,
      'class': args.fill ? 'h-8 w-full justify-center' : 'size-8 justify-center',
      'aria-label': args.label,
      'disabled': disabled,
      'data-testid': args.testId,
      'href': disabled ? undefined : (args.href || undefined),
      'target': args.href && !disabled ? (args.target || '_blank') : undefined,
      'rel': args.href && !disabled ? (args.rel || 'noopener') : undefined,
      'onClick': disabled || args.href ? undefined : args.onClick
    })
  })
}

export function tableIconMenu(args: {
  label: string
  icon: string
  testId: string
  items: DropdownMenuItem[][]
  color?: IconColor
  variant?: IconVariant
  disabled?: boolean
  align?: 'start' | 'end'
  fill?: boolean
}) {
  // Docs Nuxt UI: DropdownMenu default slot = Button (ghost + icon).
  const trigger = () => h(UButton, {
    'size': 'sm',
    'color': args.color || 'neutral',
    'variant': args.variant || 'ghost',
    'icon': args.icon,
    'square': true,
    'class': args.fill ? 'h-8 w-full justify-center' : 'size-8 justify-center',
    'aria-label': args.label,
    'disabled': args.disabled === true,
    'data-testid': args.testId
  })

  if (args.disabled) {
    return h(UTooltip, { text: args.label }, { default: trigger })
  }

  return h(UDropdownMenu, {
    items: args.items,
    content: { align: args.align || 'start' }
  }, { default: trigger })
}

import type { ClientCategoryColor } from '~/types/api'

export type ClientCategoryColorItem = {
  value: ClientCategoryColor
  label: string
  /** Hex canônico para swatch/badge (independente do tema semântico). */
  hex: string
}

/**
 * Paleta curada para tags/categorias — padrão de produtos como GitHub Labels
 * e Linear: ~18 matizes distinguíveis, com nomes e hex estáveis.
 * Os 7 primeiros preservam o contrato legado (primary…neutral).
 */
export const CLIENT_CATEGORY_COLOR_PALETTE: readonly ClientCategoryColorItem[] = [
  { value: 'primary', label: 'Laranja', hex: '#FF7A00' },
  { value: 'secondary', label: 'Violeta', hex: '#8B5CF6' },
  { value: 'success', label: 'Verde', hex: '#22C55E' },
  { value: 'info', label: 'Azul', hex: '#3B82F6' },
  { value: 'warning', label: 'Âmbar', hex: '#F59E0B' },
  { value: 'error', label: 'Vermelho', hex: '#EF4444' },
  { value: 'neutral', label: 'Cinza', hex: '#71717A' },
  { value: 'rose', label: 'Rosa', hex: '#F43F5E' },
  { value: 'pink', label: 'Pink', hex: '#EC4899' },
  { value: 'fuchsia', label: 'Fúcsia', hex: '#D946EF' },
  { value: 'purple', label: 'Roxo', hex: '#A855F7' },
  { value: 'indigo', label: 'Índigo', hex: '#6366F1' },
  { value: 'sky', label: 'Céu', hex: '#0EA5E9' },
  { value: 'cyan', label: 'Ciano', hex: '#06B6D4' },
  { value: 'teal', label: 'Teal', hex: '#14B8A6' },
  { value: 'emerald', label: 'Esmeralda', hex: '#10B981' },
  { value: 'lime', label: 'Lima', hex: '#84CC16' },
  { value: 'yellow', label: 'Amarelo', hex: '#EAB308' }
] as const

const paletteByValue = new Map(
  CLIENT_CATEGORY_COLOR_PALETTE.map(item => [item.value, item])
)

export function clientCategoryColorItem(
  color: string | null | undefined
): ClientCategoryColorItem {
  if (color && paletteByValue.has(color as ClientCategoryColor)) {
    return paletteByValue.get(color as ClientCategoryColor)!
  }
  return paletteByValue.get('neutral')!
}

export function clientCategorySoftStyle(hex: string): Record<string, string> {
  return {
    backgroundColor: `color-mix(in oklab, ${hex} 16%, transparent)`,
    color: hex,
    boxShadow: `inset 0 0 0 1px color-mix(in oklab, ${hex} 28%, transparent)`
  }
}

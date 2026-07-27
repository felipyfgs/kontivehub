import type { ClientTaxRegimeCode } from '~/types/api'

export const CLIENT_TAX_REGIME_ITEMS: Array<{ label: string, value: ClientTaxRegimeCode }> = [
  { label: 'Simples Nacional', value: 'SIMPLES_NACIONAL' },
  { label: 'MEI', value: 'MEI' },
  { label: 'Lucro Presumido', value: 'LUCRO_PRESUMIDO' },
  { label: 'Lucro Real', value: 'LUCRO_REAL' },
  { label: 'Imune / Isento', value: 'IMUNE_ISENTO' },
  { label: 'Outro', value: 'OUTRO' },
  { label: 'Não informado', value: 'UNKNOWN' }
]

export const CLIENT_TAX_REGIME_FILTER_ITEMS = CLIENT_TAX_REGIME_ITEMS

export function clientTaxRegimeLabel(value?: string | null): string | null {
  if (!value) return null
  return CLIENT_TAX_REGIME_ITEMS.find(item => item.value === value)?.label ?? value
}

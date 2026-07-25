import type { ClientTaxRegimeCode } from '~/types/api'
import type {
  GenerationItem,
  GenerationSelection,
  ProcessAudienceRules,
  WorkMonitoringModuleKey
} from '~/types/work'

export const WORK_TAX_REGIMES: Array<{ label: string, value: ClientTaxRegimeCode }> = [
  { label: 'Simples Nacional', value: 'SIMPLES_NACIONAL' },
  { label: 'MEI / SIMEI', value: 'MEI' },
  { label: 'Lucro Presumido', value: 'LUCRO_PRESUMIDO' },
  { label: 'Lucro Real', value: 'LUCRO_REAL' },
  { label: 'Imune / Isento', value: 'IMUNE_ISENTO' },
  { label: 'Outro regime', value: 'OUTRO' }
]

export const WORK_MONITORING_MODULES: Array<{
  label: string
  value: WorkMonitoringModuleKey | null
}> = [
  { label: 'Sem contexto de monitoramento', value: null },
  { label: 'PGDAS-D', value: 'PGDASD' },
  { label: 'PGMEI', value: 'PGMEI' },
  { label: 'Parcelamentos', value: 'INSTALLMENTS' },
  { label: 'DCTFWeb', value: 'DCTFWEB' },
  { label: 'FGTS', value: 'FGTS' },
  { label: 'Caixa Postal', value: 'MAILBOX' },
  { label: 'SITFIS', value: 'SITFIS' },
  { label: 'Declarações', value: 'DECLARATIONS' },
  { label: 'Guias', value: 'GUIDES' },
  { label: 'Processos fiscais', value: 'TAX_PROCESSES' }
]

export function emptyProcessAudienceRules(): ProcessAudienceRules {
  return {
    tax_regimes: [],
    category_ids: [],
    category_match: 'ANY',
    excluded_category_ids: []
  }
}

export function cloneProcessAudienceRules(
  rules?: Partial<ProcessAudienceRules> | null
): ProcessAudienceRules {
  const validRegimes = new Set(WORK_TAX_REGIMES.map(item => item.value))
  const taxRegimes = (rules?.tax_regimes || [])
    .filter((value): value is ClientTaxRegimeCode => validRegimes.has(value as ClientTaxRegimeCode))

  return {
    tax_regimes: [...new Set(taxRegimes)],
    category_ids: positiveUniqueIds(rules?.category_ids),
    category_match: rules?.category_match === 'ALL' ? 'ALL' : 'ANY',
    excluded_category_ids: positiveUniqueIds(rules?.excluded_category_ids)
  }
}

export function buildGenerationSelection(
  rules: ProcessAudienceRules,
  includeClientIds: number[],
  excludeClientIds: number[]
): GenerationSelection {
  return {
    rules: cloneProcessAudienceRules(rules),
    include_client_ids: positiveUniqueIds(includeClientIds),
    exclude_client_ids: positiveUniqueIds(excludeClientIds)
  }
}

export function generationItemClientLabel(item: GenerationItem): string {
  const selection = item.preview_payload?.selection
  return selection?.client_name || `Cliente #${item.client_id}`
}

export function generationItemClientMeta(item: GenerationItem): string {
  const selection = item.preview_payload?.selection
  return [selection?.cnpj_masked, taxRegimeLabel(selection?.tax_regime)]
    .filter(Boolean)
    .join(' · ')
}

export function taxRegimeLabel(value?: string | null): string {
  return WORK_TAX_REGIMES.find(item => item.value === value)?.label || value || ''
}

export function monitoringModuleLabel(value?: string | null): string {
  return WORK_MONITORING_MODULES.find(item => item.value === value)?.label || value || 'Sem contexto'
}

export function nextExpandedProcessId(currentId: number | null, selectedId: number): number | null {
  return currentId === selectedId ? null : selectedId
}

function positiveUniqueIds(values?: number[] | null): number[] {
  return [...new Set((values || [])
    .map(Number)
    .filter(value => Number.isInteger(value) && value > 0))]
}

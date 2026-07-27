import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import type {
  MonitoringFilterConfig,
  MonitoringFilterValue,
  MonitoringStructuredFilterField
} from '~/types/fiscal-modules'
import { fiscalSituationFilterItems } from '~/utils/fiscal-status'
import {
  createFilterModel,
  decodeClientIds,
  encodeClientIds,
  normalizeFilterModels
} from '~/utils/data-table-filters'

/** Itens do chip Envio (Simples Nacional / PGDAS-D). */
export function monitoringSendStatusFilterItems(): Array<{ label: string, value: string }> {
  return [
    { label: 'Enviado', value: 'sent' },
    { label: 'Não enviado', value: 'not_sent' }
  ]
}

export const EMPTY_MONITORING_FILTERS: Readonly<MonitoringFilterValue> = Object.freeze({
  q: '',
  situation: 'all',
  competence: '',
  clientIds: [] as number[],
  deliveryStatus: 'all',
  sendStatus: 'all',
  paymentStatus: 'all',
  status: 'all',
  coverage: 'all',
  modality: 'all'
})

export function normalizeMonitoringFilters(
  value?: Partial<MonitoringFilterValue> | null
): MonitoringFilterValue {
  return {
    q: String(value?.q ?? '').trim(),
    situation: String(value?.situation || 'all'),
    competence: String(value?.competence ?? '').trim(),
    clientIds: decodeClientIds(value?.clientIds),
    deliveryStatus: String(value?.deliveryStatus || 'all'),
    sendStatus: String(value?.sendStatus || 'all'),
    paymentStatus: String(value?.paymentStatus || 'all'),
    status: String(value?.status || 'all'),
    coverage: String(value?.coverage || 'all'),
    modality: String(value?.modality || 'all')
  }
}

export function resetMonitoringFilters(): MonitoringFilterValue {
  return {
    ...EMPTY_MONITORING_FILTERS,
    clientIds: []
  }
}

/**
 * Detecta filtros realmente ativos sobre defaults normalizados.
 * `all`, string vazia e `null` NÃO contam como filtro aplicado.
 */
export function hasActiveMonitoringFilters(
  value?: Partial<MonitoringFilterValue> | null
): boolean {
  const n = normalizeMonitoringFilters(value)
  return Boolean(
    n.q
    || (n.situation && n.situation !== 'all')
    || n.competence
    || n.clientIds.length > 0
    || (n.deliveryStatus && n.deliveryStatus !== 'all')
    || (n.sendStatus && n.sendStatus !== 'all')
    || (n.paymentStatus && n.paymentStatus !== 'all')
    || (n.status && n.status !== 'all')
    || (n.coverage && n.coverage !== 'all')
    || (n.modality && n.modality !== 'all')
  )
}

/** Retorna os campos estruturados na ordem declarada pela superfície. */
export function resolveMonitoringFilterFields(
  config: MonitoringFilterConfig | null | undefined
): MonitoringStructuredFilterField[] {
  return config?.fields ?? []
}

/**
 * Eixos option com multi-seleção por default — só onde o backend portfolio
 * aceita lista/CSV (`ModulePortfolioFilters` + whereIn).
 *
 * Fora da allowlist: single (equality). Os demais campos não entram
 * até a API aceitar `IN`.
 * Override por campo: `field.multiple` em MonitoringStructuredFilterField.
 */
const MULTIPLE_OPTION_KEYS = new Set([
  'situation',
  'deliveryStatus',
  'sendStatus',
  'modality'
])

export function monitoringFieldsToDefinitions(
  fields: readonly MonitoringStructuredFilterField[]
): DataTableFilterDefinition[] {
  return fields.map((field): DataTableFilterDefinition => {
    if (field.kind === 'client') {
      return {
        key: field.key,
        kind: 'client',
        label: field.label,
        emptyValue: null,
        // Portfolio: multi por default; override field.multiple=false se necessário.
        multiple: field.multiple ?? true
      }
    }
    if (field.kind === 'month') {
      return { key: field.key, kind: 'month', label: field.label, emptyValue: '' }
    }
    const items = field.key === 'situation'
      ? (field.items && field.items.length > 0 ? field.items : fiscalSituationFilterItems(false))
      : field.key === 'sendStatus'
        ? (field.items && field.items.length > 0 ? field.items : monitoringSendStatusFilterItems())
        : field.items
    const multiple = field.kind === 'option'
      ? (field.multiple ?? MULTIPLE_OPTION_KEYS.has(field.key))
      : false
    return {
      key: field.key,
      kind: 'option',
      label: field.label,
      items,
      emptyValue: 'all',
      // coverage default single; situation/deliveryStatus/modality multi se API ok.
      multiple
    }
  })
}

const OPTION_KEYS = [
  'situation',
  'deliveryStatus',
  'sendStatus',
  'paymentStatus',
  'status',
  'coverage',
  'modality'
] as const

type OptionFilterKey = (typeof OPTION_KEYS)[number]

function isOptionFilterKey(key: string): key is OptionFilterKey {
  return (OPTION_KEYS as readonly string[]).includes(key)
}

/** Converte valor backend-facing → chips (omite defaults vazios). */
export function monitoringFiltersToModels(
  filters: MonitoringFilterValue,
  config: MonitoringFilterConfig | null | undefined,
  clientLabel?: string | null
): DataTableFilterModel[] {
  const fields = resolveMonitoringFilterFields(config)
  const definitions = monitoringFieldsToDefinitions(fields)
  const raw: DataTableFilterModel[] = []

  for (const definition of definitions) {
    if (definition.key === 'competence') {
      const model = createFilterModel(definition, filters.competence)
      if (model) raw.push(model)
      continue
    }
    if (definition.key === 'clientId') {
      const ids = filters.clientIds || []
      if (ids.length === 0) continue
      const value = definition.kind === 'client' && definition.multiple
        ? encodeClientIds(ids)
        : ids[0]!
      const model = createFilterModel(
        definition,
        value,
        clientLabel || (ids.length > 1 ? `${ids.length} clientes` : undefined)
      )
      if (model) raw.push(model)
      continue
    }
    if (isOptionFilterKey(definition.key)) {
      const model = createFilterModel(definition, filters[definition.key])
      if (model) raw.push(model)
    }
  }

  return normalizeFilterModels(raw, definitions)
}

/** Converte chips → valor backend-facing, preservando `q` e defaults. */
export function modelsToMonitoringFilters(
  models: readonly DataTableFilterModel[],
  config: MonitoringFilterConfig | null | undefined,
  base?: Partial<MonitoringFilterValue> | null
): MonitoringFilterValue {
  const fields = resolveMonitoringFilterFields(config)
  const definitions = monitoringFieldsToDefinitions(fields)
  const normalizedModels = normalizeFilterModels(models, definitions)
  const next = normalizeMonitoringFilters(base)
  const byKey = new Map(normalizedModels.map(model => [model.key, model]))

  // Campos estruturados desta config: default se ausente no chip.
  for (const definition of definitions) {
    const model = byKey.get(definition.key)
    if (definition.key === 'clientId') {
      next.clientIds = model ? decodeClientIds(model.value) : []
      continue
    }
    if (definition.key === 'competence') {
      next.competence = model ? String(model.value) : ''
      continue
    }
    if (isOptionFilterKey(definition.key)) {
      next[definition.key] = model ? String(model.value) : 'all'
    }
  }

  return next
}

export function monitoringAdvancedFieldActive(
  filters: MonitoringFilterValue,
  field: MonitoringStructuredFilterField
): boolean {
  if (field.key === 'clientId') return (filters.clientIds?.length ?? 0) > 0
  if (field.key === 'competence') return String(filters.competence || '').trim() !== ''
  if (field.key === 'situation') {
    return String(filters.situation || '').trim() !== '' && filters.situation !== 'all'
  }
  if (field.key === 'deliveryStatus') {
    return String(filters.deliveryStatus || '').trim() !== '' && filters.deliveryStatus !== 'all'
  }
  if (field.key === 'sendStatus') {
    return String(filters.sendStatus || '').trim() !== '' && filters.sendStatus !== 'all'
  }
  if (field.key === 'paymentStatus') {
    return String(filters.paymentStatus || '').trim() !== '' && filters.paymentStatus !== 'all'
  }
  if (field.key === 'status') {
    return String(filters.status || '').trim() !== '' && filters.status !== 'all'
  }
  if (field.key === 'coverage') {
    return String(filters.coverage || '').trim() !== '' && filters.coverage !== 'all'
  }
  if (field.key === 'modality') {
    return String(filters.modality || '').trim() !== '' && filters.modality !== 'all'
  }
  return false
}

export function countActiveMonitoringFilters(
  filters: MonitoringFilterValue,
  config: MonitoringFilterConfig
): number {
  const fields = resolveMonitoringFilterFields(config)
  return fields.filter(field => monitoringAdvancedFieldActive(filters, field)).length
}

export function monitoringFilterSignature(filters: MonitoringFilterValue): string {
  const normalized = normalizeMonitoringFilters(filters)
  return JSON.stringify([
    normalized.q,
    normalized.situation,
    normalized.competence,
    encodeClientIds(normalized.clientIds),
    normalized.deliveryStatus,
    normalized.sendStatus,
    normalized.paymentStatus,
    normalized.status,
    normalized.coverage,
    normalized.modality
  ])
}

/** Conta chips (ignora busca `q`). */
export function countStructuredMonitoringFilters(
  filters: MonitoringFilterValue,
  config: MonitoringFilterConfig
): number {
  return countActiveMonitoringFilters(filters, config)
}

export function isMonitoringStructuredFieldEmpty(
  key: keyof MonitoringFilterValue,
  value: unknown
): boolean {
  if (key === 'clientIds') return !Array.isArray(value) || value.length === 0
  if (key === 'competence' || key === 'q') return String(value ?? '').trim() === ''
  return value === 'all' || value === '' || value == null
}

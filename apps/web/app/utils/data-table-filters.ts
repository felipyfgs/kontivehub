import type {
  DataTableFilterDefinition,
  DataTableFilterModel,
  DataTableFilterOperator,
  DataTableFilterOptionItem
} from '~/types/data-table-filter'

const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/
const DATE_RE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/

export function isValidMonthValue(value: unknown): boolean {
  return typeof value === 'string' && MONTH_RE.test(value.trim())
}

export function isValidDateValue(value: unknown): boolean {
  if (typeof value !== 'string') return false
  const text = value.trim()
  if (!DATE_RE.test(text)) return false
  // Validação calendário (rejeita 2026-02-31 etc.).
  const [y, m, d] = text.split('-').map(Number)
  if (!y || !m || !d) return false
  const dt = new Date(Date.UTC(y, m - 1, d))
  return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d
}

/** Serializa intervalo de datas para o valor do chip. */
export function encodeDateRange(from: string, to: string): string {
  return `${from.trim()}..${to.trim()}`
}

/** Parse de "YYYY-MM-DD..YYYY-MM-DD". */
export function decodeDateRange(value: unknown): { from: string, to: string } | null {
  if (typeof value !== 'string') return null
  const parts = value.trim().split('..')
  if (parts.length !== 2) return null
  const from = parts[0]!.trim()
  const to = parts[1]!.trim()
  if (!isValidDateValue(from) || !isValidDateValue(to)) return null
  return { from, to }
}

export function isValidDateRangeValue(value: unknown): boolean {
  const range = decodeDateRange(value)
  if (!range) return false
  return range.from <= range.to
}

/**
 * Serializa múltiplas opções (estável: unique + sort).
 * Separador: vírgula (query HTTP `situation=A,B`).
 *
 * **Contrato:** `item.value` NÃO pode conter `,` — tokens de enum (PENDING, PARCSN…) ok.
 */
export function encodeOptionValues(values: readonly string[]): string {
  const unique = new Set<string>()
  for (const raw of values) {
    const text = String(raw ?? '').trim()
    if (text) unique.add(text)
  }
  return [...unique].sort((a, b) => a.localeCompare(b)).join(',')
}

/** Parse de valor option (único, multi "a,b" ou array). */
export function decodeOptionValues(value: unknown): string[] {
  if (value == null) return []
  const parts = Array.isArray(value)
    ? value.map(item => String(item ?? '').trim()).filter(Boolean)
    : String(value).split(',').map(item => item.trim()).filter(Boolean)
  if (parts.length === 0) return []
  const encoded = encodeOptionValues(parts)
  return encoded ? encoded.split(',') : []
}

export function isMultipleOption(
  definition: DataTableFilterDefinition
): definition is Extract<DataTableFilterDefinition, { kind: 'option' }> & { multiple: true } {
  return definition.kind === 'option' && definition.multiple === true
}

export function isMultipleClient(
  definition: DataTableFilterDefinition
): definition is Extract<DataTableFilterDefinition, { kind: 'client' }> & { multiple: true } {
  return definition.kind === 'client' && definition.multiple === true
}

/**
 * Serializa ids de cliente (unique + sort numérico).
 * Mesma regra de CSV que options — ids positivos apenas.
 */
export function encodeClientIds(ids: readonly number[]): string {
  const unique = new Set<number>()
  for (const raw of ids) {
    const id = Math.floor(Number(raw))
    if (Number.isFinite(id) && id >= 1) unique.add(id)
  }
  return [...unique].sort((a, b) => a - b).join(',')
}

/** Parse de client id único, CSV "1,2" ou array. */
export function decodeClientIds(value: unknown): number[] {
  if (value == null || value === '') return []
  if (Array.isArray(value)) {
    return encodeClientIds(value.map(Number)).split(',').filter(Boolean).map(Number)
  }
  if (typeof value === 'number') {
    return encodeClientIds([value]) ? [Math.floor(value)].filter(id => id >= 1) : []
  }
  const parts = String(value).split(',').map(part => Math.floor(Number(part.trim())))
  const encoded = encodeClientIds(parts)
  return encoded ? encoded.split(',').map(Number) : []
}

/** Rótulos visíveis no chip multi; o resto vira `+N`. */
export const CHIP_OPTION_LABEL_MAX = 2

/**
 * Junta rótulos de option multi para o chip (toolbar).
 * Ex.: 5 itens → "Pendente, Atenção +3"
 */
export function formatOptionChipValueLabel(
  labels: readonly string[],
  maxVisible: number = CHIP_OPTION_LABEL_MAX
): string {
  const clean = labels.map(label => String(label ?? '').trim()).filter(Boolean)
  if (clean.length === 0) return ''
  const limit = Math.max(1, maxVisible)
  if (clean.length <= limit) return clean.join(', ')
  return `${clean.slice(0, limit).join(', ')} +${clean.length - limit}`
}

type OptionDefinition = Extract<DataTableFilterDefinition, { kind: 'option' }>

/** Tokens multi válidos: allowlist do field, sem emptyValue. */
export function sanitizeMultipleOptionValues(
  definition: OptionDefinition,
  value: unknown
): string[] {
  const empty = String(definitionEmptyValue(definition))
  const allowed = new Set(definition.items.map(item => item.value))
  return decodeOptionValues(value).filter(item => item !== empty && allowed.has(item))
}

function optionTokensForDisplay(
  definition: OptionDefinition,
  model: DataTableFilterModel
): string[] {
  const tokens = decodeOptionValues(model.value)
  if (tokens.length > 0) return tokens
  if (model.value != null && String(model.value).trim() !== '') {
    return [String(model.value).trim()]
  }
  return []
}

function isMultiOptionDisplay(definition: OptionDefinition, model: DataTableFilterModel): boolean {
  if (definition.multiple) return true
  if (model.operator === 'in') return true
  return decodeOptionValues(model.value).length > 1
}

export function definitionEmptyValue(definition: DataTableFilterDefinition): string | number | boolean | null {
  if (definition.kind === 'client' || definition.kind === 'boolean') {
    return definition.emptyValue === undefined ? null : definition.emptyValue
  }
  if (definition.kind === 'month' || definition.kind === 'date' || definition.kind === 'date_range' || definition.kind === 'text') {
    return definition.emptyValue === undefined ? '' : definition.emptyValue
  }
  return definition.emptyValue === undefined ? 'all' : definition.emptyValue
}

export function isEmptyFilterValue(
  definition: DataTableFilterDefinition,
  value: unknown
): boolean {
  if (value === undefined || value === null) return true
  if (definition.kind === 'client') {
    if (definition.multiple) return decodeClientIds(value).length === 0
    const n = Number(value)
    return !Number.isFinite(n) || n <= 0
  }
  if (definition.kind === 'boolean') {
    if (typeof value === 'boolean') return false
    if (value === 'true' || value === 'false' || value === 1 || value === 0 || value === '1' || value === '0') {
      return false
    }
    return true
  }
  if (definition.kind === 'option' && definition.multiple) {
    return sanitizeMultipleOptionValues(definition, value).length === 0
  }
  const text = String(value).trim()
  if (text === '') return true
  const empty = definitionEmptyValue(definition)
  return text === String(empty)
}

export function findDefinition(
  definitions: readonly DataTableFilterDefinition[],
  key: string
): DataTableFilterDefinition | undefined {
  return definitions.find(item => item.key === key)
}

export function optionLabel(
  items: readonly DataTableFilterOptionItem[],
  value: string
): string {
  return items.find(item => item.value === value)?.label ?? value
}

function booleanLabel(definition: Extract<DataTableFilterDefinition, { kind: 'boolean' }>, value: boolean): string {
  if (value) return definition.trueLabel || 'Sim'
  return definition.falseLabel || 'Não'
}

export function resolveModelLabel(
  definition: DataTableFilterDefinition,
  value: string | number | boolean,
  explicitLabel?: string
): string | undefined {
  if (explicitLabel && explicitLabel.trim()) return explicitLabel.trim()
  if (definition.kind === 'option') {
    if (definition.multiple) {
      const values = sanitizeMultipleOptionValues(definition, value)
      if (values.length === 0) return undefined
      // Label completo no modelo (acessível); truncamento só no chip (formatChipDisplay).
      return values.map(item => optionLabel(definition.items, item)).join(', ')
    }
    return optionLabel(definition.items, String(value))
  }
  if (definition.kind === 'month' || definition.kind === 'date' || definition.kind === 'text') {
    return String(value)
  }
  if (definition.kind === 'boolean') {
    return booleanLabel(definition, Boolean(value))
  }
  if (definition.kind === 'date_range') {
    const range = decodeDateRange(value)
    if (!range) return String(value)
    return `${range.from} – ${range.to}`
  }
  return explicitLabel
}

function operatorForDefinition(
  definition: DataTableFilterDefinition
): DataTableFilterOperator {
  if (definition.kind === 'date_range') return 'between'
  if (definition.kind === 'option' && definition.multiple) return 'in'
  if (definition.kind === 'client' && definition.multiple) return 'in'
  if (definition.kind === 'text') return definition.operator === 'contains' ? 'contains' : 'eq'
  return 'eq'
}

export function createFilterModel(
  definition: DataTableFilterDefinition,
  value: string | number | boolean,
  label?: string
): DataTableFilterModel | null {
  if (isEmptyFilterValue(definition, value)) return null

  if (definition.kind === 'month' && !isValidMonthValue(value)) return null
  if (definition.kind === 'date' && !isValidDateValue(value)) return null
  if (definition.kind === 'date_range' && !isValidDateRangeValue(value)) return null

  if (definition.kind === 'client') {
    if (definition.multiple) {
      const ids = decodeClientIds(value)
      if (ids.length === 0) return null
      const encoded = encodeClientIds(ids)
      return {
        key: definition.key,
        operator: 'in',
        value: encoded,
        label: label?.trim() || (ids.length === 1 ? `#${ids[0]}` : `${ids.length} clientes`)
      }
    }
    const id = Math.floor(Number(value))
    if (!Number.isFinite(id) || id <= 0) return null
    return {
      key: definition.key,
      operator: 'eq',
      value: id,
      label: resolveModelLabel(definition, id, label)
    }
  }

  if (definition.kind === 'boolean') {
    const bool = typeof value === 'boolean' ? value : value === 'true' || value === 1 || value === '1'
    if (typeof value === 'string' && value !== 'true' && value !== 'false' && value !== '1' && value !== '0') {
      return null
    }
    return {
      key: definition.key,
      operator: 'eq',
      value: bool,
      label: resolveModelLabel(definition, bool, label)
    }
  }

  if (definition.kind === 'date_range') {
    const range = decodeDateRange(value)
    if (!range) return null
    const encoded = encodeDateRange(range.from, range.to)
    return {
      key: definition.key,
      operator: 'between',
      value: encoded,
      label: resolveModelLabel(definition, encoded, label)
    }
  }

  if (definition.kind === 'option' && definition.multiple) {
    const values = sanitizeMultipleOptionValues(definition, value)
    if (values.length === 0) return null
    const encoded = encodeOptionValues(values)
    return {
      key: definition.key,
      operator: 'in',
      value: encoded,
      label: resolveModelLabel(definition, encoded, label)
    }
  }

  const text = String(value).trim()
  return {
    key: definition.key,
    operator: operatorForDefinition(definition),
    value: text,
    label: resolveModelLabel(definition, text, label)
  }
}

/**
 * Normaliza modelos aplicados: omite vazios, deduplica por chave (último vence),
 * ordena conforme as definitions e descarta chaves desconhecidas.
 */
export function normalizeFilterModels(
  models: readonly DataTableFilterModel[] | null | undefined,
  definitions: readonly DataTableFilterDefinition[]
): DataTableFilterModel[] {
  const byKey = new Map<string, DataTableFilterModel>()
  for (const model of models || []) {
    const definition = findDefinition(definitions, model.key)
    if (!definition) continue
    const next = createFilterModel(definition, model.value, model.label)
    if (!next) {
      byKey.delete(model.key)
      continue
    }
    byKey.set(model.key, next)
  }

  const ordered: DataTableFilterModel[] = []
  for (const definition of definitions) {
    const model = byKey.get(definition.key)
    if (model) ordered.push(model)
  }
  return ordered
}

export function activeDefinitionKeys(
  models: readonly DataTableFilterModel[],
  definitions: readonly DataTableFilterDefinition[]
): Set<string> {
  return new Set(normalizeFilterModels(models, definitions).map(model => model.key))
}

export function inactiveDefinitions(
  models: readonly DataTableFilterModel[],
  definitions: readonly DataTableFilterDefinition[]
): DataTableFilterDefinition[] {
  const active = activeDefinitionKeys(models, definitions)
  return definitions.filter(definition => !active.has(definition.key))
}

export function canConfirmDraftValue(
  definition: DataTableFilterDefinition | undefined,
  value: unknown,
  valueTo?: unknown
): boolean {
  if (!definition) return false

  if (definition.kind === 'date_range') {
    const from = String(value ?? '').trim()
    const to = String(valueTo ?? '').trim()
    if (!from || !to) return false
    return isValidDateRangeValue(encodeDateRange(from, to))
  }

  if (isEmptyFilterValue(definition, value)) return false
  if (definition.kind === 'month') return isValidMonthValue(value)
  if (definition.kind === 'date') return isValidDateValue(value)
  if (definition.kind === 'client') {
    if (definition.multiple) return decodeClientIds(value).length > 0
    const n = Number(value)
    return Number.isFinite(n) && n > 0
  }
  if (definition.kind === 'boolean') {
    return typeof value === 'boolean'
      || value === 'true'
      || value === 'false'
      || value === 1
      || value === 0
      || value === '1'
      || value === '0'
  }
  if (definition.kind === 'option' && definition.multiple) {
    return sanitizeMultipleOptionValues(definition, value).length > 0
  }
  if (definition.kind === 'text') {
    return String(value).trim().length > 0
  }
  return true
}

export function formatChipDisplay(
  definition: DataTableFilterDefinition,
  model: DataTableFilterModel
): { fieldLabel: string, operatorLabel: string, valueLabel: string } {
  let valueLabel: string

  if (definition.kind === 'option') {
    const tokens = optionTokensForDisplay(definition, model)
    if (isMultiOptionDisplay(definition, model) && tokens.length > 0) {
      // Sempre re-deriva do valor (não confia em label longo/cru).
      const labels = tokens.map(token => optionLabel(definition.items, token))
      valueLabel = formatOptionChipValueLabel(labels)
    } else {
      valueLabel = model.label?.trim()
        || optionLabel(definition.items, String(model.value))
    }
  } else if (definition.kind === 'client' && definition.multiple) {
    const ids = decodeClientIds(model.value)
    if (model.label?.trim() && !model.label.includes(',')) {
      // label de um nome único
      valueLabel = model.label.trim()
    } else if (model.label?.trim() && ids.length <= CHIP_OPTION_LABEL_MAX) {
      valueLabel = model.label.trim()
    } else if (ids.length === 0) {
      valueLabel = model.label?.trim() || '—'
    } else if (ids.length === 1) {
      valueLabel = model.label?.trim() || `#${ids[0]}`
    } else {
      // Preferir label composto se parecer lista de nomes; senão contagem.
      const label = model.label?.trim()
      if (label && label.includes(',')) {
        const parts = label.split(',').map(part => part.trim()).filter(Boolean)
        valueLabel = formatOptionChipValueLabel(parts)
      } else {
        valueLabel = `${ids.length} clientes`
      }
    }
  } else if (model.label?.trim()) {
    valueLabel = model.label.trim()
  } else if (definition.kind === 'boolean') {
    valueLabel = booleanLabel(definition, Boolean(model.value))
  } else if (definition.kind === 'date_range') {
    const range = decodeDateRange(model.value)
    valueLabel = range ? `${range.from} – ${range.to}` : String(model.value)
  } else {
    valueLabel = String(model.value)
  }

  // Operadores curtos no chip (toolbar densa).
  let operatorLabel = ':'
  if (model.operator === 'contains' || (definition.kind === 'text' && definition.operator === 'contains')) {
    operatorLabel = '~'
  } else if (model.operator === 'between' || definition.kind === 'date_range') {
    operatorLabel = '–'
  } else if (
    model.operator === 'in'
    || (definition.kind === 'option' && definition.multiple)
    || (definition.kind === 'client' && definition.multiple)
  ) {
    operatorLabel = ':'
  }

  return {
    fieldLabel: definition.label,
    operatorLabel,
    valueLabel
  }
}

export function upsertFilterModel(
  models: readonly DataTableFilterModel[],
  definitions: readonly DataTableFilterDefinition[],
  next: DataTableFilterModel
): DataTableFilterModel[] {
  const without = models.filter(model => model.key !== next.key)
  return normalizeFilterModels([...without, next], definitions)
}

export function removeFilterModel(
  models: readonly DataTableFilterModel[],
  definitions: readonly DataTableFilterDefinition[],
  key: string
): DataTableFilterModel[] {
  return normalizeFilterModels(
    models.filter(model => model.key !== key),
    definitions
  )
}

/** Valor inicial de rascunho ao adicionar um filtro. */
export function draftEmptyValue(
  definition: DataTableFilterDefinition
): string | number | boolean | null {
  if (definition.kind === 'boolean') return null
  if (definition.kind === 'client') {
    return definition.multiple ? '' : null
  }
  if (definition.kind === 'month' || definition.kind === 'date' || definition.kind === 'date_range' || definition.kind === 'text') {
    return ''
  }
  if (definition.kind === 'option' && definition.multiple) return ''
  return definition.emptyValue ?? 'all'
}

/** Ícone Lucide por kind de filtro (seletor + chip). */
export function filterKindIcon(definition: DataTableFilterDefinition): string {
  switch (definition.kind) {
    case 'client':
      return definition.multiple ? 'i-lucide-users' : 'i-lucide-user'
    case 'month':
      return 'i-lucide-calendar-range'
    case 'date':
      return 'i-lucide-calendar'
    case 'date_range':
      return 'i-lucide-calendar-clock'
    case 'text':
      return 'i-lucide-text-cursor-input'
    case 'boolean':
      return 'i-lucide-toggle-left'
    case 'option':
      return definition.multiple ? 'i-lucide-tags' : 'i-lucide-list-filter'
    default:
      return 'i-lucide-list-filter'
  }
}

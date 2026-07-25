/**
 * Defaults e helpers da agenda de recorrência da Rotina (C1).
 * Contrato alinhado à API `/api/v1/work/templates/{id}/recurrence`.
 */
import type {
  ProcessTemplate,
  ProcessTemplateRecurrence,
  RecurrenceFrequency,
  RecurrencePeriodOffset
} from '~/types/work'

export const RECURRENCE_MIN_GENERATION_DAY = 1
export const RECURRENCE_MAX_GENERATION_DAY = 28
export const RECURRENCE_DEFAULT_GENERATION_DAY = 1
export const RECURRENCE_DEFAULT_PERIOD_OFFSET: RecurrencePeriodOffset = 'PREVIOUS'

export const RECURRENCE_FREQUENCY_ITEMS: ReadonlyArray<{
  label: string
  value: RecurrenceFrequency
}> = [
  { label: 'Mensal', value: 'MONTHLY' },
  { label: 'Trimestral', value: 'QUARTERLY' },
  { label: 'Anual', value: 'YEARLY' }
]

export const RECURRENCE_PERIOD_OFFSET_ITEMS: ReadonlyArray<{
  label: string
  value: RecurrencePeriodOffset
}> = [
  { label: 'Período anterior', value: 'PREVIOUS' },
  { label: 'Período atual', value: 'CURRENT' }
]

/** Defaults ao habilitar recorrência sem sobrescrever dia/defasagem. */
export function defaultRecurrenceConfig(
  frequency: RecurrenceFrequency = 'MONTHLY'
): ProcessTemplateRecurrence {
  return {
    recurrence_enabled: true,
    recurrence_frequency: frequency,
    generation_day: RECURRENCE_DEFAULT_GENERATION_DAY,
    anchor_month: null,
    period_offset: RECURRENCE_DEFAULT_PERIOD_OFFSET,
    next_run_at: null,
    recurrence_owner_membership_id: null
  }
}

export function emptyRecurrenceConfig(): ProcessTemplateRecurrence {
  return {
    recurrence_enabled: false,
    recurrence_frequency: null,
    generation_day: RECURRENCE_DEFAULT_GENERATION_DAY,
    anchor_month: null,
    period_offset: RECURRENCE_DEFAULT_PERIOD_OFFSET,
    next_run_at: null,
    recurrence_owner_membership_id: null
  }
}

export function recurrenceFromTemplate(
  template?: Pick<
    ProcessTemplate,
    | 'recurrence_enabled'
    | 'recurrence_frequency'
    | 'generation_day'
    | 'anchor_month'
    | 'period_offset'
    | 'next_run_at'
    | 'recurrence_owner_membership_id'
  > | null
): ProcessTemplateRecurrence {
  if (!template) return emptyRecurrenceConfig()
  return {
    recurrence_enabled: template.recurrence_enabled === true,
    recurrence_frequency: template.recurrence_frequency ?? null,
    generation_day: clampGenerationDay(template.generation_day),
    anchor_month: template.anchor_month ?? null,
    period_offset: template.period_offset ?? RECURRENCE_DEFAULT_PERIOD_OFFSET,
    next_run_at: template.next_run_at ?? null,
    recurrence_owner_membership_id: template.recurrence_owner_membership_id ?? null
  }
}

export function clampGenerationDay(value: unknown): number {
  const day = Number(value)
  if (!Number.isInteger(day)) return RECURRENCE_DEFAULT_GENERATION_DAY
  if (day < RECURRENCE_MIN_GENERATION_DAY || day > RECURRENCE_MAX_GENERATION_DAY) {
    return RECURRENCE_DEFAULT_GENERATION_DAY
  }
  return day
}

export function isValidGenerationDay(value: unknown): boolean {
  const day = Number(value)
  return Number.isInteger(day)
    && day >= RECURRENCE_MIN_GENERATION_DAY
    && day <= RECURRENCE_MAX_GENERATION_DAY
}

export function recurrenceFrequencyLabel(
  frequency?: RecurrenceFrequency | null
): string {
  if (!frequency) return '—'
  return RECURRENCE_FREQUENCY_ITEMS.find(item => item.value === frequency)?.label || frequency
}

export function recurrencePeriodOffsetLabel(
  offset?: RecurrencePeriodOffset | null
): string {
  if (!offset) return '—'
  return RECURRENCE_PERIOD_OFFSET_ITEMS.find(item => item.value === offset)?.label || offset
}

/** Payload PATCH da agenda (sem office_id). */
export function recurrencePatchPayload(
  config: ProcessTemplateRecurrence
): Record<string, unknown> {
  if (!config.recurrence_enabled) {
    return { recurrence_enabled: false }
  }
  return {
    recurrence_enabled: true,
    recurrence_frequency: config.recurrence_frequency || 'MONTHLY',
    generation_day: clampGenerationDay(config.generation_day),
    period_offset: config.period_offset || RECURRENCE_DEFAULT_PERIOD_OFFSET,
    ...(config.anchor_month != null ? { anchor_month: config.anchor_month } : {}),
    ...(config.recurrence_owner_membership_id != null
      ? { recurrence_owner_membership_id: config.recurrence_owner_membership_id }
      : {})
  }
}

export function formatNextRunAt(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(date)
}

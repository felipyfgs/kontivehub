import { describe, expect, it } from 'vitest'
import {
  RECURRENCE_DEFAULT_GENERATION_DAY,
  RECURRENCE_DEFAULT_PERIOD_OFFSET,
  RECURRENCE_FREQUENCY_ITEMS,
  RECURRENCE_MAX_GENERATION_DAY,
  RECURRENCE_MIN_GENERATION_DAY,
  clampGenerationDay,
  defaultRecurrenceConfig,
  emptyRecurrenceConfig,
  formatNextRunAt,
  isValidGenerationDay,
  recurrenceFrequencyLabel,
  recurrenceFromTemplate,
  recurrencePatchPayload,
  recurrencePeriodOffsetLabel
} from '../../app/utils/work-routine-recurrence'

describe('work-routine-recurrence', () => {
  it('expõe frequências e defaults de agenda', () => {
    expect(RECURRENCE_FREQUENCY_ITEMS.map(item => item.value)).toEqual([
      'MONTHLY',
      'QUARTERLY',
      'YEARLY'
    ])
    expect(defaultRecurrenceConfig()).toMatchObject({
      recurrence_enabled: true,
      recurrence_frequency: 'MONTHLY',
      generation_day: RECURRENCE_DEFAULT_GENERATION_DAY,
      period_offset: RECURRENCE_DEFAULT_PERIOD_OFFSET
    })
    expect(emptyRecurrenceConfig().recurrence_enabled).toBe(false)
  })

  it('valida e clampa o dia de geração (1–28)', () => {
    expect(isValidGenerationDay(1)).toBe(true)
    expect(isValidGenerationDay(28)).toBe(true)
    expect(isValidGenerationDay(0)).toBe(false)
    expect(isValidGenerationDay(29)).toBe(false)
    expect(clampGenerationDay(15)).toBe(15)
    expect(clampGenerationDay(0)).toBe(RECURRENCE_DEFAULT_GENERATION_DAY)
    expect(clampGenerationDay(31)).toBe(RECURRENCE_DEFAULT_GENERATION_DAY)
    expect(RECURRENCE_MIN_GENERATION_DAY).toBe(1)
    expect(RECURRENCE_MAX_GENERATION_DAY).toBe(28)
  })

  it('monta config a partir do template e payload PATCH', () => {
    const fromTemplate = recurrenceFromTemplate({
      recurrence_enabled: true,
      recurrence_frequency: 'QUARTERLY',
      generation_day: 10,
      anchor_month: 3,
      period_offset: 'CURRENT',
      next_run_at: '2026-08-01T12:00:00Z',
      recurrence_owner_membership_id: 7
    })
    expect(fromTemplate).toMatchObject({
      recurrence_enabled: true,
      recurrence_frequency: 'QUARTERLY',
      generation_day: 10,
      period_offset: 'CURRENT',
      recurrence_owner_membership_id: 7
    })
    expect(recurrencePatchPayload(fromTemplate)).toEqual({
      recurrence_enabled: true,
      recurrence_frequency: 'QUARTERLY',
      generation_day: 10,
      period_offset: 'CURRENT',
      anchor_month: 3,
      recurrence_owner_membership_id: 7
    })
    expect(recurrencePatchPayload(emptyRecurrenceConfig())).toEqual({
      recurrence_enabled: false
    })
  })

  it('rotula frequência, defasagem e próxima execução', () => {
    expect(recurrenceFrequencyLabel('MONTHLY')).toBe('Mensal')
    expect(recurrenceFrequencyLabel(null)).toBe('—')
    expect(recurrencePeriodOffsetLabel('PREVIOUS')).toBe('Período anterior')
    expect(formatNextRunAt(null)).toBe('—')
    expect(formatNextRunAt('not-a-date')).toBe('not-a-date')
  })
})

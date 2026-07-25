import { describe, expect, it } from 'vitest'
import {
  SERPRO_ENVIRONMENT_OPTIONS,
  SERPRO_POWER_OPTIONS,
  SERPRO_SERVICE_OPTIONS,
  clientSelectOptions,
  isValidSerproIdentity,
  normalizeSerproIdentity,
  powerOption,
  serviceOption
} from '~/utils/serpro-selectors'

describe('serpro-selectors', () => {
  it('exposes catalog options', () => {
    expect(SERPRO_SERVICE_OPTIONS.length).toBeGreaterThan(0)
    expect(SERPRO_POWER_OPTIONS.length).toBeGreaterThan(0)
    expect(SERPRO_ENVIRONMENT_OPTIONS.map(o => o.value)).toContain('TRIAL')
  })

  it('resolves service and power options', () => {
    expect(serviceOption('SITFIS')?.label).toBe('SITFIS')
    expect(powerOption('PROCURACAO_OBTER')?.label).toContain('Procuração')
    expect(serviceOption('UNKNOWN')?.value).toBe('UNKNOWN')
  })

  it('validates and normalizes identities', () => {
    expect(isValidSerproIdentity('CPF', '123.456.789-09')).toBe(true)
    expect(isValidSerproIdentity('CNPJ', '12.345.678/0001-90')).toBe(true)
    expect(isValidSerproIdentity('CPF', '123')).toBe(false)
    expect(normalizeSerproIdentity('12.345.678/0001-90')).toBe('12345678000190')
  })

  it('builds client select options', () => {
    expect(clientSelectOptions([
      { id: 7, name: 'Acme', root_cnpj: '12345678' }
    ])).toEqual([
      {
        value: 7,
        label: 'Acme',
        description: 'CNPJ raiz 12345678'
      }
    ])
  })
})

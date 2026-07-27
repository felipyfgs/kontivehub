import { describe, expect, it } from 'vitest'
import {
  fiscalControlModuleKey,
  fiscalModuleStateColor,
  fiscalModuleStateLabel,
  fiscalRestrictionActor,
  fiscalRestrictionDate
} from '../../app/utils/fiscal-module-controls'

describe('controles dos módulos fiscais', () => {
  it('traduz os cinco estados efetivos da API', () => {
    expect(fiscalModuleStateLabel('AVAILABLE')).toBe('Disponível')
    expect(fiscalModuleStateLabel('GLOBALLY_RESTRICTED')).toBe('Restrito globalmente')
    expect(fiscalModuleStateLabel('TENANT_RESTRICTED')).toBe('Restrito para este escritório')
    expect(fiscalModuleStateLabel('AWAITING_CONFIGURATION')).toBe('Aguardando configuração')
    expect(fiscalModuleStateLabel('TECHNICAL_FAILURE')).toBe('Falha técnica')
  })

  it('usa cores semânticas e mantém falha/restrição em erro', () => {
    expect(fiscalModuleStateColor('AVAILABLE')).toBe('success')
    expect(fiscalModuleStateColor('AWAITING_CONFIGURATION')).toBe('warning')
    expect(fiscalModuleStateColor('TECHNICAL_FAILURE')).toBe('error')
  })

  it('expõe responsável e data da última alteração', () => {
    const control = {
      id: 1,
      restricted: true,
      reason: 'Pausa operacional',
      updated_by: { id: 7, name: 'Admin Plataforma' },
      restricted_at: '2026-07-19T12:00:00-03:00',
      updated_at: '2026-07-19T13:00:00-03:00',
      blocked_jobs_count: 2
    }
    expect(fiscalRestrictionActor(control)).toBe('Admin Plataforma')
    expect(fiscalRestrictionDate(control)).toBe('2026-07-19T13:00:00-03:00')
  })

  it('aceita somente chaves canônicas do catálogo', () => {
    expect(fiscalControlModuleKey('installments')).toBe('installments')
    expect(fiscalControlModuleKey('sitfis')).toBe('sitfis')
    expect(fiscalControlModuleKey(null, 'monitoring.registrations')).toBe('registrations')
    expect(fiscalControlModuleKey(null, 'monitoring.tax_processes')).toBe('tax_processes')
    expect(fiscalControlModuleKey('parcelamentos')).toBeNull()
  })
})

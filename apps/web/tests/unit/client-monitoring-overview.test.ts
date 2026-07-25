import { describe, expect, it } from 'vitest'
import {
  buildClientMonitoringOverview,
  mapSnapshotToProcessKey
} from '~/utils/client-monitoring-overview'
import type { FiscalSnapshot } from '~/types/api'

function snap(partial: Partial<FiscalSnapshot>): FiscalSnapshot {
  return {
    id: partial.id ?? 1,
    office_id: partial.office_id ?? 1,
    ...partial
  }
}

describe('client-monitoring-overview', () => {
  it('mapeia service_code conhecido para seção', () => {
    expect(mapSnapshotToProcessKey({ service_code: 'PGDASD' })).toBe('pgdasd')
    expect(mapSnapshotToProcessKey({ service_code: 'SITFIS' })).toBe('sitfis')
    expect(mapSnapshotToProcessKey({ service_code: 'FGTS' })).toBe('fgts')
    expect(mapSnapshotToProcessKey({ service_code: 'DCTFWEB' })).toBe('dctfweb')
    expect(mapSnapshotToProcessKey({ service_code: 'CAIXAPOSTAL' })).toBe('mailbox')
    expect(mapSnapshotToProcessKey({ system_code: 'INTEGRA_SITFIS' })).toBe('sitfis')
  })

  it('não inventa seção para código desconhecido', () => {
    expect(mapSnapshotToProcessKey({ service_code: 'XYZ_UNKNOWN' })).toBeNull()
  })

  it('monta catálogo completo e preenche status só com evidência local', () => {
    const cards = buildClientMonitoringOverview(42, [
      snap({
        id: 10,
        service_code: 'PGDASD',
        situation: 'UP_TO_DATE',
        observed_at: '2026-07-01T10:00:00Z'
      }),
      snap({
        id: 11,
        service_code: 'PGDASD',
        situation: 'PENDING',
        observed_at: '2026-07-10T10:00:00Z'
      }),
      snap({
        id: 12,
        service_code: 'SITFIS',
        situation: 'ATTENTION',
        observed_at: '2026-06-01T10:00:00Z'
      })
    ])

    expect(cards.length).toBeGreaterThanOrEqual(6)
    expect(cards.every(c => c.to.startsWith('/monitoring/clients/42'))).toBe(true)

    const pgdasd = cards.find(c => c.key === 'pgdasd')
    expect(pgdasd?.situation).toBe('PENDING')
    expect(pgdasd?.hasLocalEvidence).toBe(true)
    expect(pgdasd?.to).toBe('/monitoring/clients/42/pgdasd')

    const sitfis = cards.find(c => c.key === 'sitfis')
    expect(sitfis?.situation).toBe('ATTENTION')

    const fgts = cards.find(c => c.key === 'fgts')
    expect(fgts?.hasLocalEvidence).toBe(false)
    expect(fgts?.situation).toBeNull()

    expect(cards.map(c => c.key)).toContain('registrations')
    expect(cards.map(c => c.key)).toContain('dctfweb')
    expect(cards.find(c => c.key === 'pgdasd')?.label).toBe('Simples Nacional')
  })

  it('sem snapshots: todos sem evidência local', () => {
    const cards = buildClientMonitoringOverview(7, [], { isMei: true })
    expect(cards.every(c => !c.hasLocalEvidence && c.situation === null)).toBe(true)
  })
})

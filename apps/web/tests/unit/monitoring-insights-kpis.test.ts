import { describe, expect, it } from 'vitest'
import type { MonitoringInsightsPayload } from '~/types/monitoring-insights'
import { buildMonitoringKpis } from '~/utils/monitoring-insights'

function payload(kpis: MonitoringInsightsPayload['kpis']): MonitoringInsightsPayload {
  return {
    as_of: '2026-07-21T12:00:00-03:00',
    kpis,
    pending: null,
    rbt12: null,
    mailbox: null,
    notifications: null,
    declarations_absence: null,
    sitfis: null,
    obligations_progress: null,
    partial_errors: null
  }
}

describe('monitoring insights KPI mapping', () => {
  it('preserva zeros confirmados pelo agregador', () => {
    const items = buildMonitoringKpis(payload({
      clients_total: 0,
      pending_open: 0,
      findings_active: 0,
      modules_with_error: 0
    }))

    expect(items.map(item => item.value)).toEqual([0, 0, 0, 0])
    expect(items.every(item => item.critical !== true)).toBe(true)
  })

  it('não transforma fonte indisponível em zero', () => {
    const items = buildMonitoringKpis(payload({
      clients_total: null,
      pending_open: null,
      findings_active: null,
      modules_with_error: null
    }))

    expect(items.map(item => item.value)).toEqual(['—', '—', '—', '—'])
  })

  it('usa reticências somente durante a primeira carga', () => {
    expect(buildMonitoringKpis(null, { loading: true }).map(item => item.value))
      .toEqual(['…', '…', '…', '…'])
    expect(buildMonitoringKpis(null, { loading: false }).map(item => item.value))
      .toEqual(['—', '—', '—', '—'])
  })

  it('marca apenas contadores positivos como críticos', () => {
    const items = buildMonitoringKpis(payload({
      clients_total: 12,
      pending_open: 3,
      findings_active: 2,
      modules_with_error: 1
    }))

    expect(items.find(item => item.key === 'clients')?.critical).not.toBe(true)
    expect(items.filter(item => item.critical).map(item => item.key))
      .toEqual(['pending', 'findings', 'module_errors'])
  })
})

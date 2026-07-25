import { describe, expect, it } from 'vitest'
import type { OperationsSummary } from '~/types/api'
import {
  buildHomeCommunicationKpis,
  buildHomeFiscalKpis,
  buildHomeSerproKpis,
  homeBlocksBanner,
  homeDisplayValue
} from '~/utils/home-cockpit'

function baseSummary(overrides: Partial<OperationsSummary> = {}): OperationsSummary {
  return {
    clients: 1,
    establishments: 0,
    notes: 0,
    exports_ready: 0,
    exports_pending: 0,
    sync_due: 0,
    sync_blocked: 0,
    sync_failures_24h: 0,
    credentials_expiring_30d: 0,
    generated_at: '2026-07-22T12:00:00+00:00',
    ...overrides
  }
}

describe('home-cockpit fail-closed mapping', () => {
  it('não inventa zero enquanto loading sem summary', () => {
    expect(homeDisplayValue(true, false, 0)).toBe('…')
    expect(homeDisplayValue(false, false, 0)).toBe('—')
    expect(homeDisplayValue(false, true, 0)).toBe(0)
  })

  it('exibe banner quando blocks.blocked', () => {
    const banner = homeBlocksBanner(baseSummary({
      blocks: { blocked: true, reasons: ['KILL_SWITCH'], next_action: 'KILL_SWITCH' }
    }))
    expect(banner?.show).toBe(true)
    expect(banner?.tone).toBe('error')
    expect(banner?.to).toBe('/health')
  })

  it('KPIs fiscais usam placeholder em loading', () => {
    const items = buildHomeFiscalKpis(null, { loading: true })
    expect(items.every(i => i.value === '…')).toBe(true)
  })

  it('comunicação indisponível não vira zero saudável', () => {
    const items = buildHomeCommunicationKpis(baseSummary({
      communication: { available: false, deep_link: '/communication' }
    }))
    expect(items.every(i => i.value === '—')).toBe(true)
  })

  it('comunicação disponível mapeia contagens reais', () => {
    const items = buildHomeCommunicationKpis(baseSummary({
      communication: {
        available: true,
        global_enabled: true,
        gateway_enabled: true,
        office_enabled: true,
        inboxes_by_status: { CONNECTED: 2, CONNECTING: 1, DISCONNECTED: 2 },
        conversations_open: 4,
        conversations_pending: 1,
        outbox_dead: 3,
        outbox_retry: 0
      }
    }))
    const byKey = Object.fromEntries(items.map(i => [i.key, i]))
    expect(byKey.wa_connected?.value).toBe(2)
    expect(byKey.wa_attention?.value).toBe(3)
    expect(byKey.wa_outbox_dead?.critical).toBe(true)
  })

  it('normaliza deep_link legado de uso para /conta/consumo', () => {
    const items = buildHomeSerproKpis(baseSummary({
      usage: {
        available: true,
        remaining: 10,
        deep_link: '/settings/consumo'
      }
    }))
    const usage = items.find(i => i.key === 'usage_remaining')
    expect(usage?.to).toBe('/conta/consumo')
  })
})

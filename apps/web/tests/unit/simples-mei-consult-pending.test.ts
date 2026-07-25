import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  extractConsultRunRef,
  isFiscalMonitoringRunTerminal
} from '../../app/utils/fiscal-monitoring-run'

const read = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('fiscal monitoring run helpers', () => {
  it('reconhece status terminais', () => {
    expect(isFiscalMonitoringRunTerminal('COMPLETED')).toBe(true)
    expect(isFiscalMonitoringRunTerminal('FAILED')).toBe(true)
    expect(isFiscalMonitoringRunTerminal('QUEUED')).toBe(false)
    expect(isFiscalMonitoringRunTerminal('RUNNING')).toBe(false)
  })

  it('extrai clientId/runId do payload público', () => {
    expect(extractConsultRunRef({ id: 12, client_id: 3 })).toEqual({ clientId: 3, runId: 12 })
    expect(extractConsultRunRef({ id: 0, client_id: 3 })).toBeNull()
  })
})

describe('simples-mei consult pending skeleton wiring', () => {
  it('builders aceitam pendingClientIds e test-ids de skeleton', () => {
    const pgdasd = read('app/utils/pgdasd-table.ts')
    const pgmei = read('app/utils/pgmei-table.ts')
    expect(pgdasd).toContain('pendingClientIds')
    expect(pgdasd).toContain('pgdasd-situation-pending')
    expect(pgdasd).toContain('consultPendingSkeleton')
    expect(pgmei).toContain('pendingClientIds')
    expect(pgmei).toContain('pgmei-situation-pending')
  })

  it('página e bulk acompanham consult-enqueued sem refresh global no settle', () => {
    const page = read('app/components/monitoring/simples-mei/Portfolio.vue')
    expect(page).toContain('trackConsultPending')
    expect(page).toContain('pendingClientIds')
    expect(page).toContain('loadClients({ silent: true })')
    expect(read('app/components/monitoring/pgdasd/SelectionActions.vue')).toContain('consult-enqueued')
    expect(read('app/components/monitoring/pgmei/BulkActions.vue')).toContain('consult-enqueued')
    expect(read('app/composables/useSimplesMeiConsultPending.ts')).toContain('fiscal.runs.get')
  })
})

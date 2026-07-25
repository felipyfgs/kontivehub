import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  extractConsultRunRef,
  isFiscalMonitoringRunTerminal
} from '~/utils/fiscal-monitoring-run'
import { defaultReadCodesForModule } from '~/utils/fiscal-high-risk'
import {
  hasActiveMonitoringFilters,
  normalizeMonitoringFilters,
  resetMonitoringFilters
} from '~/utils/monitoring-filters'
import { monitoringModuleBasePath } from '~/utils/monitoring-nav'

const read = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('simples-nacional-portfolio e2e wiring', () => {
  it('rota canônica é /monitoring/simples e legado redireciona', () => {
    expect(monitoringModuleBasePath('simples_mei')).toBe('/monitoring/simples')
    expect(read('app/pages/monitoring/simples/index.vue')).toContain('submodule="PGDASD"')
    expect(read('app/pages/monitoring/simples-mei/index.vue')).toContain('navigateTo(\'/monitoring/simples\'')
  })

  it('payload de consulta PGDAS-D da UI usa códigos oficiais MONITOR', () => {
    expect(defaultReadCodesForModule('simples_mei')).toEqual({
      system_code: 'INTEGRA_SN',
      service_code: 'PGDASD',
      operation_code: 'MONITOR'
    })
  })

  it('filtros da carteira ficam em estado local com URL Nuxt path-only', () => {
    const portfolio = read('app/composables/useFiscalModulePortfolio.ts')
    for (const token of ['situation', 'competence', 'send_status', 'client_id', 'buildFilters', 'router.replace']) {
      expect(portfolio).toContain(token)
    }
    expect(portfolio).not.toContain('serializeListFilterQuery')
    expect(portfolio).not.toContain('useListFilterQuery')
    expect(portfolio).not.toContain('MONITORING_LIST_QUERY_SCHEMA')
    expect(portfolio).toContain('Object.keys(route.query).length > 0')

    const page = read('app/components/monitoring/simples-mei/Portfolio.vue')
    expect(page).toContain('key: \'situation\'')
    expect(page).toContain('key: \'sendStatus\'')
    expect(page).toContain('key: \'competence\'')
    expect(page).toContain('key: \'clientId\'')

    const active = normalizeMonitoringFilters({
      situation: 'PENDING',
      sendStatus: 'sent',
      competence: '2026-06',
      clientId: '12'
    })
    expect(hasActiveMonitoringFilters(active)).toBe(true)
    expect(resetMonitoringFilters().sendStatus).toBe('all')
  })

  it('consulta linha/bulk rastreia pending e faz poll da run', () => {
    const page = read('app/components/monitoring/simples-mei/Portfolio.vue')
    expect(page).toContain('useSimplesMeiConsultPending')
    expect(page).toContain('trackConsultPending')
    expect(page).toContain('pgdasd-row-consult-confirm')
    expect(page).toContain('loadClients({ silent: true })')

    const pending = read('app/composables/useSimplesMeiConsultPending.ts')
    expect(pending).toContain('fiscal.runs.get')
    expect(pending).toContain('isFiscalMonitoringRunTerminal')
    expect(pending).toContain('POLL_MS')

    const bulk = read('app/components/monitoring/pgdasd/SelectionActions.vue')
    expect(bulk).toContain('enqueueReadUpdate')
    expect(bulk).toContain('consult-enqueued')

    expect(extractConsultRunRef({ id: 9, client_id: 4 })).toEqual({ clientId: 4, runId: 9 })
    expect(isFiscalMonitoringRunTerminal('COMPLETED')).toBe(true)
    expect(isFiscalMonitoringRunTerminal('QUEUED')).toBe(false)
  })

  it('membership e permissões diferenciam viewer de operador', () => {
    const page = read('app/components/monitoring/simples-mei/Portfolio.vue')
    expect(page).toContain('canManageClients')
    expect(page).toContain('canTriggerSync')
    expect(page).toContain('${testIdPrefix}-associate-clients')
    expect(page).toContain('MonitoringAssociateMonitoringClientsModal')
    expect(page).toContain(':selection-enabled="canManageClients"')

    const actions = read('app/components/monitoring/pgdasd/SelectionActions.vue')
    expect(actions).toContain('canConsult')
    expect(actions).toContain('canTriggerSync')
    expect(page).toContain(':can-consult="canTriggerSync"')
  })
})

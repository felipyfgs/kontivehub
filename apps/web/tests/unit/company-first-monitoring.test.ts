import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)

describe('company-first-monitoring fidelity', () => {
  it('overview da empresa lista processos em vez de snapshots', () => {
    const page = readFileSync(root('app/pages/monitoring/clients/[clientId].vue'), 'utf8')
    expect(page).toContain('MonitoringClientProcessOverview')
    expect(page).toContain('Processos monitorados')
    expect(page).toContain('buildClientMonitoringOverview')
    expect(page).not.toContain('Snapshots atuais')
    expect(page).not.toContain('client-section-table-overview')
  })

  it('hub /monitoring expõe atalho Por empresa sem remover módulo-first', () => {
    const hub = readFileSync(root('app/pages/monitoring/index.vue'), 'utf8')
    expect(hub).toContain('monitoring-by-company-entry')
    expect(hub).toContain('Por empresa')
    expect(hub).toContain('to="/clients"')
    expect(hub).toContain('MonitoringInsightsPendingCard')
  })

  it('chrome PGDAS-D enxuto no detalhe do cliente', () => {
    const view = readFileSync(root('app/components/monitoring/PgdasdHistoryView.vue'), 'utf8')
    expect(view).toContain('title="PGDAS-D"')
    expect(view).toContain('Nenhum histórico')
    expect(view).toContain('Sem períodos locais')
    expect(view).not.toContain('Nenhum histórico local')

    const page = readFileSync(root('app/pages/monitoring/clients/[clientId].vue'), 'utf8')
    expect(page).not.toContain('Histórico DAS')
    expect(page).not.toContain('pgdasdDasHistory')
  })

  it('rail do detalhe tem seletor de empresa e seções canônicas', () => {
    const aside = readFileSync(root('app/components/monitoring/ClientFiscalAside.vue'), 'utf8')
    expect(aside).toContain('data-testid="monitoring-client-switcher"')
    expect(aside).toContain('FiscalClientPicker')
    expect(aside).toContain('switchClient')

    const page = readFileSync(root('app/pages/monitoring/clients/[clientId].vue'), 'utf8')
    expect(page).toContain('clientFiscalSwitchPath')
    expect(page).toContain('@switch-client="onSwitchClient"')
    expect(page).toContain('tab === \'dctfweb\'')
    expect(page).toContain('tab === \'mailbox\'')
  })

  it('histórico PGDAS-D usa a grade oficial por PA na página e no modal', () => {
    const view = readFileSync(root('app/components/monitoring/PgdasdHistoryView.vue'), 'utf8')
    const grid = readFileSync(root('app/components/monitoring/pgdasd/PgdasdHistoryPeriodGrid.vue'), 'utf8')
    const dasModal = readFileSync(root('app/components/monitoring/PgdasdDasHistoryModal.vue'), 'utf8')

    expect(view).toContain('data-testid="pgdasd-history-view"')
    expect(view).toContain('data-testid="pgdasd-history-periods"')
    expect(view).toContain('PgdasdHistoryPeriodGrid')
    expect(view).toContain('Buscar documentos')
    expect(view).toContain('documentConfirmOpen')

    expect(grid).toContain('pgdasd-history-period-')
    expect(grid).toContain('data-testid="pgdasd-history-table"')
    expect(grid).toContain('data-testid="pgdasd-history-mobile"')
    expect(grid).toContain('buildPgdasdHistoryOperationRows')
    expect(grid).toContain('Data/hora Transmissão')
    expect(grid).toContain('Data/hora Emissão')
    expect(grid).toContain('Outros documentos')
    expect(grid).toContain('downloadAuthenticated')
    expect(grid).toContain('Sem registros neste PA.')
    expect(grid).toContain('colspan="4"')
    expect(grid).toContain('max-w-full')
    expect(grid).toContain('xl:block')
    expect(grid).toContain('xl:hidden')

    expect(dasModal).not.toContain('MonitoringPgdasdDeclarationsHistoryModal')
    expect(dasModal).not.toContain('pgdasd-das-open-declarations')
    expect(existsSync(root('app/components/monitoring/PgdasdDeclarationsHistoryModal.vue'))).toBe(false)
  })
})

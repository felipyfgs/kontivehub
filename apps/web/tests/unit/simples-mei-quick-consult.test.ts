import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('simples-mei consulta rápida', () => {
  it('toolbar PGDAS-D: Ações só com consulta confirmada; sem membership no menu', () => {
    const src = read('app/components/monitoring/pgdasd/SelectionActions.vue')
    expect(src).toContain('pgdasd-selection-actions-menu')
    expect(src).toContain('aria-label="Ações em massa"')
    expect(src).toContain('enqueueReadUpdate')
    expect(src).toContain('pgdasd-bulk-consult-confirm')
    expect(src).not.toMatch(/Associar clientes|Excluir do monitoramento/)
    expect(src).not.toMatch(/data-testid="pgdasd-bulk-consult"/)
  })

  it('toolbar PGMEI: Ações sem membership direto', () => {
    const src = read('app/components/monitoring/pgmei/BulkActions.vue')
    expect(src).toContain('pgmei-bulk-actions-menu')
    expect(src).toContain('label: \'Solicitar consulta\'')
    expect(src).not.toMatch(/Associar clientes|Excluir do monitoramento/)
  })

  it('página: Associar no botão dedicado; excluir pela linha; rotas SN/MEI desacopladas', () => {
    const page = read('app/components/monitoring/simples-mei/Portfolio.vue')
    expect(page).toContain('testIdPrefix')
    expect(page).toContain('membershipOpen = true')
    expect(page).toContain('MonitoringAssociateMonitoringClientsModal')
    expect(page).toContain('requestExcludeFromMonitoring')
    expect(page).toContain('exclude-confirm')
    expect(page).toContain(':can-consult="canTriggerSync"')
    expect(page).toContain('confirmRowConsult')
    expect(page).toContain('trackConsultPending')
    expect(read('app/pages/monitoring/simples/index.vue')).toContain('submodule="PGDASD"')
    expect(read('app/pages/monitoring/mei/index.vue')).toContain('submodule="PGMEI"')
    expect(read('app/pages/monitoring/simples-mei/index.vue')).toContain('/monitoring/simples')
  })

  it('colunas expõem atalho de consulta por linha', () => {
    expect(read('app/utils/pgdasd-table.ts')).toContain('pgdasd-row-consult')
    expect(read('app/utils/pgmei-table.ts')).toContain('pgmei-row-consult')
  })
})

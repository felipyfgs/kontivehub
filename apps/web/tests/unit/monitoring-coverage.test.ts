import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type {
  MonitoringCoverageOperation,
  MonitoringCoverageSurface
} from '~/types/fiscal-modules'
import {
  filterMonitoringCoverageSurfaces,
  monitoringCoverageOutputLabel,
  monitoringRouteMatches,
  monitoringWorkspaceRequestIsCurrent
} from '~/utils/monitoring-coverage'

function operation(overrides: Partial<MonitoringCoverageOperation> = {}): MonitoringCoverageOperation {
  return {
    action_key: 'consulta_oficial',
    label: 'Consulta oficial',
    route: 'Consultar',
    official_state: 'PRODUCTION',
    is_mutating: false,
    trial_scenario_available: false,
    request_documented: true,
    response_documented: true,
    output_fields: [],
    ...overrides
  }
}

describe('cobertura documental do monitor', () => {
  it('expõe os campos de saída documentados sem inventar conteúdo', () => {
    expect(monitoringCoverageOutputLabel(operation({
      output_fields: [
        { name: 'status', type: 'Number' },
        { name: 'PDFByteArrayBase64', type: 'String' }
      ]
    }))).toBe('status, PDFByteArrayBase64')

    expect(monitoringCoverageOutputLabel(operation({
      response_documented: false
    }))).toBe('Saída ainda não documentada')

    expect(monitoringCoverageOutputLabel(operation()))
      .toBe('Envelope documentado sem campos publicados')
  })

  it('filtra o contrato canônico por rota, inclusive parâmetros dinâmicos', () => {
    const surfaces = [
      { surface_key: 'mailbox_list', route: '/monitoring/mailbox' },
      { surface_key: 'mailbox_detail', route: '/monitoring/mailbox/:id' },
      { surface_key: 'dctfweb', route: '/monitoring/dctfweb' },
      { surface_key: 'mit', route: '/monitoring/dctfweb' }
    ] as MonitoringCoverageSurface[]

    expect(monitoringRouteMatches('/monitoring/mailbox/:id', '/monitoring/mailbox/42?tab=anexos'))
      .toBe(true)
    expect(monitoringRouteMatches('/monitoring/mailbox', '/monitoring/mailbox/42'))
      .toBe(false)
    expect(filterMonitoringCoverageSurfaces(surfaces, { route: '/monitoring/dctfweb' })
      .map(surface => surface.surface_key))
      .toEqual(['dctfweb', 'mit'])
    expect(filterMonitoringCoverageSurfaces(surfaces, { surfaceKeys: ['desconhecida'] }))
      .toEqual([])
  })

  it('descarta respostas de outra sessão ou geração', () => {
    const token = { sessionEpoch: 4, generation: 7 }

    expect(monitoringWorkspaceRequestIsCurrent(token, 4, 7)).toBe(true)
    expect(monitoringWorkspaceRequestIsCurrent(token, 5, 7)).toBe(false)
    expect(monitoringWorkspaceRequestIsCurrent(token, 4, 8)).toBe(false)
  })

  it('mantém o painel de cobertura como componente reutilizável sem coordenadas internas SERPRO', () => {
    const dashboard = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/index.vue'), 'utf8')
    const panel = readFileSync(resolve(process.cwd(), 'app/components/monitoring/SerproCoveragePanel.vue'), 'utf8')

    expect(dashboard).not.toContain('MonitoringSerproCoveragePanel')
    expect(panel).toContain('useMonitoringWorkspace')
    expect(panel).not.toContain('api.fiscal.monitoringCoverage()')
    expect(panel).toContain('Trial valida transporte e schema, não a situação fiscal')
    expect(panel).toContain('surface.capabilities')
    expect(panel).not.toContain('operation_key')
    expect(panel).not.toContain('idSistema')
    expect(panel).not.toContain('idServico')
  })
})

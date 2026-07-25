import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  documentActionVisible,
  type FiscalDocumentDescriptor
} from '~/types/fiscal-modules'
import {
  monitoringFreshnessLabel,
  monitoringQueryStateMeta
} from '~/utils/monitoring-coverage'

function descriptor(overrides: Partial<FiscalDocumentDescriptor> = {}): FiscalDocumentDescriptor {
  return {
    available: true,
    kind: 'PDF',
    label: 'Ver documento oficial',
    content_type: 'application/pdf',
    observed_at: '2026-07-19T12:00:00-03:00',
    source_surface: 'sitfis',
    source_label: 'SITFIS',
    href: '/api/v1/fiscal/evidence/10/download',
    unavailable_reason: null,
    ...overrides
  }
}

describe('fundação visual do workspace fiscal', () => {
  it('mantém estados consultivos semanticamente distintos', () => {
    expect(monitoringQueryStateMeta('READY')).toMatchObject({
      label: 'Resultado disponível',
      color: 'success'
    })
    expect(monitoringQueryStateMeta('NO_DATA')).toMatchObject({
      label: 'Sem dados',
      color: 'neutral'
    })
    expect(monitoringQueryStateMeta('FAILED')).toMatchObject({ color: 'error' })
    expect(monitoringQueryStateMeta('UNSUPPORTED')).toMatchObject({ color: 'neutral' })
    expect(monitoringFreshnessLabel('STALE')).toBe('Observação anterior preservada')
  })

  it('só habilita documento com evidência coerente e href fornecido pelo servidor', () => {
    expect(documentActionVisible(descriptor())).toBe(true)
    expect(documentActionVisible(descriptor({ available: false }))).toBe(false)
    expect(documentActionVisible(descriptor({ href: null }))).toBe(false)
    expect(documentActionVisible(descriptor({ href: '   ' }))).toBe(false)
    expect(documentActionVisible(descriptor({ unavailable_reason: 'INTEGRITY_REJECTED' }))).toBe(false)
  })

  it('injeta cobertura contextual nos shells compartilhados sem gate de papel', () => {
    const moduleTable = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/ModuleTable.vue'),
      'utf8'
    )
    const dashboard = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/index.vue'), 'utf8')
    const mailbox = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/mailbox.vue'), 'utf8')
    const client = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/clients/[clientId].vue'),
      'utf8'
    )

    // Painel de cobertura SERPRO e banner de dados demonstrativos saíram do chrome
    // das superfícies de monitoramento (ruído visual; contrato permanece no componente).
    for (const source of [moduleTable, dashboard, mailbox, client]) {
      expect(source).not.toContain('MonitoringSerproCoveragePanel')
      expect(source).not.toContain('fiscal-synthetic-alert')
      expect(source).not.toContain('dashboard-synthetic-alert')
    }
    expect(moduleTable).not.toContain('fiscal-provenance')
  })

  it('não oferece editor de payload ou renderer de JSON no explorador', () => {
    const explorer = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/ManualConsultExplorer.vue'),
      'utf8'
    )

    expect(explorer).not.toContain('JSON.parse')
    expect(explorer).not.toContain('<pre')
    expect(explorer).toContain('Filtros na tela do módulo')
    expect(explorer).toContain('<MonitoringQueryStateBadge')
  })
})

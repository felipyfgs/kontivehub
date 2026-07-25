import { describe, expect, it } from 'vitest'
import {
  CLIENT_FISCAL_HIDDEN_SECTION_KEYS,
  clientFiscalCanonicalLabels,
  clientFiscalDetailNav,
  clientFiscalSwitchPath,
  isClientFiscalSectionVisible
} from '~/utils/client-fiscal-detail-navigation'
import { buildClientMonitoringOverview } from '~/utils/client-monitoring-overview'
import { MONITORING_NAV_ITEMS } from '~/utils/monitoring-nav'

describe('client-fiscal-rail', () => {
  it('oculta seções internas no catálogo', () => {
    const ids = clientFiscalDetailNav(1).map(i => i.id)
    for (const key of CLIENT_FISCAL_HIDDEN_SECTION_KEYS) {
      expect(ids).not.toContain(`cf-${key}`)
      expect(isClientFiscalSectionVisible(key)).toBe(false)
    }
  })

  it('MEI (ccmei) só com isMei', () => {
    expect(isClientFiscalSectionVisible('ccmei', { isMei: false })).toBe(false)
    expect(isClientFiscalSectionVisible('ccmei', { isMei: true })).toBe(true)
    expect(clientFiscalDetailNav(1, { isMei: false }).map(i => i.id)).not.toContain('cf-ccmei')
    expect(clientFiscalDetailNav(1, { isMei: true }).map(i => i.id)).toContain('cf-ccmei')
  })

  it('labels canônicos espelham MONITORING_NAV_ITEMS (sem MEI)', () => {
    const expected = MONITORING_NAV_ITEMS
      .filter(item => item.moduleKey !== 'mei')
      .map(item => item.label)
    expect(clientFiscalCanonicalLabels({ isMei: false })).toEqual(expected)
  })

  it('com MEI inclui label MEI após Simples Nacional', () => {
    const labels = clientFiscalCanonicalLabels({ isMei: true })
    expect(labels).toEqual(MONITORING_NAV_ITEMS.map(item => item.label))
    expect(labels.indexOf('MEI')).toBe(labels.indexOf('Simples Nacional') + 1)
  })

  it('exibe Cadastro, Processos, DCTFWeb e Caixas; oculta Pendências', () => {
    const ids = clientFiscalDetailNav(1).map(i => i.id)
    expect(ids).toContain('cf-registrations')
    expect(ids).toContain('cf-tax-processes')
    expect(ids).toContain('cf-dctfweb')
    expect(ids).toContain('cf-mailbox')
    expect(ids).not.toContain('cf-pending')
  })

  it('overview lista processos canônicos e MEI só com isMei', () => {
    const sn = buildClientMonitoringOverview(3, [], { isMei: false })
    expect(sn.map(c => c.key)).toContain('registrations')
    expect(sn.map(c => c.key)).toContain('tax_processes')
    expect(sn.map(c => c.key)).toContain('dctfweb')
    expect(sn.map(c => c.key)).toContain('mailbox')
    expect(sn.map(c => c.key)).not.toContain('ccmei')
    expect(sn.map(c => c.label)).toContain('Simples Nacional')
    expect(sn.map(c => c.label)).toContain('Situação Fiscal')
    expect(sn.map(c => c.label)).not.toContain('PGDAS-D')

    const mei = buildClientMonitoringOverview(3, [], { isMei: true })
    expect(mei.map(c => c.key)).toContain('ccmei')
    expect(mei.find(c => c.key === 'ccmei')?.label).toBe('MEI')
  })

  it('troca de empresa preserva seção válida e faz fallback', () => {
    expect(clientFiscalSwitchPath(2, 'guides', { isMei: false }))
      .toBe('/monitoring/clients/2/guides')
    expect(clientFiscalSwitchPath(2, 'ccmei', { isMei: false }))
      .toBe('/monitoring/clients/2')
    expect(clientFiscalSwitchPath(9, 'ccmei', { isMei: true }))
      .toBe('/monitoring/clients/9/ccmei')
    expect(clientFiscalSwitchPath(2, 'pending', { isMei: true }))
      .toBe('/monitoring/clients/2')
  })
})

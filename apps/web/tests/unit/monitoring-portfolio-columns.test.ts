import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  monitoringAssociateClientListFilters,
  monitoringAssociateScopeLabel
} from '~/utils/monitoring-associate-filters'

function readApp(rel: string) {
  return readFileSync(resolve(process.cwd(), rel), 'utf8')
}

/** Extrai ids de coluna na ordem em que aparecem no builder (heurística de source). */
function columnIdsInOrder(source: string): string[] {
  const ids: string[] = []
  const re = /\bid:\s*'([a-z0-9_]+)'/g
  let match: RegExpExecArray | null
  while ((match = re.exec(source)) !== null) {
    ids.push(match[1])
  }
  return ids
}

describe('monitoring-associate-filters', () => {
  it('filtra MEI na carteira PGMEI', () => {
    expect(monitoringAssociateClientListFilters('simples_mei', 'PGMEI')).toEqual({
      is_active: true,
      tax_regimes: 'MEI'
    })
    expect(monitoringAssociateScopeLabel('simples_mei', 'PGMEI')).toContain('MEI')
  })

  it('filtra Simples Nacional na carteira PGDASD', () => {
    expect(monitoringAssociateClientListFilters('simples_mei', 'PGDASD')).toEqual({
      is_active: true,
      tax_regimes: 'SIMPLES_NACIONAL'
    })
  })

  it('não filtra regime em carteiras transversais', () => {
    expect(monitoringAssociateClientListFilters('dctfweb', 'DCTFWEB')).toEqual({
      is_active: true
    })
    expect(monitoringAssociateClientListFilters('sitfis')).toEqual({ is_active: true })
    expect(monitoringAssociateClientListFilters('fgts')).toEqual({ is_active: true })
  })
})

describe('monitoring-table-columns contract (source)', () => {
  const source = readApp('app/utils/monitoring-table-columns.ts')

  it('documenta spine com coluna Comunicação casada', () => {
    expect(source).toContain('buildMonitoringComunicacaoColumn')
    expect(source).toContain('MONITORING_COMUNICACAO_ID')
    expect(source).toContain('Situação · Últ. Declaração')
    expect(source).toContain('Comunicação · Consulta · Ações')
    expect(source).toContain('\'label\': MONITORING_ACTIONS_LABEL')
    expect(source).toContain('\'icon\': \'i-lucide-ellipsis-vertical\'')
    expect(source).toContain('\'variant\': \'subtle\'')
    expect(source).not.toContain('\'square\': true')
    expect(source).not.toContain('\'trailingIcon\': \'i-lucide-chevron-down\'')
    expect(source).toMatch(/MONITORING_SHARED_COLUMN_LABELS[\s\S]*COMUNICACAO_ID[\s\S]*CONSULTED_ID/)
    expect(source).not.toMatch(/MONITORING_SHARED_COLUMN_LABELS[\s\S]*HISTORY_ID/)
    expect(source).not.toContain('buildMonitoringEnvioAndTrackingColumns')
    expect(source).not.toContain('Hist. comunicação')
  })
})

describe('portfolio column order (source)', () => {
  it('DCTFWeb começa Situação · Últ. Declaração · Cliente', () => {
    const source = readApp('app/utils/dctfweb-table.ts')
    const dctfFn = source.slice(
      source.indexOf('export function buildDctfwebColumns'),
      source.indexOf('export function buildMitColumns')
    )
    const ids = columnIdsInOrder(dctfFn)
    expect(ids.slice(0, 3)).toEqual(['situation', 'last_declaration', 'client'])
    expect(ids).toContain('actions')
    expect(dctfFn).toContain('buildMonitoringComunicacaoColumn')
    expect(dctfFn).not.toContain('communication-info')
    expect(dctfFn).toContain('Editar cliente')
  })

  it('PGDAS mantém Situação · Declaração · RBT12 · Cliente e termina em Ações (sem Pagamento)', () => {
    const source = readApp('app/utils/pgdasd-table.ts')
    const ids = columnIdsInOrder(source)
    expect(ids.slice(0, 4)).toEqual(['situation', 'last_declaration', 'rbt12', 'client'])
    expect(ids.at(-1)).toBe('actions')
    expect(source.indexOf('buildMonitoringComunicacaoColumn'))
      .toBeLessThan(source.indexOf('MONITORING_CONSULTED_ID'))
    expect(source.indexOf('MONITORING_CONSULTED_ID'))
      .toBeLessThan(source.lastIndexOf('id: \'actions\''))
    expect(ids).not.toContain('payment')
    expect(source).toContain('buildMonitoringComunicacaoColumn')
    expect(source).not.toContain('communication-info')
    expect(source).toContain('Editar cliente')
    expect(source).toContain('sortHeader(\'Situação\'')
    expect(source).toContain('sortHeader(\'Declaração\'')
    expect(source).toContain('sortHeader(\'RBT12\'')
    expect(source).not.toContain('sortHeader(\'Pagamento\'')
    expect(source).toContain('sortHeader(\'Cliente\'')
    expect(source).toContain('PaymentValue')
    expect(source).toContain('Pagamento dos DAS do período de apuração esperado')
  })

  it('PGMEI e MIT usam Comunicação e Editar cliente no ⋮', () => {
    const pgmei = readApp('app/utils/pgmei-table.ts')
    const dctf = readApp('app/utils/dctfweb-table.ts')
    expect(pgmei).toContain('buildMonitoringComunicacaoColumn')
    expect(pgmei).toContain('Editar cliente')
    expect(pgmei).not.toContain('communication-info')
    expect(dctf).toContain('export function buildMitColumns')
    expect(dctf).toContain('Editar cliente')
    expect(dctf).toContain('testIdPrefix: \'mit-tracking\'')
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  monitoringSendStatusFilterItems,
  normalizeMonitoringFilters,
  hasActiveMonitoringFilters
} from '~/utils/monitoring-filters'

describe('simples-nacional-portfolio-filters', () => {
  it('exporta itens Enviado / Não enviado', () => {
    expect(monitoringSendStatusFilterItems()).toEqual([
      { label: 'Enviado', value: 'sent' },
      { label: 'Não enviado', value: 'not_sent' }
    ])
  })

  it('normaliza e detecta sendStatus ativo', () => {
    const empty = normalizeMonitoringFilters({})
    expect(empty.sendStatus).toBe('all')
    expect(hasActiveMonitoringFilters(empty)).toBe(false)

    const active = normalizeMonitoringFilters({ sendStatus: 'sent' })
    expect(active.sendStatus).toBe('sent')
    expect(hasActiveMonitoringFilters(active)).toBe(true)
  })

  it('PGDASD declara Situação · Cliente · Competência · Envio; MEI só Cliente', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/simples-mei/Portfolio.vue'),
      'utf8'
    )
    expect(source).toContain('key: \'situation\'')
    expect(source).toContain('key: \'sendStatus\'')
    expect(source).toContain('label: \'Envio\'')
    expect(source).toContain('value: \'sent\'')
    expect(source).toContain('value: \'not_sent\'')

    // Ramo MEI continua só com Cliente (sem Situação/Envio no bloco PGMEI).
    const meiBlock = source.slice(
      source.indexOf('if (isPgmei.value)'),
      source.indexOf('return {\n    fields: [\n      { key: \'situation\'')
    )
    expect(meiBlock).toContain('key: \'clientId\'')
    expect(meiBlock).not.toContain('key: \'situation\'')
    expect(meiBlock).not.toContain('key: \'sendStatus\'')
  })

  it('composable mapeia send_status na query e no buildFilters', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/composables/useFiscalModulePortfolio.ts'),
      'utf8'
    )
    expect(source).toContain('send_status')
    expect(source).toContain('sendStatus')
  })
})

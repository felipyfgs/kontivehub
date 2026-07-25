import { describe, expect, it } from 'vitest'
import type { SimplesMeiClientRow } from '~/types/fiscal-modules'
import { simplesMeiMissingProcuracaoSituation } from '~/utils/simples-mei-situation'

function row(procuracaoStatus: string | null | undefined): SimplesMeiClientRow {
  return {
    client_id: 1,
    legal_name: 'Cliente Teste',
    cnpj_masked: '**.***.***/****-**',
    situation: 'UNKNOWN',
    detail: {
      module_key: 'simples_mei',
      declaration_state: 'UNVERIFIED',
      procuracao_status: procuracaoStatus
    }
  } as SimplesMeiClientRow
}

describe('simplesMeiMissingProcuracaoSituation', () => {
  it('returns Sem procuração when status is missing', () => {
    const override = simplesMeiMissingProcuracaoSituation(row('missing'), 'pgdasd-situation')
    expect(override?.label).toBe('Sem procuração')
    expect(override?.testId).toBe('pgdasd-situation')
  })

  it('returns null for authorized or unverified', () => {
    expect(simplesMeiMissingProcuracaoSituation(row('authorized'), 'pgdasd-situation')).toBeNull()
    expect(simplesMeiMissingProcuracaoSituation(row('unverified'), 'pgdasd-situation')).toBeNull()
    expect(simplesMeiMissingProcuracaoSituation(row(null), 'pgdasd-situation')).toBeNull()
  })
})

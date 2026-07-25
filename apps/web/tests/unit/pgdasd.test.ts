import { describe, expect, it } from 'vitest'
import {
  pgdasdDasPaymentMeta,
  pgdasdDeclarationMeta,
  pgdasdDeclarationState,
  pgdasdHistoryCalendarYears,
  pgdasdPaymentDetailItems,
  pgdasdRbt12DetailItems,
  pgdasdRbt12UnavailableLabel
} from '~/utils/pgdasd'

describe('pgdasd utils', () => {
  it('maps known declaration states', () => {
    expect(pgdasdDeclarationState('CURRENT')).toBe('CURRENT')
    expect(pgdasdDeclarationState('DUE_WITHIN_DEADLINE')).toBe('DUE_WITHIN_DEADLINE')
    expect(pgdasdDeclarationState('OVERDUE_NOT_FOUND')).toBe('OVERDUE_NOT_FOUND')
    expect(pgdasdDeclarationState('UNVERIFIED')).toBe('UNVERIFIED')
  })

  it('labels entrega do PA esperado', () => {
    expect(pgdasdDeclarationMeta('CURRENT').label).toBe('Em dia')
    expect(pgdasdDeclarationMeta('DUE_WITHIN_DEADLINE').label).toBe('No prazo')
    expect(pgdasdDeclarationMeta('OVERDUE_NOT_FOUND').label).toBe('Atrasado')
    expect(pgdasdDeclarationMeta('UNVERIFIED').label).toBe('Não verificado')
  })

  it('labels pagamento dos DAS do PA esperado (meta interna; UI colapsa sem evidência)', () => {
    expect(pgdasdDasPaymentMeta('PAID').label).toBe('Em dia')
    expect(pgdasdDasPaymentMeta('UNPAID').label).toBe('Pendências')
    expect(pgdasdDasPaymentMeta('NO_DAS').label).toBe('Sem movimento')
    expect(pgdasdDasPaymentMeta('NO_DAS').description).toBe('Nenhum DAS gerado no período.')
    expect(pgdasdDasPaymentMeta('UNVERIFIED').label).toBe('Não verificado')
    expect(pgdasdDasPaymentMeta('nope').label).toBe('Não verificado')
  })

  it('detalhe Pagamento unpaid lista competências com valor em cada linha', () => {
    const items = pgdasdPaymentDetailItems({
      expected_period_key: '2026-06',
      payment_state: 'UNPAID',
      payment_state_reason: 'DAS_PAYMENT_NOT_LOCATED',
      payment_das_count: 2,
      payment_paid_count: 1,
      payment_unpaid_count: 1,
      payment_open_competencies: [
        { period_key: '2026-06', amount_cents: 15000 },
        { period_key: '2026-05', amount_cents: null }
      ]
    })
    expect(items.map(i => i.label)).toEqual(['06/2026', '05/2026'])
    expect(items.find(i => i.label === 'Situação')).toBeUndefined()
    expect(items.find(i => i.label === '06/2026')?.value).toMatch(/R\$\s*150,00/)
    expect(items.find(i => i.label === '06/2026')?.isDebit).toBe(true)
    expect(items.find(i => i.label === '05/2026')?.value).toBe('—')
    expect(items.find(i => i.label === '05/2026')?.isDebit).toBe(false)
    expect(items.some(i => i.value.includes('DAS_PAYMENT'))).toBe(false)
    expect(items.some(i => i.label === 'DAS no PA')).toBe(false)
  })

  it('detalhe Pagamento pago mostra Em dia sem contagens', () => {
    const meta = pgdasdDasPaymentMeta('PAID')
    expect(meta.label).toBe('Em dia')
    expect(meta.color).toBe('success')
    expect(meta.description).toBe('Pagamento localizado.')
    expect(meta.description).not.toContain('período esperado')

    const items = pgdasdPaymentDetailItems({
      payment_state: 'PAID',
      payment_state_reason: 'DAS_PAYMENT_LOCATED',
      payment_das_count: 1,
      payment_paid_count: 1,
      payment_unpaid_count: 0
    })
    expect(items.map(i => i.label)).toEqual(['Situação', 'Detalhe'])
    expect(items[0]?.value).toBe('Em dia')
    expect(items[1]?.value).toBe('Pagamento localizado.')
    expect(items[1]?.value).not.toContain('DAS_PAYMENT')
    expect(items[1]?.value).not.toContain('Pagamento do DAS do período esperado')
    expect(items.some(i => i.label === 'DAS no PA')).toBe(false)
  })

  it('meta NO_DAS usa Sem movimento com detalhe curto', () => {
    const meta = pgdasdDasPaymentMeta('NO_DAS')
    expect(meta.label).toBe('Sem movimento')
    expect(meta.description).toBe('Nenhum DAS gerado no período.')
    expect(meta.description).not.toContain('Consulta válida')

    const items = pgdasdPaymentDetailItems({
      payment_state: 'NO_DAS',
      payment_state_reason: 'NO_DAS_IN_EXPECTED_PERIOD'
    })
    expect(items.map(i => i.value)).toEqual(['Sem movimento', 'Nenhum DAS gerado no período.'])
  })

  it('falls back to UNVERIFIED for unknown values', () => {
    expect(pgdasdDeclarationState('nope')).toBe('UNVERIFIED')
    expect(pgdasdDeclarationState(null)).toBe('UNVERIFIED')
    expect(pgdasdDeclarationState(undefined)).toBe('UNVERIFIED')
  })

  it('monta anos-calendário do histórico com ano corrente e period_key', () => {
    const now = new Date('2026-07-20T12:00:00Z')
    expect(pgdasdHistoryCalendarYears({
      periods: [
        { period_key: '2025-12' },
        { period_key: '2024-01' },
        { period_key: 'invalid' }
      ]
    }, [2023], now)).toEqual([2026, 2025, 2024, 2023])
  })

  it('detalhe RBT12 monta lista enxuta com composição e RPA', () => {
    const items = pgdasdRbt12DetailItems({
      status: 'PARSED',
      total_cents: 1_000_000,
      internal_market_cents: 900_000,
      external_market_cents: 100_000,
      rpa_cents: 150_000,
      period_key: '2026-05',
      origin: { das_number: '07202617423291033' }
    })
    expect(items.map(i => i.label)).toEqual([
      'RBT12',
      'Mercado interno',
      'Mercado externo',
      'RPA',
      'PA'
    ])
    expect(items.find(i => i.label === 'RBT12')?.value).toBeTruthy()
    expect(pgdasdRbt12UnavailableLabel('EXACT_RBT12_VALUE_NOT_FOUND')).toContain('localizar o RBT12')
  })
})

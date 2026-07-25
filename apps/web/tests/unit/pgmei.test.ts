import { describe, expect, it } from 'vitest'
import { pgmeiDebtMeta, pgmeiDebtState, pgmeiFreshnessState } from '~/utils/pgmei'

describe('pgmei utils', () => {
  it('normalizes debt state', () => {
    expect(pgmeiDebtState('HAS_ACTIVE_DEBT')).toBe('HAS_ACTIVE_DEBT')
    expect(pgmeiDebtState('NO_ACTIVE_DEBT')).toBe('NO_ACTIVE_DEBT')
    expect(pgmeiDebtState('anything')).toBe('UNVERIFIED')
    expect(pgmeiDebtState(null)).toBe('UNVERIFIED')
  })

  it('maps freshness state', () => {
    expect(pgmeiFreshnessState('CURRENT')).toBe('CURRENT')
    expect(pgmeiFreshnessState('current')).toBe('CURRENT')
    expect(pgmeiFreshnessState('OLD')).toBe('OUTDATED')
    expect(pgmeiFreshnessState(undefined)).toBe('OUTDATED')
  })

  it('returns debt meta for known states', () => {
    expect(pgmeiDebtMeta('HAS_ACTIVE_DEBT').label).toBe('Dívida ativa')
    expect(pgmeiDebtMeta('NO_ACTIVE_DEBT').color).toBe('success')
    expect(pgmeiDebtMeta('UNVERIFIED').icon).toContain('circle-help')
  })
})

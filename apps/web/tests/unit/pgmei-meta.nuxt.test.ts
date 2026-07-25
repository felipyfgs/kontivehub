import { describe, expect, it } from 'vitest'
import { pgmeiDebtMeta } from '~/utils/pgmei'

describe('pgmeiDebtMeta (nuxt)', () => {
  it('imports and resolves meta in nuxt environment', () => {
    const meta = pgmeiDebtMeta('NO_ACTIVE_DEBT')
    expect(meta.label).toBe('Sem dívida no ano')
    expect(meta.color).toBe('success')
  })
})

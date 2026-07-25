import { describe, expect, it } from 'vitest'
import { SERPRO_NAV_ITEMS } from '~/utils/serpro-navigation'

describe('SERPRO_NAV_ITEMS (nuxt)', () => {
  it('imports navigation catalog in nuxt environment', () => {
    expect(SERPRO_NAV_ITEMS.length).toBeGreaterThanOrEqual(2)
    expect(SERPRO_NAV_ITEMS.some(item => item.to === '/admin/serpro/configuration')).toBe(true)
  })
})

import { describe, expect, it } from 'vitest'
import { SERPRO_NAV_ITEMS } from '~/utils/serpro-navigation'

describe('serpro-navigation', () => {
  it('exposes at least two nav items', () => {
    expect(SERPRO_NAV_ITEMS.length).toBeGreaterThanOrEqual(2)
  })

  it('marks configuration path active on configuration item', () => {
    const config = SERPRO_NAV_ITEMS.find(item => item.id === 'serpro-configuration')
    expect(config).toBeTruthy()
    expect(config?.isActive?.('/admin/serpro/configuration')).toBe(true)
    expect(config?.isActive?.('/admin/serpro/contracts')).toBe(true)
    expect(config?.to).toBe('/admin/serpro/configuration')
  })
})

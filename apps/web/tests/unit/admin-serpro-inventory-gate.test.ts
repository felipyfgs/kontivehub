import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { SERPRO_NAV_ITEMS } from '~/utils/serpro-navigation'

describe('admin/serpro inventory gate', () => {
  it('lists configuration page in inventory and SERPRO_NAV_ITEMS', () => {
    const pages = JSON.parse(
      readFileSync(resolve(__dirname, '../fixtures/surface-inventory/web-pages.json'), 'utf8')
    ) as Array<{ route?: string }>

    expect(pages.some(page => page.route === '/admin/serpro/configuration')).toBe(true)
    expect(SERPRO_NAV_ITEMS.some(item => item.to === '/admin/serpro/configuration')).toBe(true)
  })
})

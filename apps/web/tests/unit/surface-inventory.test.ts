import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const fixturesDir = resolve(__dirname, '../fixtures/surface-inventory')
const pagesRoot = resolve(__dirname, '../../app/pages')

function listVuePages(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      out.push(...listVuePages(full))
    } else if (entry.endsWith('.vue')) {
      out.push(full)
    }
  }
  return out
}

describe('surface inventory (web)', () => {
  it('page count matches summary.pagesTotal', () => {
    const summary = JSON.parse(readFileSync(join(fixturesDir, 'summary.json'), 'utf8')) as {
      pagesTotal: number
      pagesRedirectOnly: number
    }
    const pages = listVuePages(pagesRoot)
    expect(pages.length).toBe(summary.pagesTotal)
  })

  it('inventory files match the complete page file set', () => {
    const inventory = JSON.parse(readFileSync(join(fixturesDir, 'web-pages.json'), 'utf8')) as Array<{
      file: string
      route: string
    }>
    const liveFiles = listVuePages(pagesRoot)
      .map(file => `app/pages/${file.slice(pagesRoot.length + 1)}`)
      .sort()
    const inventoryFiles = inventory.map(page => page.file).sort()

    expect(inventoryFiles).toEqual(liveFiles)
    expect(inventory.every(page => page.route.startsWith('/'))).toBe(true)
  })

  it('redirect-only notes count matches summary.pagesRedirectOnly', () => {
    const summary = JSON.parse(readFileSync(join(fixturesDir, 'summary.json'), 'utf8')) as {
      pagesRedirectOnly: number
    }
    const pages = JSON.parse(readFileSync(join(fixturesDir, 'web-pages.json'), 'utf8')) as Array<{
      notes?: string
      redirectOnly?: boolean
    }>
    const redirectCount = pages.filter((page) => {
      if (typeof page.redirectOnly === 'boolean') return page.redirectOnly
      return String(page.notes || '').toLowerCase().includes('redirect-only')
    }).length
    expect(redirectCount).toBe(summary.pagesRedirectOnly)
  })
})

import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

interface Inventory {
  schemaVersion: number
  scope: unknown
  digest: string
  summary: unknown
  files: Array<{ path: string }>
  symbols: Array<{ id: string }>
}

interface Ledger {
  inventoryDigest: string
  entries: Array<{ symbolId: string }>
}

interface Summary {
  inventoryDigests: { web: string }
}

const webRoot = resolve(__dirname, '../..')
const artifactRoot = process.env.CODE_QUALITY_ARTIFACT_ROOT
  || resolve(webRoot, '../../apps/api/resources/code-quality/artifacts')

function readJson<T>(path: string): T {
  return JSON.parse(readFileSync(path, 'utf8')) as T
}

describe('canonical code-quality artifacts', () => {
  it('binds the web inventory, ledger and mirrored summary', () => {
    const inventory = readJson<Inventory>(resolve(artifactRoot, 'web/inventory.json'))
    const ledger = readJson<Ledger>(resolve(artifactRoot, 'web/ledger.json'))
    const apiSummaryText = readFileSync(resolve(artifactRoot, 'api/summary.json'), 'utf8')
    const webSummaryText = readFileSync(resolve(artifactRoot, 'web/summary.json'), 'utf8')
    const summary = JSON.parse(webSummaryText) as Summary
    const { digest, ...core } = inventory

    expect(createHash('sha256').update(JSON.stringify(core)).digest('hex')).toBe(digest)
    expect(ledger.inventoryDigest).toBe(digest)
    expect(apiSummaryText).toBe(webSummaryText)
    expect(summary.inventoryDigests.web).toBe(digest)

    const symbolIds = inventory.symbols.map(symbol => symbol.id)
    const ledgerIds = ledger.entries.map(entry => entry.symbolId)
    expect(new Set(symbolIds).size).toBe(symbolIds.length)
    expect(ledgerIds).toEqual(symbolIds)
  })

  it('contains every current Nuxt page in the complete file inventory', () => {
    const inventory = readJson<Inventory>(resolve(artifactRoot, 'web/inventory.json'))
    const inventoryPaths = new Set(
      inventory.files.map(file => file.path)
    )
    const pages = JSON.parse(
      readFileSync(resolve(webRoot, 'tests/fixtures/surface-inventory/web-pages.json'), 'utf8')
    ) as Array<{ file: string }>

    expect(pages.every(page => inventoryPaths.has(`apps/web/${page.file}`))).toBe(true)
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  buildWebInventory,
  collectWebSource,
  inventoryDrift
} from '../../scripts/lib/code-quality-inventory.mjs'

const webRoot = resolve(__dirname, '../..')
const fixtureRoot = resolve(__dirname, '../fixtures/code-quality')

function fixture(name: string): string {
  return readFileSync(resolve(fixtureRoot, name), 'utf8')
}

describe('code-quality web inventory', () => {
  it('collects SFC script setup symbols with global lines and metrics', () => {
    const result = collectWebSource(
      fixture('component-valid.vue.fixture'),
      'apps/web/app/components/AuditFixture.vue'
    )

    expect(result.parseErrors).toEqual([])
    const submit = result.symbols.find(symbol => symbol.displayName === 'submit')
    expect(submit).toMatchObject({
      kind: 'arrow-function',
      language: 'vue',
      startLine: 13,
      parameters: [expect.objectContaining({ name: 'force', optional: true })],
      metrics: expect.objectContaining({ branches: 2, complexity: 3, importFanOut: 1 })
    })
  })

  it('collects composable declarations and nested callbacks with parents', () => {
    const result = collectWebSource(
      fixture('composable-valid.ts.fixture'),
      'apps/web/app/composables/useCounter.ts'
    )

    expect(result.parseErrors).toEqual([])
    const composable = result.symbols.find(symbol => symbol.displayName === 'useCounter')
    const normalize = result.symbols.find(symbol => symbol.displayName === 'normalize')
    const callback = result.symbols.find(symbol => symbol.displayName.startsWith('arrow-function#'))
    expect(composable).toMatchObject({ kind: 'function', parentId: null })
    expect(normalize).toMatchObject({ kind: 'arrow-function', parentId: composable?.id })
    const normalizeCallback = result.symbols.find(symbol => symbol.parentId === normalize?.id)
    expect(callback).toBeDefined()
    expect(normalizeCallback).toMatchObject({ kind: 'arrow-function', parentId: normalize?.id })
    expect(result.symbols.every(symbol => /^[a-f0-9]{64}$/.test(symbol.fingerprint))).toBe(true)
  })

  it('collects TypeScript interfaces, classes, methods and functions', () => {
    const result = collectWebSource(
      fixture('typescript-valid.ts.fixture'),
      'apps/web/app/utils/runner.ts'
    )

    expect(result.parseErrors).toEqual([])
    expect(result.symbols.map(symbol => symbol.kind).sort()).toEqual([
      'class',
      'function',
      'interface',
      'method',
      'method',
      'method'
    ])
    expect(result.symbols.find(symbol => symbol.qualifiedName === 'Runner::run')?.metrics.branches).toBe(1)
  })

  it('keeps executable files without declarations in the inventory', () => {
    const result = collectWebSource(
      fixture('no-symbol.ts.fixture'),
      'apps/web/app/types/version.ts'
    )

    expect(result).toEqual({ symbols: [], parseErrors: [] })
  })

  it('reports invalid syntax without partial symbols', () => {
    const result = collectWebSource(
      fixture('typescript-invalid.ts.fixture'),
      'apps/web/app/utils/invalid.ts'
    )

    expect(result.symbols).toEqual([])
    expect(result.parseErrors.length).toBeGreaterThan(0)
    expect(result.parseErrors[0]).toEqual(
      expect.objectContaining({ language: 'typescript', line: 1 })
    )
  })

  it('builds a deterministic file inventory from canonical paths', () => {
    const paths = [
      'apps/web/scripts/generate-code-quality-inventory.mjs',
      'apps/web/scripts/lib/code-quality-inventory.mjs'
    ]
    const first = buildWebInventory(webRoot, paths)
    const second = buildWebInventory(webRoot, [...paths].reverse())

    expect(second).toEqual(first)
    expect(first.summary).toMatchObject({ files: 2, parseErrors: 0, byApp: { web: 2 } })
    expect(first.files.map(file => file.path)).toEqual([...paths].sort())
    expect(first.symbols.length).toBeGreaterThan(5)
  })

  it('detects replacement with unchanged aggregate counts', () => {
    const expected = {
      scope: { command: 'scope' },
      files: [{ path: 'apps/web/app/old.ts', sha256: 'a', category: 'application', language: 'typescript' }],
      symbols: [{ id: 'old@1', path: 'apps/web/app/old.ts', qualifiedName: 'old', kind: 'function', fingerprint: 'a' }]
    }
    const current = {
      scope: { command: 'scope' },
      files: [{ path: 'apps/web/app/new.ts', sha256: 'b', category: 'application', language: 'typescript' }],
      symbols: [{ id: 'new@1', path: 'apps/web/app/new.ts', qualifiedName: 'new', kind: 'function', fingerprint: 'b' }]
    }

    expect(inventoryDrift(expected, current)).toMatchObject({
      missingFiles: ['apps/web/app/old.ts'],
      unexpectedFiles: ['apps/web/app/new.ts'],
      missingSymbols: ['old@1'],
      unexpectedSymbols: ['new@1']
    })
  })

  it('does not scan files outside the canonical path input', () => {
    const inventory = buildWebInventory(webRoot, [
      'apps/web/scripts/generate-code-quality-inventory.mjs'
    ])

    expect(inventory.files.map(file => file.path)).toEqual([
      'apps/web/scripts/generate-code-quality-inventory.mjs'
    ])
  })
})

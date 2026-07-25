import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'

type JsonRecord = Record<string, unknown>

function objectAt(value: unknown, ...keys: string[]): JsonRecord {
  let cursor = value
  for (const key of keys) {
    if (!cursor || typeof cursor !== 'object' || Array.isArray(cursor)) {
      throw new TypeError(`Expected object before ${key}`)
    }
    cursor = (cursor as JsonRecord)[key]
  }
  if (!cursor || typeof cursor !== 'object' || Array.isArray(cursor)) {
    throw new TypeError(`Expected object at ${keys.join('.')}`)
  }
  return cursor as JsonRecord
}

describe('code-quality audit schema', () => {
  let schema: JsonRecord

  beforeAll(() => {
    const path = process.env.CODE_QUALITY_SCHEMA_PATH
      || resolve(__dirname, '../../../..', 'apps/api/resources/code-quality/schema.json')
    schema = JSON.parse(readFileSync(path, 'utf8')) as JsonRecord
  })

  it('declares every versioned artifact consumed by the two apps', () => {
    for (const artifact of ['inventory', 'ledger', 'findings', 'summaryMirror']) {
      expect(objectAt(schema, '$defs', artifact, 'properties', 'schemaVersion').const).toBe(1)
    }
  })

  it('keeps review states, severities and executable languages closed', () => {
    expect(objectAt(schema, '$defs', 'reviewStatus').enum).toEqual([
      'pending',
      'reviewed-no-finding',
      'reviewed-with-findings',
      'excluded-with-reason'
    ])
    expect(objectAt(schema, '$defs', 'finding', 'properties', 'severity').enum).toEqual(['P0', 'P1', 'P2', 'P3'])
    expect(objectAt(schema, '$defs', 'language').enum).toEqual(expect.arrayContaining([
      'php',
      'typescript',
      'javascript',
      'vue',
      'python'
    ]))
  })

  it('uses the exact Git scope and excludes generated dependency trees', () => {
    expect(objectAt(schema, '$defs', 'scope', 'properties', 'command').const).toBe(
      'git ls-files --cached --others --exclude-standard apps/api apps/web'
    )
    const excludedPattern = objectAt(schema, '$defs', 'relativePath', 'not').pattern
    expect(excludedPattern).toEqual(expect.stringContaining('vendor'))
    expect(excludedPattern).toEqual(expect.stringContaining('node_modules'))
    expect(excludedPattern).toEqual(expect.stringContaining('\\.output'))
  })

  it('binds both app digests in the mirrored summary', () => {
    const summary = objectAt(schema, '$defs', 'summaryMirror')
    const properties = objectAt(summary, 'properties')
    const inventoryDigests = objectAt(properties, 'inventoryDigests')

    expect(summary.required).toEqual(expect.arrayContaining(['inventoryDigests']))
    expect(inventoryDigests.required).toEqual(['api', 'web'])
    expect(inventoryDigests.additionalProperties).toBe(false)
  })
})

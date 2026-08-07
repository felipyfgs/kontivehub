import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const matrix = readFileSync(
  resolve(process.cwd(), 'tests/fixtures/template-parity-matrix.md'),
  'utf8'
)

const acceptedArchetypes = new Set([
  'casca global',
  'analítica',
  'lista administrativa',
  'master-detail',
  'configurações/formulários',
  'autenticação'
])

function rowsAfter(heading: string) {
  const start = matrix.indexOf(heading)
  const nextHeading = matrix.indexOf('\n## ', start + heading.length)
  const section = matrix.slice(start, nextHeading === -1 ? undefined : nextHeading)
  return section.split('\n').filter(line => line.startsWith('| `pages/'))
}

describe('baseline de padronização do frontend', () => {
  it('mantém 83 superfícies e um arquétipo explícito por superfície', () => {
    const routes = rowsAfter('# Matriz de paridade estrutural')
    const archetypes = rowsAfter('## Arquétipos por rota')
    const routeFiles = routes.map(row => row.match(/^\| `(pages\/[^`]+)`/)?.[1])
    const archetypeFiles = archetypes.map(row => row.match(/^\| `(pages\/[^`]+)`/)?.[1])

    expect(routes).toHaveLength(83)
    expect(archetypes).toHaveLength(83)
    expect(archetypeFiles).toEqual(routeFiles)

    for (const row of archetypes) {
      const archetype = row.match(/\| `([^`]+)` \|$/)?.[1]
      expect(acceptedArchetypes.has(archetype ?? '')).toBe(true)
    }
  })
})

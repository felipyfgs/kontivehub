import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const webRoot = resolve(__dirname, '../..')
const repoRoot = resolve(webRoot, '../..')
const fixtureDir = resolve(__dirname, '../fixtures/use-case-testability')
const graphPath = resolve(fixtureDir, 'graph.json')
const catalogPath = resolve(fixtureDir, 'catalog.json')

type Evidence = { level: string, file: string, anchor: string, journeyId: string }
type GraphNode = { id: string, type: string, journeyId?: string, critical?: boolean, file?: string }

function listVuePages(root: string): string[] {
  return readdirSync(root).flatMap((entry) => {
    const absolute = join(root, entry)
    if (statSync(absolute).isDirectory()) return listVuePages(absolute)
    return entry.endsWith('.vue') ? [absolute] : []
  })
}

function readGraph() {
  return JSON.parse(readFileSync(graphPath, 'utf8')) as {
    digest: string
    summary: { apiRoutes: number, pages: number, unmatchedApiClientEndpoints: number }
    evidence: Evidence[]
    nodes: GraphNode[]
    edges: Array<{ from: string, to: string, type: string }>
  } & Record<string, unknown>
}

describe('use-case-testability-graph', () => {
  it('is deterministic and identical to the API peer snapshot when available', () => {
    execFileSync(process.execPath, [
      resolve(webRoot, 'scripts/generate-use-case-testability.mjs'),
      '--check'
    ], { cwd: webRoot })

    const graph = readGraph()
    const { digest, ...core } = graph
    expect(createHash('sha256').update(JSON.stringify(core)).digest('hex')).toBe(digest)

    const apiPeer = resolve(repoRoot, 'apps/api/tests/fixtures/use-case-testability/graph.json')
    if (existsSync(apiPeer)) expect(readFileSync(apiPeer, 'utf8')).toBe(readFileSync(graphPath, 'utf8'))
  })

  it('classifies every current Nuxt page without orphan graph edges', () => {
    const graph = readGraph()
    const pageNodes = graph.nodes.filter(node => node.type === 'page') as Array<GraphNode & { file: string }>
    const pagesRoot = resolve(webRoot, 'app/pages')
    const currentPages = listVuePages(pagesRoot)
      .map(file => `app/pages/${file.slice(pagesRoot.length + 1)}`)
      .sort()
    expect(pageNodes.map(node => node.file).sort()).toEqual(currentPages)
    expect(pageNodes.every(node => Boolean(node.journeyId))).toBe(true)
    expect(graph.summary.pages).toBe(pageNodes.length)

    const nodeIds = new Set(graph.nodes.map(node => node.id))
    expect(nodeIds.size).toBe(graph.nodes.length)
    expect(graph.edges.every(edge => nodeIds.has(edge.from) && nodeIds.has(edge.to))).toBe(true)
    expect(graph.summary.unmatchedApiClientEndpoints).toBe(0)
  })

  it('requires real L1-L3 evidence and anchors for every critical journey', () => {
    const graph = readGraph()
    const catalog = JSON.parse(readFileSync(catalogPath, 'utf8')) as {
      journeys: Array<{ id: string, critical: boolean }>
    }
    const critical = catalog.journeys.filter(journey => journey.critical)
    expect(critical).toHaveLength(4)

    for (const journey of critical) {
      const evidence = graph.evidence.filter(item => item.journeyId === journey.id)
      expect([...new Set(evidence.map(item => item.level))].sort()).toEqual(['L1', 'L2', 'L3'])
    }

    for (const evidence of graph.evidence) {
      const path = resolve(repoRoot, evidence.file)
      if (!existsSync(path)) continue
      expect(readFileSync(path, 'utf8'), `${evidence.file} sem ${evidence.anchor}`)
        .toContain(evidence.anchor)
    }
  })
})

import {
  existsSync,
  mkdirSync,
  readFileSync,
  writeFileSync
} from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  buildGraph as createGraph,
  listVuePages,
  reportFor,
  routeInventoryFromLive,
  summaryFor
} from './lib/use-case-testability.mjs'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const webRoot = resolve(scriptDir, '..')
const repoRoot = resolve(webRoot, '../..')
const webSurfaceDir = resolve(webRoot, 'tests/fixtures/surface-inventory')
const webGraphDir = resolve(webRoot, 'tests/fixtures/use-case-testability')
const apiSurfaceDir = resolve(repoRoot, 'apps/api/tests/fixtures/surface-inventory')
const apiGraphDir = resolve(repoRoot, 'apps/api/tests/fixtures/use-case-testability')
const reportPath = resolve(webGraphDir, 'use-case-graph.md')

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'))
}

function jsonText(value) {
  return `${JSON.stringify(value, null, 2)}\n`
}

function writeText(path, value) {
  mkdirSync(dirname(path), { recursive: true })
  writeFileSync(path, value)
}

export function buildGraph(inputs) {
  return createGraph({ ...inputs, webRoot })
}

export function currentPageFiles() {
  const pagesRoot = resolve(webRoot, 'app/pages')
  return listVuePages(pagesRoot).map(file => `app/pages/${file}`)
}

function loadInputs() {
  return {
    catalog: readJson(resolve(webGraphDir, 'catalog.json')),
    apiRoutes: readJson(resolve(webSurfaceDir, 'api-routes.json')),
    pages: readJson(resolve(webSurfaceDir, 'web-pages.json'))
  }
}

function refreshSurfaceInventory(liveRoutesPath) {
  const pages = readJson(resolve(webSurfaceDir, 'web-pages.json'))
  const seedPath = existsSync(resolve(webSurfaceDir, 'api-routes.json'))
    ? resolve(webSurfaceDir, 'api-routes.json')
    : resolve(apiSurfaceDir, 'api-routes.json')
  const existing = readJson(seedPath)
  const apiRoutes = liveRoutesPath
    ? routeInventoryFromLive(readJson(liveRoutesPath), existing)
    : existing
  const summary = summaryFor(apiRoutes, pages)

  writeText(resolve(webSurfaceDir, 'api-routes.json'), jsonText(apiRoutes))
  writeText(resolve(webSurfaceDir, 'summary.json'), jsonText(summary))
  if (existsSync(resolve(repoRoot, 'apps/api'))) {
    writeText(resolve(apiSurfaceDir, 'api-routes.json'), jsonText(apiRoutes))
    writeText(resolve(apiSurfaceDir, 'web-pages.json'), jsonText(pages))
    writeText(resolve(apiSurfaceDir, 'summary.json'), jsonText(summary))
  }
}

function main() {
  const args = process.argv.slice(2)
  const check = args.includes('--check')
  const liveIndex = args.indexOf('--live-routes')
  const liveRoutesPath = liveIndex >= 0 ? resolve(args[liveIndex + 1]) : null

  if (!check) refreshSurfaceInventory(liveRoutesPath)
  const graph = buildGraph(loadInputs())
  const graphText = jsonText(graph)
  const report = reportFor(graph)
  const webGraphPath = resolve(webGraphDir, 'graph.json')

  if (check) {
    if (!existsSync(webGraphPath) || readFileSync(webGraphPath, 'utf8') !== graphText) {
      throw new Error('Snapshot web do grafo está desatualizado; execute o gerador sem --check')
    }
    const apiGraphPath = resolve(apiGraphDir, 'graph.json')
    if (existsSync(apiGraphPath) && readFileSync(apiGraphPath, 'utf8') !== graphText) {
      throw new Error('Snapshots API/web do grafo divergem')
    }
    if (existsSync(reportPath) && readFileSync(reportPath, 'utf8') !== report) {
      throw new Error('Relatório do grafo está desatualizado')
    }
    return
  }

  writeText(webGraphPath, graphText)
  if (existsSync(resolve(repoRoot, 'apps/api'))) writeText(resolve(apiGraphDir, 'graph.json'), graphText)
  if (existsSync(repoRoot)) writeText(reportPath, report)
  process.stdout.write(`Grafo ${graph.digest}: ${graph.summary.apiRoutes} rotas, ${graph.summary.pages} páginas, ${graph.summary.journeys} jornadas.\n`)
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main()

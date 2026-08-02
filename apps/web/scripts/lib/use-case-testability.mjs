import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

function normalizeMethod(method) {
  return method === 'GET|HEAD' ? 'GET' : String(method).split('|')[0]
}

function shortenAction(action) {
  if (!action || action.startsWith('Closure')) return 'Closure'
  if (action.includes('@')) {
    const [className, method] = action.split('@')
    return `${className.split('\\').at(-1)}@${method}`
  }
  return action.split('\\').at(-1)
}

function buildPrefixGroups(existing) {
  const prefixes = new Map()
  for (const row of existing) {
    const parts = row.uri.split('/')
    for (let index = 1; index <= parts.length; index += 1) {
      const prefix = parts.slice(0, index).join('/')
      const groups = prefixes.get(prefix) || new Set()
      groups.add(row.group)
      prefixes.set(prefix, groups)
    }
  }
  return prefixes
}

function inferGroup(uri, exact, prefixes) {
  if (exact.has(uri)) return exact.get(uri)

  const parts = uri.split('/')
  for (let index = parts.length; index > 0; index -= 1) {
    const groups = prefixes.get(parts.slice(0, index).join('/'))
    if (groups?.size === 1) return [...groups][0]
  }

  if (uri.startsWith('api/v1/auth/') || ['api/v1/account', 'api/v1/me'].includes(uri)) return 'auth'
  if (uri.startsWith('api/internal/v1/communication/') || uri === 'api/broadcasting/auth') return 'communication'
  if (/^(login|logout|forgot-password|reset-password|sanctum\/|user\/)/.test(uri)) return 'auth'
  if (uri.startsWith('api/v1/')) return uri.split('/')[2]
  if (uri.startsWith('horizon') || uri === 'up') return 'monitoring'
  if (uri.startsWith('storage/')) return 'storage'
  throw new Error(`Não foi possível classificar a rota ${uri}`)
}

export function routeInventoryFromLive(liveRows, existing) {
  const exact = new Map(existing.map(row => [row.uri, row.group]))
  const prefixes = buildPrefixGroups(existing)
  return liveRows.map((row) => {
    const uri = String(row.uri || '')
    return {
      method: normalizeMethod(row.method),
      uri,
      group: inferGroup(uri, exact, prefixes),
      action: shortenAction(String(row.action || ''))
    }
  })
}

function countsBy(rows, key) {
  return Object.fromEntries(
    [...rows.reduce((counts, row) => {
      const value = row[key]
      counts.set(value, (counts.get(value) || 0) + 1)
      return counts
    }, new Map()).entries()].sort(([left], [right]) => left.localeCompare(right))
  )
}

function isRedirect(page) {
  return page.redirectOnly === true || String(page.notes || '').toLowerCase().includes('redirect-only')
}

export function summaryFor(apiRoutes, pages) {
  return {
    apiTotal: apiRoutes.length,
    apiByMethod: countsBy(apiRoutes, 'method'),
    apiByGroup: countsBy(apiRoutes, 'group'),
    pagesTotal: pages.length,
    pagesBySection: countsBy(pages, 'section'),
    pagesRedirectOnly: pages.filter(isRedirect).length
  }
}

function unique(values) {
  return [...new Set(values)]
}

function assignedJourney(items, kind, value) {
  const matches = items.filter((journey) => {
    if (kind === 'route') return journey.apiGroups.includes(value.group)
    return journey.pageRoutes.includes(value.route) || journey.pageSections.includes(value.section)
  })
  if (matches.length !== 1) {
    throw new Error(`${kind} ${kind === 'route' ? `${value.method} ${value.uri}` : value.file} pertence a ${matches.length} jornadas`)
  }
  return matches[0]
}

function extractApiEndpoints(source) {
  const endpoints = []
  const bases = new Map()
  const basePattern = /const\s+([A-Za-z_$][\w$]*)\s*=\s*(['"])(\/api\/v1\/[^'"]+)\2/g
  for (const match of source.matchAll(basePattern)) bases.set(match[1], match[3])

  const pattern = /([`'"])(\/api\/v1\/[\s\S]*?)\1/g
  for (const match of source.matchAll(pattern)) {
    const raw = match[2]
      .replace(/\$\{[^}]*\?\s*$/, '')
      .split('?')[0]
    if ([...bases.values()].includes(raw)) continue
    const normalized = raw
      .replace(/\$\{[^}]+\}/g, ':param')
      .replace(/^\//, '')
    if (!normalized.includes('\n')) endpoints.push(normalized)
  }

  for (const [name, base] of bases) {
    const templatePattern = new RegExp('`\\$\\{' + name + '\\}([\\s\\S]*?)`', 'g')
    for (const match of source.matchAll(templatePattern)) {
      const normalized = `${base}${match[1]}`
        .replace(/\$\{[^}]+\}/g, ':param')
        .split('?')[0]
        .replace(/^\//, '')
      if (!normalized.includes('\n')) endpoints.push(normalized)
    }
  }
  return unique(endpoints).sort()
}

function routeMatchesEndpoint(routeUri, endpoint) {
  const routeParts = routeUri.split('/')
  const endpointParts = endpoint.split('/')
  if (routeParts.length !== endpointParts.length) return false
  return routeParts.every((part, index) => {
    const candidate = endpointParts[index]
    return part.startsWith('{') || candidate === ':param' || part === candidate
  })
}

function graphDigest(payload) {
  return createHash('sha256').update(JSON.stringify(payload)).digest('hex')
}

export function buildGraph({ catalog, apiRoutes, pages, webRoot }) {
  const actorIds = new Set(catalog.actors.map(actor => actor.id))
  const integrationIds = new Set(catalog.integrations.map(integration => integration.id))
  const journeyIds = new Set(catalog.journeys.map(journey => journey.id))
  if (journeyIds.size !== catalog.journeys.length) throw new Error('IDs de jornada duplicados')

  for (const journey of catalog.journeys) {
    for (const actor of journey.actors) {
      if (!actorIds.has(actor)) throw new Error(`Ator inexistente ${actor} em ${journey.id}`)
    }
    for (const integration of journey.integrations) {
      if (!integrationIds.has(integration)) throw new Error(`Integração inexistente ${integration} em ${journey.id}`)
    }
  }

  const routeNodes = apiRoutes.map((route) => {
    const journey = assignedJourney(catalog.journeys, 'route', route)
    return {
      id: `api:${route.method}:${route.uri}`,
      type: 'api-route',
      journeyId: journey.id,
      ...route
    }
  })
  const pageNodes = pages.map((page) => {
    const journey = assignedJourney(catalog.journeys, 'page', page)
    return {
      id: `page:${page.file}`,
      type: 'page',
      journeyId: journey.id,
      redirectOnly: isRedirect(page),
      ...page
    }
  })

  const apiClientFiles = unique(catalog.journeys.flatMap(journey => journey.apiClients)).sort()
  const apiClientNodes = []
  const apiClientRouteEdges = []
  const unmatchedApiClientEndpoints = []
  for (const file of apiClientFiles) {
    const absolute = resolve(webRoot, file)
    if (!existsSync(absolute)) throw new Error(`Cliente HTTP inexistente: ${file}`)
    const endpoints = extractApiEndpoints(readFileSync(absolute, 'utf8'))
    apiClientNodes.push({ id: `api-client:${file}`, type: 'api-client', file, endpoints })
    for (const endpoint of endpoints) {
      const matches = routeNodes.filter(route => routeMatchesEndpoint(route.uri, endpoint))
      if (matches.length === 0) {
        unmatchedApiClientEndpoints.push({ file, endpoint })
        continue
      }
      for (const route of matches) {
        apiClientRouteEdges.push({ from: `api-client:${file}`, to: route.id, type: 'calls' })
      }
    }
  }

  const handlerNodes = unique(routeNodes.map(route => route.action)).sort().map(action => ({
    id: `handler:${action}`,
    type: 'handler',
    action
  }))
  const evidence = catalog.journeys.flatMap(journey => journey.evidence.map(item => ({ ...item, journeyId: journey.id })))
  const testNodes = unique(evidence.map(item => item.file)).sort().map(file => ({
    id: `test:${file}`,
    type: 'test',
    file
  }))

  const journeyNodes = catalog.journeys.map(journey => ({
    id: `journey:${journey.id}`,
    type: 'journey',
    journeyId: journey.id,
    title: journey.title,
    critical: journey.critical,
    actors: journey.actors,
    gaps: journey.gaps
  }))
  const actorNodes = catalog.actors.map(actor => ({ ...actor, id: `actor:${actor.id}`, type: 'actor' }))
  const integrationNodes = catalog.integrations.map(integration => ({ ...integration, id: `integration:${integration.id}`, type: 'integration' }))

  const edges = [
    ...routeNodes.flatMap(route => [
      { from: `journey:${route.journeyId}`, to: route.id, type: 'exposes' },
      { from: route.id, to: `handler:${route.action}`, type: 'handled-by' }
    ]),
    ...pageNodes.map(page => ({ from: `journey:${page.journeyId}`, to: page.id, type: 'enters' })),
    ...catalog.journeys.flatMap(journey => journey.actors.map(actor => ({ from: `actor:${actor}`, to: `journey:${journey.id}`, type: 'performs' }))),
    ...catalog.journeys.flatMap(journey => journey.integrations.map(integration => ({ from: `journey:${journey.id}`, to: `integration:${integration}`, type: 'may-egress-to' }))),
    ...catalog.journeys.flatMap(journey => journey.apiClients.flatMap((client) => {
      const pagesForJourney = pageNodes.filter(page => page.journeyId === journey.id)
      if (pagesForJourney.length === 0) {
        return [{ from: `journey:${journey.id}`, to: `api-client:${client}`, type: 'uses-client' }]
      }
      return pagesForJourney.map(page => ({ from: page.id, to: `api-client:${client}`, type: 'uses-client' }))
    })),
    ...apiClientRouteEdges,
    ...evidence.map(item => ({
      from: `journey:${item.journeyId}`,
      to: `test:${item.file}`,
      type: 'proven-by',
      level: item.level,
      anchor: item.anchor
    }))
  ]

  const matrix = catalog.journeys.map((journey) => {
    const levels = Object.fromEntries(['L0', 'L1', 'L2', 'L3'].map(level => [
      level,
      level === 'L0' || journey.evidence.some(item => item.level === level)
    ]))
    return {
      journeyId: journey.id,
      title: journey.title,
      critical: journey.critical,
      apiRoutes: routeNodes.filter(route => route.journeyId === journey.id).length,
      pages: pageNodes.filter(page => page.journeyId === journey.id).length,
      apiClients: journey.apiClients.length,
      levels,
      gaps: ['L0', 'L1', 'L2', 'L3'].filter(level => !levels[level])
    }
  })

  const core = {
    schemaVersion: 1,
    source: {
      catalog: 'apps/web/tests/fixtures/use-case-testability/catalog.json',
      apiRoutes: 'apps/*/tests/fixtures/surface-inventory/api-routes.json',
      pages: 'apps/*/tests/fixtures/surface-inventory/web-pages.json'
    },
    summary: {
      journeys: catalog.journeys.length,
      criticalJourneys: catalog.journeys.filter(journey => journey.critical).length,
      apiRoutes: routeNodes.length,
      pages: pageNodes.length,
      handlers: handlerNodes.length,
      apiClients: apiClientNodes.length,
      tests: testNodes.length,
      unmatchedApiClientEndpoints: unmatchedApiClientEndpoints.length
    },
    matrix,
    unmatchedApiClientEndpoints,
    evidence,
    nodes: [
      ...journeyNodes,
      ...actorNodes,
      ...pageNodes,
      ...apiClientNodes,
      ...routeNodes,
      ...handlerNodes,
      ...integrationNodes,
      ...testNodes
    ],
    edges
  }

  return { digest: graphDigest(core), ...core }
}

export function reportFor(graph) {
  const lines = [
    '# Grafo de testabilidade dos casos de uso',
    '',
    `Snapshot: \`${graph.digest}\``,
    '',
    `O levantamento classifica **${graph.summary.apiRoutes} rotas API**, **${graph.summary.pages} páginas Nuxt** e **${graph.summary.apiClients} clientes HTTP** em **${graph.summary.journeys} jornadas**. ${graph.summary.criticalJourneys} jornadas são críticas e exigem evidência L1–L3.`,
    '',
    '| Jornada | Crítica | Rotas | Páginas | Clientes HTTP | L0 | L1 | L2 | L3 | Lacunas |',
    '|---|:---:|---:|---:|---:|:---:|:---:|:---:|:---:|---|'
  ]
  for (const row of graph.matrix) {
    lines.push(`| ${row.title} (\`${row.journeyId}\`) | ${row.critical ? 'sim' : 'não'} | ${row.apiRoutes} | ${row.pages} | ${row.apiClients} | ${row.levels.L0 ? '✓' : '—'} | ${row.levels.L1 ? '✓' : '—'} | ${row.levels.L2 ? '✓' : '—'} | ${row.levels.L3 ? '✓' : '—'} | ${row.gaps.join(', ') || 'nenhuma'} |`)
  }
  lines.push(
    '',
    '## Leitura dos níveis',
    '',
    '- `L0`: superfície inventariada e classificada.',
    '- `L1`: contrato HTTP com autenticação, tenant e papel.',
    '- `L2`: regra de domínio ou comportamento do cliente web.',
    '- `L3`: jornada executada no navegador pelo Playwright local.',
    '',
    '## Limites e segurança',
    '',
    '- Lacunas não críticas permanecem explícitas; referência textual não conta como cobertura behavioral.',
    '- Playwright permanece fora do CI e bloqueia hosts externos.',
    '- SERPRO, Integra, SEFAZ, portal MEI e comunicação permanecem fail-closed nos testes.',
    `- Endpoints de clientes HTTP sem correspondência estrutural: **${graph.summary.unmatchedApiClientEndpoints}**.`,
    ''
  )
  return lines.join('\n')
}

export function listVuePages(root, pagesRoot = root) {
  const files = []
  for (const entry of readdirSync(root)) {
    const absolute = join(root, entry)
    if (statSync(absolute).isDirectory()) files.push(...listVuePages(absolute, pagesRoot))
    else if (entry.endsWith('.vue')) files.push(relative(pagesRoot, absolute))
  }
  return files.sort()
}

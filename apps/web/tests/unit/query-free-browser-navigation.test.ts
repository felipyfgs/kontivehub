import { readFileSync, readdirSync, statSync } from 'node:fs'
import { basename, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  healthTypePath,
  normalizeHealthTypePathParam
} from '~/utils/health-navigation'
import { consumeResetPasswordCredentials } from '~/utils/reset-password'
import {
  documentCatalogClientPath,
  documentCatalogTypePath
} from '~/utils/document-routes'
import { EXPORT_CREATE_PATH } from '~/utils/export-routes'

const APP_ROOT = resolve(process.cwd(), 'app')
const LEGACY_ADAPTER = resolve(APP_ROOT, 'middleware/00.legacy-query.global.ts')
const FRAGMENT_CONSUMERS = new Set([
  resolve(APP_ROOT, 'utils/activation.ts'),
  resolve(APP_ROOT, 'utils/reset-password.ts')
])

function sourceFiles(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = resolve(directory, entry)
    if (statSync(path).isDirectory()) return sourceFiles(path)
    return /\.(?:ts|vue)$/.test(path) ? [path] : []
  })
}

function browserNavigationFiles(): string[] {
  return ['pages', 'components', 'layouts', 'middleware', 'composables', 'utils']
    .flatMap(directory => sourceFiles(resolve(APP_ROOT, directory)))
    .filter(path => !path.includes('/composables/api/'))
    .filter(path => !path.includes('/types/generated/'))
}

describe('navegação canônica sem query', () => {
  it('executa o adaptador legado antes da autenticação global', () => {
    expect(basename(LEGACY_ADAPTER).localeCompare('auth.global.ts')).toBeLessThan(0)
  })

  it('bloqueia consumidores e produtores de query do navegador fora do adaptador legado', () => {
    const violations: string[] = []

    for (const path of browserNavigationFiles()) {
      if (path === LEGACY_ADAPTER) continue
      const source = readFileSync(path, 'utf8')
      const relative = path.slice(APP_ROOT.length + 1)

      if (/\b(?:route|to)\.query\b/.test(source)) violations.push(`${relative}: route.query`)
      if (/['"`]\/(?!api\/)[^'"`\s]*\?[A-Za-z0-9_]/.test(source)) {
        violations.push(`${relative}: URL interna literal com query`)
      }
      if (/(?:navigateTo|router\.(?:push|replace))\s*\(\s*\{[\s\S]{0,240}?\bquery\s*:/.test(source)) {
        violations.push(`${relative}: navegação com objeto query`)
      }
      if (!FRAGMENT_CONSUMERS.has(path) && /\bURLSearchParams\b/.test(source)) {
        violations.push(`${relative}: URLSearchParams`)
      }
    }

    expect(violations).toEqual([])
  })

  it('mantém requests HTTP com query fora do gate do navegador', () => {
    const apiSource = readFileSync(resolve(APP_ROOT, 'composables/api/createOperationsApi.ts'), 'utf8')
    expect(apiSource).toContain('/api/v1/operations/inbox')
    expect(apiSource).toContain('{ query: params }')
  })

  it('normaliza somente tipos de Saúde allowlisted no path', () => {
    expect(normalizeHealthTypePathParam('cte_593')).toBe('cte_593')
    expect(normalizeHealthTypePathParam(['usage_high'])).toBe('usage_high')
    expect(normalizeHealthTypePathParam('../admin')).toBeNull()
    expect(healthTypePath('cte_593')).toBe('/health/type/cte_593')
    expect(healthTypePath('desconhecido')).toBe('/health')
  })

  it('registra o path parametrizado de Saúde sem alias incompatível', () => {
    const page = readFileSync(resolve(APP_ROOT, 'pages/health.vue'), 'utf8')
    const config = readFileSync(resolve(APP_ROOT, '../nuxt.config.ts'), 'utf8')

    expect(page).not.toContain('alias: [\'/health/type/:type\']')
    expect(config).toContain('\'pages:extend\'(pages)')
    expect(config).toContain('path: \'/health/type/:type\'')
    expect(config).toContain('file: healthPage.file')
  })

  it('mantém contextos de documentos e criação em paths tipados', () => {
    expect(documentCatalogTypePath('cte')).toBe('/docs/catalog/type/CTE')
    expect(documentCatalogTypePath('xml')).toBe('/docs/catalog')
    expect(documentCatalogClientPath('42')).toBe('/docs/catalog/client/42')
    expect(documentCatalogClientPath('../42')).toBe('/docs/catalog')
    expect(EXPORT_CREATE_PATH).toBe('/exports/new')
  })

  it('preserva filtros e paginação de Documentos no estado de superfície', () => {
    const workspace = readFileSync(resolve(APP_ROOT, 'components/docs/Workspace.vue'), 'utf8')
    const byClient = readFileSync(resolve(APP_ROOT, 'components/docs/ByClient.vue'), 'utf8')
    const catalog = readFileSync(resolve(APP_ROOT, 'pages/docs/catalog.vue'), 'utf8')

    expect(workspace).toContain('DocumentsCatalogNavigationState')
    expect(workspace).toContain('pageSize: 25')
    expect(workspace).toContain('documentsNavigation.state.value.pageSize')
    expect(byClient).toContain('ByClientNavigationState')
    expect(byClient).toContain('operational_filter')
    expect(byClient).toContain('page: number')
    expect(byClient).toContain('per_page: 10 | 20 | 50')
    expect(byClient).toContain('const search = ref(byClientNavigation.state.value.q)')
    expect(workspace).not.toContain('const byClientPage = ref(')
    expect(workspace).not.toContain('v-model:search="filters.q"')
    expect(catalog).toContain(':key="`${initialKind}:${initialClientId}`"')
    expect(catalog).not.toContain(':key="route.path"')
  })

  it('reinicia estado por sessão sem duplicar cargas ou contornar normalizadores', () => {
    const health = readFileSync(resolve(APP_ROOT, 'pages/health.vue'), 'utf8')
    const exportsPage = readFileSync(resolve(APP_ROOT, 'pages/exports.vue'), 'utf8')
    const closing = readFileSync(resolve(APP_ROOT, 'pages/closing.vue'), 'utf8')

    expect(health).toMatch(/watch\(sessionEpoch[\s\S]*typeFilter\.value = FILTER_ALL/)
    expect(health).toMatch(/watch\(sessionEpoch[\s\S]*router\.replace\('\/health'\)/)
    expect(exportsPage).toContain('const pageWillReset = page.value !== 1')
    expect(exportsPage).toContain('if (!pageWillReset) void load()')
    expect(closing.match(/set: value => patchClosingNavigation/g)).toHaveLength(8)
  })

  it('consome o fragmento de reset e limpa segredo e search da barra', () => {
    const replaceState = (..._args: Parameters<History['replaceState']>) => undefined
    const calls: Array<Parameters<History['replaceState']>> = []
    const historyApi = {
      replaceState: (...args: Parameters<History['replaceState']>) => {
        calls.push(args)
        return replaceState(...args)
      }
    }

    expect(consumeResetPasswordCredentials({
      pathname: '/reset-password',
      hash: '#token=abc%2B123&email=pessoa%2Bteste%40example.test'
    }, historyApi)).toEqual({
      token: 'abc+123',
      email: 'pessoa+teste@example.test'
    })
    expect(calls).toEqual([[null, '', '/reset-password']])
  })
})

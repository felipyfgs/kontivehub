#!/usr/bin/env node
/**
 * Gate estrutural de fidelidade ao Nuxt UI Dashboard Template (0f30c09).
 *
 * Uso:
 *   node apps/web/scripts/template-fidelity-gate.mjs
 *   node apps/web/scripts/template-fidelity-gate.mjs --json
 *   node apps/web/scripts/template-fidelity-gate.mjs --self-test
 *
 * Fonte de inventário:
 *   apps/web/tests/fixtures/template-parity-matrix.md
 *
 * Cascas de produto permitidas (embutem o template):
 *   MonitoringModuleTable, DocsWorkspace, WorkQueueWorkspace, DASHBOARD_TABLE_UI
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const WEB = path.resolve(__dirname, '..')
const PAGES = path.join(WEB, 'app/pages')
const APP = path.join(WEB, 'app')
const MATRIX = path.join(WEB, 'tests/fixtures/template-parity-matrix.md')

/** Wrappers que NÃO embutem o template — proibidos em pages. */
const FORBIDDEN_CHROME = [
  ['ShellListShell', 'components/shell/ListShell.vue'],
  ['ShellStickyTableFilters', 'components/shell/StickyTableFilters.vue'],
  ['ShellInfiniteTableLoader', 'components/shell/InfiniteTableLoader.vue']
]

/** Tokens literais de customers.vue @ 0f30c09 (ou export DASHBOARD_TABLE_UI). */
const CUSTOMERS_TABLE_UI_TOKENS = [
  `base: 'table-fixed border-separate border-spacing-0'`,
  `thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none'`,
  `tbody: '[&>tr]:last:[&>td]:border-b-0'`,
  `th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r'`,
  `td: 'border-b border-default'`,
  `separator: 'h-0'`
]

const asJson = process.argv.includes('--json')
const selfTest = process.argv.includes('--self-test')

function walkVue(dir) {
  const out = []
  if (!fs.existsSync(dir)) return out
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) out.push(...walkVue(full))
    else if (entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

function relApp(file) {
  return path.relative(APP, file).split(path.sep).join('/')
}

function parseMatrixFiles(md) {
  const files = new Set()
  for (const line of md.split('\n')) {
    const m = line.match(/^\| `(pages\/[^`]+)` \|/)
    if (m) files.add(m[1])
  }
  return files
}

function parseMatrixBundles(md) {
  const bundles = new Map()
  for (const line of md.split('\n')) {
    const cells = line.split('|').map(cell => cell.trim())
    const file = cells[1]?.match(/^`(pages\/[^`]+)`$/)?.[1]
    const bundle = cells[3]?.match(/^`([^`]+)`$/)?.[1]
    if (file && bundle) bundles.set(file, bundle)
  }
  return bundles
}

function read(file) {
  return fs.readFileSync(file, 'utf8')
}

function isAuth(text) {
  return /layout:\s*['"]auth['"]/.test(text)
}

function isRedirect(text) {
  if (text.includes('navigateTo') && !text.includes('UDashboard') && text.length < 600) {
    return true
  }
  if (/definePageMeta\s*\(\s*\{[\s\S]*redirect\s*:/.test(text) && text.length < 500) {
    return true
  }
  return false
}

function hasChrome(text) {
  return text.includes('UDashboardPanel')
}

/** Cascas que embutem UDashboardPanel do template. */
function hasEmbeddedChrome(text) {
  return (
    text.includes('ShellPagePanel')
    || text.includes('ShellSettingsShell')
    || text.includes('MonitoringModuleTable')
    || text.includes('<ModuleTable')
    || text.includes('MonitoringSimplesMeiPortfolio')
    || text.includes('DocsWorkspace')
    || text.includes('WorkQueueWorkspace')
    || text.includes('NotesWorkspace')
    || text.includes('CommunicationWorkspacePage')
  )
}

function hasCustomersTableUi(text) {
  if (text.includes('DASHBOARD_TABLE_UI') || text.includes('DENSE_DASHBOARD_TABLE_UI')) {
    return true
  }
  return CUSTOMERS_TABLE_UI_TOKENS.every(token => text.includes(token))
}

function findForbiddenChrome(file, text) {
  const violations = []
  for (const [identifier, componentPath] of FORBIDDEN_CHROME) {
    if (text.includes(identifier) || text.includes(componentPath)) {
      violations.push(`${relApp(file)}: chrome proibido ${identifier} (${componentPath})`)
    }
  }
  return violations
}

function findNonCanonicalAlerts(file, text) {
  const violations = []
  if (text.includes('FiscalDemoBanner')) {
    violations.push(`${relApp(file)}: banner demonstrativo persistente fora do template`)
  }
  return violations
}

function parentShellFiles(pageFile) {
  const parents = []
  let dir = path.dirname(pageFile)
  while (dir.startsWith(PAGES) && dir !== PAGES) {
    const base = path.basename(dir)
    const sibling = path.join(path.dirname(dir), `${base}.vue`)
    if (fs.existsSync(sibling)) parents.push(sibling)
    dir = path.dirname(dir)
  }
  return parents
}

function pageHasChromeWithParents(pageFile, text) {
  if (hasChrome(text) || hasEmbeddedChrome(text)) {
    return { ok: true, via: hasChrome(text) ? 'self' : 'embedded' }
  }
  for (const parent of parentShellFiles(pageFile)) {
    const pText = read(parent)
    if (hasChrome(pText) || hasEmbeddedChrome(pText)) {
      return { ok: true, via: relApp(parent) }
    }
  }
  return { ok: false, via: null }
}

function findListContractIssues(file, text) {
  const rel = relApp(file)
  const violations = []

  if (text.includes('MonitoringModuleTable') || text.includes('<ModuleTable')) {
    const requiredShell = [
      'panel-id=',
      ':columns=',
      ':rows=',
      ':loading=',
      '@update:page=',
      '@refresh='
    ]
    for (const token of requiredShell) {
      if (!text.includes(token)) {
        violations.push(`${rel}: MonitoringModuleTable sem contrato (${token})`)
      }
    }
    if (/\boffice_id\s*:/.test(text)) violations.push(`${rel}: request controla office_id`)
    violations.push(...findNonCanonicalAlerts(file, text))
    return violations
  }

  if (!text.includes('<UTable')) {
    violations.push(`${rel}: LIST sem UTable`)
  }
  if (text.includes('<UTable') && !hasCustomersTableUi(text)) {
    violations.push(`${rel}: LIST sem :ui de customers.vue (literal ou DASHBOARD_TABLE_UI)`)
  }
  if (/\boffice_id\s*:/.test(text)) violations.push(`${rel}: request controla office_id`)
  violations.push(...findNonCanonicalAlerts(file, text))
  return violations
}

function runSelfTest() {
  const fixture = path.join(PAGES, 'fixture.vue')
  const validList = `
    <template #body>
      <UInput />
      <UTable :ui="{
        base: 'table-fixed border-separate border-spacing-0',
        thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
        tbody: '[&>tr]:last:[&>td]:border-b-0',
        th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
        td: 'border-b border-default',
        separator: 'h-0'
      }" />
    </template>`
  const checks = {
    rejectsForbiddenChrome: findForbiddenChrome(fixture, '<ShellListShell />').length === 1,
    acceptsAlertDescription: findNonCanonicalAlerts(fixture, '<UAlert title="Info" description="Texto longo" />').length === 0,
    rejectsDemoBanner: findNonCanonicalAlerts(fixture, '<FiscalDemoBanner />').length === 1,
    acceptsLiteralList: findListContractIssues(fixture, validList).length === 0,
    acceptsDashboardTableUi: findListContractIssues(fixture, '<UTable :ui="DASHBOARD_TABLE_UI" />').length === 0,
    acceptsModuleTable: findListContractIssues(fixture, `
      <MonitoringModuleTable panel-id="x" :columns="c" :rows="r" :loading="l" @update:page="p" @refresh="f" />
    `).length === 0
  }
  const issues = Object.entries(checks)
    .filter(([, passed]) => !passed)
    .map(([name]) => `Autoteste falhou: ${name}`)
  const result = { ok: issues.length === 0, issues, checks }
  console.log(JSON.stringify(result, null, 2))
  process.exit(result.ok ? 0 : 1)
}

function main() {
  const issues = []
  const warnings = []

  const pageFiles = walkVue(PAGES).map(f => relApp(f)).sort()
  const hasMatrix = fs.existsSync(MATRIX)
  const matrixMd = hasMatrix ? read(MATRIX) : ''
  const matrixFiles = parseMatrixFiles(matrixMd)
  const matrixBundles = parseMatrixBundles(matrixMd)

  if (!hasMatrix) {
    issues.push(`Matriz obrigatória ausente: ${path.relative(WEB, MATRIX)}`)
  } else {
    for (const f of pageFiles) {
      if (!matrixFiles.has(f)) issues.push(`Página fora da matriz: ${f}`)
    }
    for (const f of matrixFiles) {
      if (!pageFiles.includes(f)) issues.push(`Entrada fantasma na matriz: ${f}`)
    }
  }

  if (hasMatrix) {
    for (const abs of walkVue(PAGES)) {
      const text = read(abs)
      const rel = relApp(abs)
      const bundle = matrixBundles.get(rel) || 'UNKNOWN'

      if (bundle === 'AUTH' || bundle === 'REDIRECT' || isAuth(text) || isRedirect(text)) {
        continue
      }

      // Conteúdo de settings / placeholders: chrome vem do pai ou reexport sob Conta.
      if (bundle === 'SETTINGS_CHILD' || bundle === 'CHILD') {
        issues.push(...findForbiddenChrome(abs, text))
        issues.push(...findNonCanonicalAlerts(abs, text))
        continue
      }

      issues.push(...findForbiddenChrome(abs, text))
      issues.push(...findNonCanonicalAlerts(abs, text))

      const chrome = pageHasChromeWithParents(abs, text)
      if (!chrome.ok) {
        issues.push(`Sem UDashboardPanel/casca (nem no pai): ${rel}`)
      }

      if (bundle === 'LIST') {
        issues.push(...findListContractIssues(abs, text))
      }
    }

    const componentsDir = path.join(APP, 'components')
    if (fs.existsSync(componentsDir)) {
      for (const abs of walkVue(componentsDir)) {
        const text = read(abs)
        issues.push(...findNonCanonicalAlerts(abs, text))
      }
    }
  }

  const result = {
    ok: issues.length === 0,
    pages: pageFiles.length,
    matrixEntries: matrixFiles.size,
    issues,
    warnings
  }

  if (asJson) {
    console.log(JSON.stringify(result, null, 2))
  } else {
    console.log(`Template fidelity gate — pages=${result.pages} matrix=${result.matrixEntries}`)
    if (warnings.length) {
      console.log('\nWarnings:')
      for (const w of warnings) console.log(`  ⚠ ${w}`)
    }
    if (issues.length) {
      console.log('\nIssues:')
      for (const i of issues) console.log(`  ✗ ${i}`)
      console.log(`\nFAIL (${issues.length} issue(s))`)
    } else {
      console.log('\nPASS')
    }
  }

  process.exit(issues.length ? 1 : 0)
}

if (selfTest) runSelfTest()
else main()

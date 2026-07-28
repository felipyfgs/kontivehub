import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')
const workspace = source('app/components/docs/Workspace.vue')

describe('docs shell chrome gate', () => {
  it('usa navbar e refresh canônicos sem chrome duplicado', () => {
    expect(workspace).toContain('<ShellPageNavbar')
    expect(workspace).toContain('title="Documentos"')
    expect(workspace).toContain('test-id="page-navbar"')
    expect(workspace).toContain('<ShellNavbarRefresh')
    expect(workspace).toContain('aria-label="Atualizar catálogo de documentos"')
    expect(workspace).not.toContain('<UDashboardNavbar')
    expect(workspace).not.toContain('<UDashboardSidebarCollapse')
    expect(workspace).not.toContain('<UTooltip text="Atualizar">')
  })

  it('usa erro inicial canônico sem apagar erro contextual com linhas', () => {
    expect(workspace).toContain('const initialLoadError = computed')
    expect(workspace).toContain('<ShellLoadError')
    expect(workspace).toContain('test-id="docs-initial-load-error"')
    expect(workspace).toContain('@retry="reloadActive"')
    expect(workspace).toContain(':error="loadError"')
  })

  it('preserva composição documental, rotas e responsividade', () => {
    for (const contract of [
      'class="min-w-0"',
      'DocsInsightsBar',
      'DocsFilters',
      'DocsByClient',
      'DocsCatalog',
      'DocsDetailModal',
      'ShellFormModal',
      '/docs/imports',
      '/docs/catalog',
      'selectedAccessKey'
    ]) {
      expect(workspace).toContain(contract)
    }

    expect(existsSync(resolve(process.cwd(), 'app/components/shell/DocsWorkspace.vue'))).toBe(false)
  })
})

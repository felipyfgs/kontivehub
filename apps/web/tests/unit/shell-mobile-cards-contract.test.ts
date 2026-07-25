import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { LIST_TABLE_FOOTER_CLASS } from '~/utils/table-ui'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)

describe('shell-mobile-cards-contract', () => {
  it('ShellMobileCards existe e aceita slots/campos primários', () => {
    const path = root('app/components/shell/MobileCards.vue')
    expect(existsSync(path)).toBe(true)
    const source = readFileSync(path, 'utf8')
    expect(source).toContain('primaryColumnId')
    expect(source).toContain('statusColumnId')
    expect(source).toContain('summaryColumnIds')
    expect(source).toContain('shell-mobile-cards')
    expect(source).toContain('-cell')
  })

  it('ShellDataTable expõe caminho mobile cards em viewport < md', () => {
    const source = readFileSync(root('app/components/shell/DataTable.vue'), 'utf8')
    expect(source).toContain('ShellMobileCards')
    expect(source).toContain('mobileCards')
    expect(source).toContain('smaller(\'md\')')
    expect(source).toContain('useMobileCards')
    expect(source).toContain('primaryColumnId')
    expect(source).toContain('summaryColumnIds')
  })

  it('ShellTableFooter empilha/compacta controles em < sm', () => {
    expect(LIST_TABLE_FOOTER_CLASS).toContain('flex-col')
    expect(LIST_TABLE_FOOTER_CLASS).toContain('sm:flex-row')
    const footer = readFileSync(root('app/components/shell/TableFooter.vue'), 'utf8')
    expect(footer).toContain('smaller(\'sm\')')
    expect(footer).toContain('list-table-footer-controls')
    expect(footer).toContain('w-28 sm:w-36')
    expect(footer).toContain('resolvedSiblingCount')
  })

  it('ModuleDataTable compõe ShellDataTable (cards via shell, sem markup divergente)', () => {
    const moduleSource = readFileSync(
      root('app/components/monitoring/ModuleDataTable.vue'),
      'utf8'
    )
    expect(moduleSource).toContain('ShellDataTable')
    expect(moduleSource).toContain('mobile-cards-test-id="fiscal-mobile-cards"')
    expect(moduleSource).toContain('update:perPage')
    expect(moduleSource).not.toContain('<MonitoringModuleMobileCards')
    expect(moduleSource).not.toContain('v-if="useMobileCards"')

    const wrapper = readFileSync(
      root('app/components/monitoring/ModuleMobileCards.vue'),
      'utf8'
    )
    expect(wrapper).toContain('ShellMobileCards')
    expect(wrapper).toContain('fiscal-mobile-cards')
  })
})

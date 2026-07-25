import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  clampListTablePage,
  listTablePageCount,
  normalizeListTablePerPage
} from '~/utils/table-ui'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)

describe('list-table-pagination', () => {
  it('normaliza per-page para 10|20|50', () => {
    expect(normalizeListTablePerPage(10)).toBe(10)
    expect(normalizeListTablePerPage(20)).toBe(20)
    expect(normalizeListTablePerPage(50)).toBe(50)
    expect(normalizeListTablePerPage(15)).toBe(20)
    expect(normalizeListTablePerPage(100)).toBe(20)
    expect(normalizeListTablePerPage(undefined)).toBe(20)
    expect(normalizeListTablePerPage(47, 10)).toBe(10)
  })

  it('calcula pageCount sem páginas fantasma', () => {
    expect(listTablePageCount(2, 20)).toBe(1)
    expect(listTablePageCount(20, 20)).toBe(1)
    expect(listTablePageCount(21, 20)).toBe(2)
    expect(listTablePageCount(0, 20)).toBe(1)
    expect(listTablePageCount(5, 0)).toBe(5)
  })

  it('clamp da página fica em [1, pageCount]', () => {
    expect(clampListTablePage(1, 1)).toBe(1)
    expect(clampListTablePage(3, 1)).toBe(1)
    expect(clampListTablePage(0, 4)).toBe(1)
    expect(clampListTablePage(99, 3)).toBe(3)
  })

  it('ShellTableFooter mantém UPagination sempre e normaliza per-page', () => {
    const footer = readFileSync(root('app/components/shell/TableFooter.vue'), 'utf8')
    expect(footer).toContain('v-if="showPagination"')
    expect(footer).not.toContain('effectiveShowPagination')
    expect(footer).not.toContain('pageCount.value > 1')
    expect(footer).toContain('normalizeListTablePerPage')
    expect(footer).toContain('resolvedItemsPerPage')
    expect(footer).toContain('resolvedPage')

    const dataTable = readFileSync(root('app/components/shell/DataTable.vue'), 'utf8')
    expect(dataTable).toContain('v-if="showFooter"')
    expect(dataTable).not.toContain('total > 0 || selectedCount > 0')
    // Desktop e mobile usam o mesmo resolvedGetRowId (nunca getRowId cru/undefined).
    expect(dataTable.match(/:get-row-id="resolvedGetRowId"/g)?.length).toBe(2)
    expect(dataTable).not.toMatch(/:get-row-id="getRowId"/)
  })

  it('listas in-memory usam useLocalTablePagination (não fingem length como per-page)', () => {
    for (const rel of [
      'app/pages/admin/offices/index.vue',
      'app/pages/admin/serpro/catalog.vue',
      'app/pages/admin/serpro/contracts.vue',
      'app/pages/admin/serpro/usage.vue',
      'app/pages/settings/usage.vue'
    ]) {
      const source = readFileSync(root(rel), 'utf8')
      expect(source, rel).toContain('useLocalTablePagination')
      expect(source, rel).not.toMatch(/:items-per-page="[^"]*\.length\s*\|\|\s*1"/)
    }
  })
})

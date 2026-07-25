import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('data-table-filter overflow collapse', () => {
  it('colapsa chips em contador+lista quando a faixa estoura', () => {
    const root = readFileSync(
      resolve(process.cwd(), 'app/components/data-table-filter/Root.vue'),
      'utf8'
    )
    const toolbar = readFileSync(
      resolve(process.cwd(), 'app/components/shell/ListFilterToolbar.vue'),
      'utf8'
    )
    const layout = readFileSync(
      resolve(process.cwd(), 'app/utils/list-filter-layout.ts'),
      'utf8'
    )

    expect(root).toContain('useResizeObserver')
    expect(root).toContain('chipsCollapsed')
    expect(root).toContain('justify-end')
    expect(root).toContain('data-testid="data-table-filter-collapsed"')
    expect(root).toContain('data-testid="data-table-filter-collapsed-list"')
    expect(root).toContain('data-testid="data-table-filter-chips-measure"')
    expect(root).toContain('data-testid="data-table-filter-controls"')
    expect(root).toContain('measure.scrollWidth > budget + 1')

    expect(toolbar).toContain('flex min-w-0 flex-1 items-center justify-end gap-1.5')
    expect(layout).toContain('sm:flex-1 sm:flex-nowrap sm:justify-end')
  })
})

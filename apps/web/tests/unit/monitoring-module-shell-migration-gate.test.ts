import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('monitoring module shell migration gate', () => {
  it('uses the canonical panel and navbar without duplicating sidebar chrome', () => {
    const table = source('app/components/monitoring/ModuleTable.vue')

    expect(table.match(/<ShellPagePanel\b/g)).toHaveLength(1)
    expect(table.match(/<ShellPageNavbar\b/g)).toHaveLength(1)
    expect(table).toContain('<ShellPagePanel :id="panelId">')
    expect(table).toContain(':title="title"')
    expect(table).not.toContain('<UDashboardPanel')
    expect(table).not.toContain('<UDashboardNavbar')
    expect(table).not.toContain('<UDashboardSidebarCollapse')
  })

  it('keeps pending search fail-closed beside the navbar refresh', () => {
    const table = source('app/components/monitoring/ModuleTable.vue')

    expect(table).toMatch(/<template #right>[\s\S]*?<MonitoringPendingSearchButton/)
    expect(table).toContain('v-if="showPendingSearch && moduleKey && getClientId && !surfaceIsUnavailable"')
    expect(table).toContain(':current-page-client-ids="currentPageClientIds"')
    expect(table).toContain(':selected-client-ids="selectedClientIds"')
    expect(table).toMatch(/<ShellNavbarRefresh[\s\S]*?:loading="loading \|\| refreshing"[\s\S]*?test-id="fiscal-navbar-refresh"[\s\S]*?@click="emit\('refresh'\)"/)
  })

  it('separates the initial load error from stale data failures', () => {
    const table = source('app/components/monitoring/ModuleTable.vue')

    expect(table).toContain('const hasInitialError = computed(() => Boolean(props.error)')
    expect(table).toContain('&& props.rows.length === 0')
    expect(table).toContain('&& !props.loading')
    expect(table).toContain('&& !props.refreshing)')
    expect(table).toMatch(/<ShellLoadError[\s\S]*?v-if="hasInitialError"[\s\S]*?test-id="fiscal-error-alert"[\s\S]*?:description="error"[\s\S]*?@retry="emit\('refresh'\)"/)
    expect(table).toMatch(/<UAlert[\s\S]*?v-else-if="error"[\s\S]*?data-testid="fiscal-error-alert"/)
    expect(table).toMatch(/<MonitoringModuleDataTable[\s\S]*?v-if="!hasInitialError"/)
    expect(table).toContain('Última atualização válida:')
  })

  it('preserves slots, testids and the existing toolbar and KPI adapters', () => {
    const table = source('app/components/monitoring/ModuleTable.vue')
    const toolbar = source('app/components/monitoring/ModuleToolbar.vue')
    const kpis = source('app/components/monitoring/KpiStrip.vue')

    for (const slot of ['submodules', 'kpis', 'utilities', 'bulk-actions', 'detail']) {
      expect(table, `slot ${slot}`).toContain(`name="${slot}"`)
    }
    for (const testId of [
      'fiscal-module-body',
      'fiscal-submodules',
      'fiscal-surface-unavailable-alert',
      'fiscal-kpi-block',
      'fiscal-utilities',
      'fiscal-error-alert',
      'fiscal-column-visibility'
    ]) {
      expect(table, testId).toContain(testId)
    }

    expect(table).toContain(':selected-client-ids="selectedClientIds"')
    expect(table).toContain(':selected-count="selectedCount"')
    expect(table).toContain(':clear-selection="clearSelection"')
    expect(toolbar).toContain('<ShellListFilterToolbar')
    expect(toolbar).toContain('test-id-prefix="fiscal-filter"')
    expect(kpis).toContain('<ShellScrollableTabs')
  })
})

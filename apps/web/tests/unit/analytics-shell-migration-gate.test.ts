import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')
const home = source('app/pages/index.vue')
const monitoring = source('app/pages/monitoring/index.vue')

describe('analytics shell migration gate', () => {
  it('uses canonical panel and navbar shells without duplicate sidebar chrome', () => {
    for (const page of [home, monitoring]) {
      expect(page.match(/<ShellPagePanel\b/g)).toHaveLength(1)
      expect(page.match(/<ShellPageNavbar\b/g)).toHaveLength(1)
      expect(page).not.toContain('<UDashboardPanel')
      expect(page).not.toContain('<UDashboardNavbar')
      expect(page).not.toContain('<UDashboardSidebarCollapse')
    }
  })

  it('keeps the home toolbar, actions and valid-data contracts', () => {
    expect(home).toContain('<template #toolbar>')
    expect(home).toContain('<UDashboardToolbar data-testid="page-toolbar">')
    expect(home).toMatch(/<ShellNavbarRefresh[\s\S]*?:loading="loading"[\s\S]*?class="-ms-1"[\s\S]*?aria-label="Atualizar"[\s\S]*?@click="load"/)
    expect(home).toContain('Abrir alertas operacionais')
    expect(home).toContain('Abrir ações rápidas')
    expect(home).toContain('Promise.allSettled')
    expect(home).toContain('lastGoodSummary')
    expect(home).toContain('lastValidAt')
    expect(home).toContain('home-operations-section')
  })

  it('keeps monitoring overflow, refresh, CTA and distinct error states', () => {
    expect(monitoring).toContain('body-class="overflow-x-hidden"')
    expect(monitoring).toMatch(/<ShellNavbarRefresh[\s\S]*?aria-label="Atualizar visão fiscal"[\s\S]*?:loading="loading"[\s\S]*?@click="load"/)
    expect(monitoring).toContain('monitoring-by-company-entry')
    expect(monitoring).toContain('monitoring-by-company-link')
    expect(monitoring).toContain('insights-load-error')
    expect(monitoring).toContain('insights-refresh-error')
    expect(monitoring).toContain('insights-partial-error')
    expect(monitoring).toContain('<ShellKpiStrip')
  })

  it('does not introduce a public analytics wrapper', () => {
    expect(existsSync(resolve(process.cwd(), 'app/components/shell/AnalyticsPage.vue'))).toBe(false)
    expect(home).not.toContain('ShellAnalyticsPage')
    expect(monitoring).not.toContain('ShellAnalyticsPage')
  })
})

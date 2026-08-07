import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)

const MODALS = [
  'app/components/monitoring/PgmeiHistoryModal.vue',
  'app/components/monitoring/RegimeOptionModal.vue',
  'app/components/monitoring/RegimeResolutionModal.vue',
  'app/components/monitoring/RegimeCalendarModal.vue',
  'app/components/monitoring/MeiPublicServicesModal.vue',
  'app/components/monitoring/DefisDeclarationsModal.vue'
] as const

describe('responsive fiscal history modals', () => {
  it('keeps tabular history readable as cards below md without horizontal scrolling', () => {
    for (const rel of MODALS) {
      const source = readFileSync(root(rel), 'utf8')
      expect(source, rel).toContain('md:hidden')
      expect(source, rel).toContain('hidden w-full text-left text-sm md:table')
      expect(source, rel).not.toContain('overflow-x-auto')
    }
  })

  it('preserves the factual values and document actions in mobile summaries', () => {
    const pgmei = readFileSync(root('app/components/monitoring/PgmeiHistoryModal.vue'), 'utf8')
    const resolution = readFileSync(root('app/components/monitoring/RegimeResolutionModal.vue'), 'utf8')
    const dasn = readFileSync(root('app/components/monitoring/MeiPublicServicesModal.vue'), 'utf8')

    expect(pgmei).toContain('itemEntity(item)')
    expect(pgmei).toContain('itemStatus(item)')
    expect(resolution).toContain('data-testid="regime-resolution-document"')
    expect(dasn).toContain('hasIntegralDasnReceipt')
  })

  it('keeps the PGDAS-D matrix named and paired with a narrow-screen summary', () => {
    const grid = readFileSync(root('app/components/monitoring/pgdasd/HistoryPeriodGrid.vue'), 'utf8')

    expect(grid).toContain('role="region"')
    expect(grid).toContain(':aria-label="`Operações do PA ${formatPgdasdPeriod(period.period_key)}`"')
    expect(grid).toContain('xl:block')
    expect(grid).toContain('data-testid="pgdasd-history-mobile"')
    expect(grid).toContain('xl:hidden')
  })
})

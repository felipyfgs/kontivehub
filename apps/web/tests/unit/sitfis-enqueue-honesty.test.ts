import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(__dirname, '../..')

function read(rel: string): string {
  return readFileSync(resolve(root, rel), 'utf8')
}

describe('sitfis enqueue honesty gate', () => {
  it('useMonitoringActions só trata enqueued===true como sucesso SITFIS', () => {
    const src = read('app/composables/useMonitoringActions.ts')
    expect(src).toContain('payload?.enqueued === true')
    expect(src).toContain('Atualização SITFIS não enfileirada')
    expect(src).toContain('WITHIN_TTL')
    expect(src).toContain('ALREADY_RUNNING')
    expect(src).toContain('return enqueued ? (payload as Record<string, unknown>) : null')
  })

  it('PendingSearch e ModuleBulkActions só incrementam quando result é truthy', () => {
    const pending = read('app/components/monitoring/PendingSearchButton.vue')
    const bulk = read('app/components/monitoring/ModuleBulkActions.vue')
    expect(pending).toContain('if (result) queued += 1')
    expect(bulk).toContain('if (result) ok++')
  })
})

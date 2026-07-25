import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('useMonitoringWorkspace smoke gate', () => {
  it('exports useMonitoringWorkspace from composable source', () => {
    const source = readFileSync(
      resolve(__dirname, '../../app/composables/useMonitoringWorkspace.ts'),
      'utf8'
    )
    expect(source).toContain('export function useMonitoringWorkspace')
  })
})

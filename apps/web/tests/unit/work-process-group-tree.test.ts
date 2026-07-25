import { describe, expect, it } from 'vitest'
import {
  expandAllGroupKeys,
  GROUP_CHILDREN_PREFETCH_CONCURRENCY,
  mapWithConcurrency,
  materialisedProcessesFromCache,
  mergeMaterialisedProcesses,
  toggleGroupKeyExpanded,
  toggleProcessIdExpanded,
  type GroupChildrenCacheEntry
} from '../../app/composables/useWorkProcessGroupTree'

describe('useWorkProcessGroupTree', () => {
  it('abre todos os clientes da página e permite multi-open', () => {
    expect(expandAllGroupKeys(['a', 'b', 'c'])).toEqual({ a: true, b: true, c: true })
    const opened = toggleGroupKeyExpanded({ a: true }, 'b')
    expect(opened).toEqual({ a: true, b: true })
    expect(toggleGroupKeyExpanded(opened, 'a')).toEqual({ b: true })
  })

  it('expande processos de forma multi-open', () => {
    const next = toggleProcessIdExpanded({}, 7)
    expect(next).toEqual({ 7: true })
    expect(toggleProcessIdExpanded(next, 9)).toEqual({ 7: true, 9: true })
    expect(toggleProcessIdExpanded({ 7: true, 9: true }, 7)).toEqual({ 9: true })
  })

  it('prefetch limita concorrência', async () => {
    expect(GROUP_CHILDREN_PREFETCH_CONCURRENCY).toBeGreaterThanOrEqual(3)
    expect(GROUP_CHILDREN_PREFETCH_CONCURRENCY).toBeLessThanOrEqual(5)

    let inFlight = 0
    let maxInFlight = 0
    const items = Array.from({ length: 8 }, (_, i) => i)

    await mapWithConcurrency(items, 3, async () => {
      inFlight += 1
      maxInFlight = Math.max(maxInFlight, inFlight)
      await Promise.resolve()
      inFlight -= 1
    })

    expect(maxInFlight).toBeLessThanOrEqual(3)
  })

  it('materializa só processos ready do cache', () => {
    const cache: Record<string, GroupChildrenCacheEntry> = {
      1: {
        status: 'ready',
        processes: [{ id: 10 } as never, { id: 11 } as never],
        error: null,
        page: 1,
        total: 2
      },
      2: {
        status: 'loading',
        processes: [{ id: 99 } as never],
        error: null,
        page: 1,
        total: 0
      },
      3: {
        status: 'error',
        processes: [],
        error: 'falha',
        page: 1,
        total: 0
      }
    }

    expect(materialisedProcessesFromCache(cache).map(p => p.id)).toEqual([10, 11])
  })

  it('mantém processos materializados de páginas anteriores e atualiza versões recarregadas', () => {
    const pageOne = [{ id: 10, lock_version: 1 } as never]
    const pageTwo = [{ id: 20, lock_version: 1 } as never]
    const accumulated = mergeMaterialisedProcesses(
      mergeMaterialisedProcesses({}, pageOne),
      pageTwo
    )
    const refreshed = mergeMaterialisedProcesses(
      accumulated,
      [{ id: 10, lock_version: 2 } as never]
    )

    expect(Object.keys(refreshed)).toEqual(['10', '20'])
    expect(refreshed['10']?.lock_version).toBe(2)
  })
})

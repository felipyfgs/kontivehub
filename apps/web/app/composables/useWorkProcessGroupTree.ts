/**
 * Árvore Cliente → processos → tasks no modo `group=client`.
 * Multi-expand de clientes/processos, cache por group key e prefetch paralelo.
 */
import type { OperationalProcess } from '~/types/work'

/** Concorrência do prefetch de filhos (3–5). */
export const GROUP_CHILDREN_PREFETCH_CONCURRENCY = 4

export type GroupChildrenCacheStatus = 'idle' | 'loading' | 'ready' | 'error'

export interface GroupChildrenCacheEntry {
  status: GroupChildrenCacheStatus
  processes: OperationalProcess[]
  error: string | null
  page: number
  total: number
}

export function emptyGroupChildrenEntry(): GroupChildrenCacheEntry {
  return {
    status: 'idle',
    processes: [],
    error: null,
    page: 1,
    total: 0
  }
}

/** Marca todos os keys da página como expandidos (abertura do modo Cliente). */
export function expandAllGroupKeys(keys: readonly string[]): Record<string, boolean> {
  const next: Record<string, boolean> = {}
  for (const key of keys) {
    if (key) next[key] = true
  }
  return next
}

/** Multi-open: abrir um key não fecha os demais; mesmo key recolhe. */
export function toggleGroupKeyExpanded(
  current: Record<string, boolean>,
  key: string
): Record<string, boolean> {
  if (!key) return { ...current }
  const next = { ...current }
  if (next[key]) {
    Reflect.deleteProperty(next, key)
  } else {
    next[key] = true
  }
  return next
}

/** Multi-open de processos (tasks embutidas). */
export function toggleProcessIdExpanded(
  current: Record<string, boolean>,
  processId: number
): Record<string, boolean> {
  if (!Number.isInteger(processId) || processId <= 0) return { ...current }
  const id = String(processId)
  const next = { ...current }
  if (next[id]) {
    Reflect.deleteProperty(next, id)
  } else {
    next[id] = true
  }
  return next
}

export function isProcessIdExpanded(
  expanded: Record<string, boolean>,
  processId: number
): boolean {
  return expanded[String(processId)] === true
}

/**
 * Executa workers com limite de concorrência (prefetch controlado).
 * Não dispara rajada ilimitada.
 */
export async function mapWithConcurrency<T>(
  items: readonly T[],
  concurrency: number,
  worker: (item: T, index: number) => Promise<void>
): Promise<void> {
  if (!items.length) return
  const limit = Math.max(1, Math.min(items.length, Math.floor(concurrency) || 1))
  let cursor = 0

  async function runSlot(): Promise<void> {
    while (cursor < items.length) {
      const index = cursor
      cursor += 1
      await worker(items[index] as T, index)
    }
  }

  await Promise.all(Array.from({ length: limit }, () => runSlot()))
}

/** Processos materializados (cache ready) — elegíveis a bulk. */
export function materialisedProcessesFromCache(
  cache: Record<string, GroupChildrenCacheEntry>
): OperationalProcess[] {
  const out: OperationalProcess[] = []
  const seen = new Set<number>()
  for (const entry of Object.values(cache)) {
    if (entry.status !== 'ready') continue
    for (const process of entry.processes) {
      if (seen.has(process.id)) continue
      seen.add(process.id)
      out.push(process)
    }
  }
  return out
}

/**
 * Cache cumulativo dos recursos efetivamente carregados. Ao trocar a página de
 * filhos/grupos, mantém metadados das seleções anteriores sem inventar recursos.
 */
export function mergeMaterialisedProcesses(
  current: Record<string, OperationalProcess>,
  processes: readonly OperationalProcess[]
): Record<string, OperationalProcess> {
  const next = { ...current }
  for (const process of processes) {
    next[String(process.id)] = process
  }
  return next
}

export function groupChildrenSelectionState(
  processes: readonly OperationalProcess[],
  processSelection: Record<string, boolean>
): 'none' | 'some' | 'all' {
  if (!processes.length) return 'none'
  const selected = processes.filter(
    process => processSelection[String(process.id)] === true
  ).length
  if (selected === 0) return 'none'
  if (selected === processes.length) return 'all'
  return 'some'
}

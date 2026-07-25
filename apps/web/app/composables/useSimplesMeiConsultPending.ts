/**
 * Pendência visual de consulta Simples/MEI: skeleton por client_id até a run terminal.
 */
import {
  extractConsultRunRef,
  isFiscalMonitoringRunTerminal
} from '~/utils/fiscal-monitoring-run'

export type SimplesMeiConsultPendingRef = {
  clientId: number
  runId: number
}

const POLL_MS = 2500
const MAX_ATTEMPTS = 48

export function useSimplesMeiConsultPending(options: {
  onSettled?: () => void | Promise<void>
} = {}) {
  const api = useApi()
  /** client_id → run_id */
  const pendingByClient = ref<Record<number, number>>({})
  const timers = new Map<number, ReturnType<typeof setTimeout>>()
  const attempts = new Map<number, number>()

  const pendingClientIds = computed(() =>
    new Set(Object.keys(pendingByClient.value).map(Number))
  )

  function isPending(clientId: number): boolean {
    return pendingByClient.value[clientId] != null
  }

  function clearTimer(clientId: number) {
    const timer = timers.get(clientId)
    if (timer) clearTimeout(timer)
    timers.delete(clientId)
    attempts.delete(clientId)
  }

  function removePending(clientId: number) {
    if (pendingByClient.value[clientId] == null) return
    pendingByClient.value = Object.fromEntries(
      Object.entries(pendingByClient.value)
        .filter(([id]) => Number(id) !== clientId)
        .map(([id, runId]) => [Number(id), runId])
    )
    clearTimer(clientId)
  }

  function clearAll() {
    for (const clientId of Object.keys(pendingByClient.value).map(Number)) {
      clearTimer(clientId)
    }
    pendingByClient.value = {}
  }

  async function settle(clientId: number) {
    removePending(clientId)
    await options.onSettled?.()
  }

  function schedulePoll(clientId: number, runId: number) {
    clearTimer(clientId)
    attempts.set(clientId, 0)
    timers.set(clientId, setTimeout(() => {
      void pollOnce(clientId, runId)
    }, POLL_MS))
  }

  async function pollOnce(clientId: number, runId: number) {
    if (pendingByClient.value[clientId] !== runId) return

    const count = (attempts.get(clientId) || 0) + 1
    attempts.set(clientId, count)

    if (count > MAX_ATTEMPTS) {
      await settle(clientId)
      return
    }

    try {
      const res = await api.fiscal.runs.get(runId)
      const status = res.data?.status
      if (isFiscalMonitoringRunTerminal(status)) {
        await settle(clientId)
        return
      }
    } catch {
      // Mantém skeleton e tenta de novo até o teto.
    }

    if (pendingByClient.value[clientId] !== runId) return
    timers.set(clientId, setTimeout(() => {
      void pollOnce(clientId, runId)
    }, POLL_MS))
  }

  function track(entries: Array<SimplesMeiConsultPendingRef | unknown>) {
    const next = { ...pendingByClient.value }
    const accepted: SimplesMeiConsultPendingRef[] = []

    for (const entry of entries) {
      const ref = (entry && typeof entry === 'object' && 'clientId' in entry && 'runId' in entry)
        ? entry as SimplesMeiConsultPendingRef
        : extractConsultRunRef(entry)
      if (!ref) continue
      next[ref.clientId] = ref.runId
      accepted.push(ref)
    }

    if (!accepted.length) return
    pendingByClient.value = next
    for (const ref of accepted) {
      schedulePoll(ref.clientId, ref.runId)
    }
  }

  onBeforeUnmount(() => {
    clearAll()
  })

  return {
    pendingByClient,
    pendingClientIds,
    isPending,
    track,
    clearAll,
    removePending
  }
}

/** Status terminais de FiscalMonitoringRun (espelho FiscalRunStatus::isTerminal). */
const TERMINAL_RUN_STATUSES = new Set([
  'COMPLETED',
  'FAILED',
  'SKIPPED',
  'REQUEUED',
  'BLOCKED'
])

export function isFiscalMonitoringRunTerminal(status: string | null | undefined): boolean {
  if (!status) return false
  return TERMINAL_RUN_STATUSES.has(String(status).toUpperCase())
}

export function extractConsultRunRef(input: unknown): { clientId: number, runId: number } | null {
  if (!input || typeof input !== 'object') return null
  const row = input as Record<string, unknown>
  const runId = Number(row.id)
  const clientId = Number(row.client_id)
  if (!Number.isFinite(runId) || runId < 1) return null
  if (!Number.isFinite(clientId) || clientId < 1) return null
  return { clientId, runId }
}

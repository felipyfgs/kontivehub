export function selectedMonitoringRows<T>(
  rows: T[],
  selection: Record<string, boolean>,
  getRowId: (row: T, index: number) => string
): T[] {
  const selected = new Set(
    Object.entries(selection)
      .filter(([, enabled]) => enabled)
      .map(([id]) => id)
  )
  return rows.filter((row, index) => selected.has(getRowId(row, index)))
}

export function pruneMonitoringSelection<T>(
  rows: T[],
  selection: Record<string, boolean>,
  getRowId: (row: T, index: number) => string
): Record<string, boolean> {
  const current = new Set(rows.map((row, index) => getRowId(row, index)))
  return Object.fromEntries(
    Object.entries(selection).filter(([id, enabled]) => enabled && current.has(id))
  )
}

export function monitoringSelectionScope(input: {
  officeEpoch: number
  route: string
  page: number
  filters: string
  sorting: unknown
  /** Tab/submódulo local (ex. PGDASD) — path da rota pode ser compartilhado entre tabs. */
  submodule?: string
}): string {
  return JSON.stringify([
    input.officeEpoch,
    input.route,
    input.page,
    input.filters,
    input.sorting,
    input.submodule ?? ''
  ])
}

/** Aceita somente IDs decimais positivos canônicos de segmentos de path. */
export function parsePositiveRouteId(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (typeof raw === 'number') {
    return Number.isSafeInteger(raw) && raw > 0 ? raw : null
  }
  if (typeof raw !== 'string' || !/^[1-9]\d*$/.test(raw)) return null
  const id = Number(raw)
  return Number.isSafeInteger(id) ? id : null
}

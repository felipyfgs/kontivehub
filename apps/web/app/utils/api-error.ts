interface ApiErrorPayload {
  message?: string
  code?: string
  errors?: Record<string, string[]> | Array<{ path?: string, code?: string, message?: string }>
  data?: unknown
  graph_digest?: string
}

function payloadFrom(error: unknown): ApiErrorPayload | undefined {
  if (!error || typeof error !== 'object') {
    return undefined
  }

  const candidate = error as {
    data?: ApiErrorPayload
    response?: { _data?: ApiErrorPayload }
  }

  return candidate.data || candidate.response?._data
}

export function apiErrorMessage(error: unknown, fallback: string): string {
  const payload = payloadFrom(error)
  if (payload?.message && typeof payload.message === 'string') {
    return payload.message
  }
  return fallback
}

export function apiErrorCode(error: unknown): string | null {
  const payload = payloadFrom(error)
  return typeof payload?.code === 'string' ? payload.code : null
}

export function apiErrorStatus(error: unknown): number | null {
  if (!error || typeof error !== 'object') return null
  const candidate = error as {
    status?: unknown
    statusCode?: unknown
    response?: { status?: unknown }
  }
  const value = candidate.statusCode ?? candidate.status ?? candidate.response?.status
  let status: number
  try {
    status = Number(value)
  } catch {
    return null
  }
  return Number.isInteger(status) && status >= 100 && status <= 599 ? status : null
}

export function apiFieldErrors(error: unknown): Record<string, string[]> {
  const errors = payloadFrom(error)?.errors
  if (!errors || Array.isArray(errors)) {
    return {}
  }
  return errors
}

/** Erros estruturados de grafo (validate / dry-run 422). */
export function apiGraphErrors(error: unknown): Array<{ path: string, code: string, message: string }> {
  const errors = payloadFrom(error)?.errors
  if (!Array.isArray(errors)) {
    return []
  }
  return errors
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const row = item as { path?: unknown, code?: unknown, message?: unknown }
      if (typeof row.path !== 'string' || typeof row.code !== 'string' || typeof row.message !== 'string') {
        return null
      }
      return { path: row.path, code: row.code, message: row.message }
    })
    .filter((item): item is { path: string, code: string, message: string } => item !== null)
}

export function apiErrorData<T>(error: unknown): T | null {
  return (payloadFrom(error)?.data as T | undefined) ?? null
}

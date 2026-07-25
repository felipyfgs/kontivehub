import type {
  DeclarationOperation,
  DeclarationOperationAvailability,
  DeclarationOperationParam,
  DeclarationOperationMutation
} from '~/types/fiscal-modules'

export function declarationOperationAvailabilityMeta(value?: string | null) {
  const normalized = String(value || '').toUpperCase() as DeclarationOperationAvailability
  if (normalized === 'AVAILABLE') {
    return { label: 'Disponível', color: 'success' as const, icon: 'i-lucide-circle-check' }
  }
  if (normalized === 'CONTROLLED') {
    return { label: 'Controlada', color: 'warning' as const, icon: 'i-lucide-shield-check' }
  }
  if (normalized === 'PROSPECTION') {
    return { label: 'Em prospecção', color: 'neutral' as const, icon: 'i-lucide-telescope' }
  }
  return { label: 'Não implementada', color: 'error' as const, icon: 'i-lucide-circle-x' }
}

export function declarationMutationStatusMeta(operation?: DeclarationOperationMutation | null) {
  const status = String(operation?.status || '').toUpperCase()
  if (status === 'CONFIRMED') {
    return { label: operation?.status_label || 'Confirmada', color: 'success' as const, icon: 'i-lucide-circle-check' }
  }
  if (status === 'UNKNOWN_RESULT' || status === 'RECONCILING') {
    return { label: operation?.status_label || 'Resultado incerto', color: 'warning' as const, icon: 'i-lucide-circle-help' }
  }
  if (status === 'REJECTED') {
    return { label: operation?.status_label || 'Rejeitada', color: 'error' as const, icon: 'i-lucide-circle-x' }
  }
  if (status === 'SENT' || status === 'PENDING') {
    return { label: operation?.status_label || 'Processando', color: 'info' as const, icon: 'i-lucide-loader-circle' }
  }
  return { label: operation?.status_label || status || 'Aguardando', color: 'neutral' as const, icon: 'i-lucide-clock-3' }
}

export function declarationOperationDefaults(operation: DeclarationOperation): Record<string, string> {
  const defaults: Record<string, string> = {}
  for (const field of operation.params) {
    defaults[field.name] = field.type === 'object'
      ? '{}'
      : field.type === 'array'
        ? '[]'
        : ''
  }
  return defaults
}

export function buildDeclarationOperationParams(
  fields: DeclarationOperationParam[],
  values: Record<string, string>
): Record<string, unknown> {
  const output: Record<string, unknown> = {}
  for (const field of fields) {
    const raw = String(values[field.name] ?? '').trim()
    if (raw === '') {
      if (field.required) throw new Error(`Informe: ${field.label}`)
      continue
    }

    if (field.type === 'integer') {
      if (!/^-?\d+$/.test(raw)) throw new Error(`${field.label}: informe um número inteiro.`)
      output[field.name] = Number(raw)
      continue
    }
    if (field.type === 'object' || field.type === 'array') {
      let decoded: unknown
      try {
        decoded = JSON.parse(raw)
      } catch {
        throw new Error(`${field.label}: JSON inválido.`)
      }
      if (field.type === 'object' && (decoded === null || Array.isArray(decoded) || typeof decoded !== 'object')) {
        throw new Error(`${field.label}: informe um objeto JSON.`)
      }
      if (field.type === 'array' && !Array.isArray(decoded)) {
        throw new Error(`${field.label}: informe uma lista JSON.`)
      }
      output[field.name] = decoded
      continue
    }

    output[field.name] = raw
  }

  return output
}

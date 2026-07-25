import type {
  FiscalMonitoringQueryState,
  MonitoringCoverageOutputField,
  MonitoringCoverageSurface
} from '~/types/fiscal-modules'

export interface MonitoringCoverageContext {
  surfaceKeys?: readonly string[] | null
  route?: string | null
}

export interface MonitoringWorkspaceRequestToken {
  sessionEpoch: number
  generation: number
}

export interface MonitoringQueryStateMeta {
  state: FiscalMonitoringQueryState | string
  label: string
  description: string
  icon: string
  color: 'success' | 'warning' | 'error' | 'info' | 'neutral'
  animated?: boolean
}

/** Vocabulário visual do estado consultivo; nenhum estado ausente vira sucesso. */
export function monitoringQueryStateMeta(value?: string | null): MonitoringQueryStateMeta {
  const state = String(value || 'IDLE').trim().toUpperCase() || 'IDLE'
  const catalog: Record<FiscalMonitoringQueryState, Omit<MonitoringQueryStateMeta, 'state'>> = {
    IDLE: {
      label: 'Ainda não consultado',
      description: 'Não há consulta registrada para este contexto.',
      icon: 'i-lucide-circle-dashed',
      color: 'neutral'
    },
    QUEUED: {
      label: 'Na fila',
      description: 'A consulta aguarda processamento.',
      icon: 'i-lucide-clock-3',
      color: 'info'
    },
    PROCESSING: {
      label: 'Processando',
      description: 'A fonte oficial ainda está processando a consulta.',
      icon: 'i-lucide-loader-circle',
      color: 'info',
      animated: true
    },
    READY: {
      label: 'Resultado disponível',
      description: 'A consulta terminou e possui resultado.',
      icon: 'i-lucide-circle-check',
      color: 'success'
    },
    NO_DATA: {
      label: 'Sem dados',
      description: 'A consulta terminou sem registros para o recorte.',
      icon: 'i-lucide-inbox',
      color: 'neutral'
    },
    FAILED: {
      label: 'Falha na atualização',
      description: 'A tentativa mais recente falhou.',
      icon: 'i-lucide-circle-x',
      color: 'error'
    },
    BLOCKED: {
      label: 'Consulta bloqueada',
      description: 'A política de acesso ou disponibilidade bloqueou a consulta.',
      icon: 'i-lucide-shield-off',
      color: 'error'
    },
    UNSUPPORTED: {
      label: 'Sem fonte suportada',
      description: 'Não existe fonte oficial suportada para esta informação.',
      icon: 'i-lucide-ban',
      color: 'neutral'
    }
  }
  const known = catalog[state as FiscalMonitoringQueryState]
  if (known) return { state, ...known }

  return {
    state,
    label: 'Estado desconhecido',
    description: 'O contrato não reconhece o estado informado.',
    icon: 'i-lucide-help-circle',
    color: 'neutral'
  }
}

export function monitoringFreshnessLabel(value?: string | null): string {
  switch (String(value || '').trim().toUpperCase()) {
    case 'FRESH': return 'Observação recente'
    case 'STALE': return 'Observação anterior preservada'
    default: return 'Frescor não informado'
  }
}

function normalizeMonitoringRoute(value: string): string {
  const withoutQuery = value.trim().split(/[?#]/u, 1)[0] || '/'
  const withLeadingSlash = withoutQuery.startsWith('/') ? withoutQuery : `/${withoutQuery}`
  return withLeadingSlash.length > 1
    ? withLeadingSlash.replace(/\/+$/u, '')
    : withLeadingSlash
}

/** Compara uma rota Nuxt real com o pattern público do contrato Laravel. */
export function monitoringRouteMatches(pattern: string, route: string): boolean {
  const patternSegments = normalizeMonitoringRoute(pattern).split('/').filter(Boolean)
  const routeSegments = normalizeMonitoringRoute(route).split('/').filter(Boolean)

  if (patternSegments.length !== routeSegments.length) return false

  return patternSegments.every((segment, index) =>
    segment.startsWith(':') || segment === routeSegments[index]
  )
}

/**
 * Recorta a cobertura usando exclusivamente identificadores/rotas recebidos
 * do backend. Chaves desconhecidas retornam vazio (fail-closed).
 */
export function filterMonitoringCoverageSurfaces(
  surfaces: readonly MonitoringCoverageSurface[],
  context: MonitoringCoverageContext = {}
): MonitoringCoverageSurface[] {
  const wantedKeys = new Set(
    (context.surfaceKeys ?? [])
      .map(value => value.trim())
      .filter(Boolean)
  )

  if (wantedKeys.size > 0) {
    return surfaces.filter(surface => wantedKeys.has(surface.surface_key))
  }

  const route = context.route?.trim()
  if (route) {
    return surfaces.filter(surface => monitoringRouteMatches(surface.route, route))
  }

  return [...surfaces]
}

/** Guard comum para respostas que podem chegar após troca de contexto. */
export function monitoringWorkspaceRequestIsCurrent(
  token: MonitoringWorkspaceRequestToken,
  currentSessionEpoch: number,
  currentGeneration: number
): boolean {
  return token.sessionEpoch === currentSessionEpoch
    && token.generation === currentGeneration
}

export function monitoringCoverageOutputLabel(operation: {
  response_documented: boolean
  output_fields: MonitoringCoverageOutputField[]
}): string {
  if (!operation.response_documented) return 'Saída ainda não documentada'

  const fields = operation.output_fields
    .map(field => field.name.trim())
    .filter(Boolean)

  return fields.length
    ? fields.join(', ')
    : 'Envelope documentado sem campos publicados'
}

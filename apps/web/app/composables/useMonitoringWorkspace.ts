import { createSharedComposable } from '@vueuse/core'
import type { MaybeRefOrGetter } from 'vue'
import type {
  MonitoringCoverageContract,
  MonitoringCoverageSurface
} from '~/types/fiscal-modules'
import {
  filterMonitoringCoverageSurfaces,
  monitoringWorkspaceRequestIsCurrent
} from '~/utils/monitoring-coverage'
import type { MonitoringWorkspaceRequestToken } from '~/utils/monitoring-coverage'

export type MonitoringWorkspaceLoadStatus = 'idle' | 'pending' | 'success' | 'error'

export interface UseMonitoringWorkspaceOptions {
  /** Recorte explícito; desconhecidos resultam em cobertura vazia. */
  surfaceKeys?: MaybeRefOrGetter<readonly string[] | null | undefined>
  /** Rota contextual; por padrão usa a rota Nuxt atual. */
  route?: MaybeRefOrGetter<string | null | undefined>
  /** Dashboard central: não reduz o contrato à rota atual. */
  allSurfaces?: MaybeRefOrGetter<boolean | undefined>
}

/**
 * Cache tenant-aware do contrato canônico. A closure compartilhada conserva
 * somente recursos não serializáveis (Promise/AbortController); todo estado
 * publicado usa useState com chaves explícitas.
 */
const _useMonitoringWorkspaceProvider = () => {
  const api = useApi()
  const { sessionEpoch } = useDashboard()
  const contract = useState<MonitoringCoverageContract | null>(
    'monitoring-workspace:contract',
    () => null
  )
  const cachedSessionEpoch = useState<number | null>(
    'monitoring-workspace:session-epoch',
    () => null
  )
  const generation = useState<number>('monitoring-workspace:generation', () => 0)
  const status = useState<MonitoringWorkspaceLoadStatus>(
    'monitoring-workspace:status',
    () => 'idle'
  )
  const error = useState<string | null>('monitoring-workspace:error', () => null)
  const loadedAt = useState<string | null>('monitoring-workspace:loaded-at', () => null)

  let activeController: AbortController | null = null
  let activeRequest: Promise<MonitoringCoverageContract | null> | null = null

  function cancelPendingRequest() {
    activeController?.abort()
    activeController = null
    activeRequest = null
  }

  function alignSessionEpoch(): boolean {
    if (cachedSessionEpoch.value === sessionEpoch.value) return false

    generation.value += 1
    cancelPendingRequest()
    cachedSessionEpoch.value = sessionEpoch.value
    contract.value = null
    loadedAt.value = null
    error.value = null
    status.value = 'idle'

    return true
  }

  function invalidate() {
    generation.value += 1
    cancelPendingRequest()
    cachedSessionEpoch.value = sessionEpoch.value
    contract.value = null
    loadedAt.value = null
    error.value = null
    status.value = 'idle'
  }

  function requestIsCurrent(token: MonitoringWorkspaceRequestToken): boolean {
    return monitoringWorkspaceRequestIsCurrent(
      token,
      sessionEpoch.value,
      generation.value
    )
  }

  async function load(force = false): Promise<MonitoringCoverageContract | null> {
    alignSessionEpoch()

    if (!force && contract.value) return contract.value
    if (!force && activeRequest) return activeRequest

    generation.value += 1
    cancelPendingRequest()

    const token: MonitoringWorkspaceRequestToken = {
      sessionEpoch: sessionEpoch.value,
      generation: generation.value
    }
    const controller = new AbortController()
    activeController = controller
    status.value = 'pending'
    error.value = null

    const request = (async () => {
      try {
        const response = await api.fiscal.monitoringCoverage({ signal: controller.signal })
        if (!requestIsCurrent(token)) return null

        contract.value = response.data
        cachedSessionEpoch.value = token.sessionEpoch
        loadedAt.value = new Date().toISOString()
        status.value = 'success'

        return response.data
      } catch (caught) {
        if (!requestIsCurrent(token)) return null

        status.value = 'error'
        error.value = apiErrorMessage(
          caught,
          'Não foi possível carregar o contrato de cobertura do monitor.'
        )

        return null
      } finally {
        if (requestIsCurrent(token)) activeController = null
      }
    })()

    activeRequest = request
    const result = await request
    if (activeRequest === request) activeRequest = null

    return result
  }

  watch(sessionEpoch, () => {
    const changed = alignSessionEpoch()
    if (changed) void load()
  })

  onMounted(() => void load())

  return {
    cachedSessionEpoch,
    contract,
    error,
    generation,
    invalidate,
    load,
    loadedAt,
    refresh: () => load(true),
    requestIsCurrent,
    status
  }
}

const useMonitoringWorkspaceProvider = createSharedComposable(_useMonitoringWorkspaceProvider)

/**
 * Workspace público: compartilha/cacheia o contrato e entrega somente as
 * surfaces do contexto atual. O contrato completo continua disponível para o
 * painel central mediante allSurfaces=true.
 */
export function useMonitoringWorkspace(options: UseMonitoringWorkspaceOptions = {}) {
  const route = useRoute()
  const provider = useMonitoringWorkspaceProvider()
  const { sessionEpoch } = useDashboard()
  const contextGeneration = ref(0)

  const requestedSurfaceKeys = computed<readonly string[]>(() => {
    const values = toValue(options.surfaceKeys)
    return Array.isArray(values) ? values : []
  })
  const allSurfaces = computed(() => toValue(options.allSurfaces) === true)
  const contextualRoute = computed(() => {
    if (allSurfaces.value || requestedSurfaceKeys.value.length > 0) return null
    return toValue(options.route) ?? route.path
  })
  const contextSignature = computed(() => JSON.stringify({
    all: allSurfaces.value,
    route: contextualRoute.value,
    surfaceKeys: [...requestedSurfaceKeys.value].sort()
  }))

  const surfaces = computed<MonitoringCoverageSurface[]>(() => {
    const rows = provider.contract.value?.surfaces ?? []
    if (allSurfaces.value) return [...rows]

    return filterMonitoringCoverageSurfaces(rows, {
      surfaceKeys: requestedSurfaceKeys.value,
      route: contextualRoute.value
    })
  })

  watch(contextSignature, (next, previous) => {
    if (previous === undefined || next === previous) return
    contextGeneration.value += 1
  })

  watch(sessionEpoch, () => {
    contextGeneration.value += 1
  })

  function captureContextRequest(): MonitoringWorkspaceRequestToken {
    return {
      sessionEpoch: sessionEpoch.value,
      generation: contextGeneration.value
    }
  }

  function contextRequestIsCurrent(token: MonitoringWorkspaceRequestToken): boolean {
    return monitoringWorkspaceRequestIsCurrent(
      token,
      sessionEpoch.value,
      contextGeneration.value
    )
  }

  return {
    ...provider,
    captureContextRequest,
    contextGeneration,
    contextRequestIsCurrent,
    surfaces
  }
}

import { toRaw, type Ref } from 'vue'

type SurfaceOptions<T> = {
  normalize?: (value: T) => T
  resetKey?: Ref<unknown> | (() => unknown)
}

export type SurfaceNavigationState<T> = {
  state: Ref<T>
  patch: (value: Partial<T>) => void
  replace: (value: T) => void
  reset: () => void
}

export const SURFACE_NAVIGATION = {
  communication: {
    workspace: 'communication.workspace',
    contacts: 'communication.contacts',
    flows: 'communication.flows',
    quickResponses: 'communication.quick-responses'
  },
  work: {
    queue: 'work-queue',
    processes: 'work-process-grouping',
    calendar: 'work-calendar',
    templates: 'work-templates'
  },
  clients: 'clients.index',
  documents: {
    catalog: 'docs.catalog',
    byClient: 'docs.by-client'
  },
  closing: 'closing.list',
  health: 'health.list',
  exports: 'exports.list'
} as const

export const COMMUNICATION_SURFACES = SURFACE_NAVIGATION.communication
export const WORK_SURFACES = SURFACE_NAVIGATION.work

type NestedStringValue<T> = T extends string
  ? T
  : T extends Record<string, unknown>
    ? NestedStringValue<T[keyof T]>
    : never

export type SurfaceNavigationId = NestedStringValue<typeof SURFACE_NAVIGATION>

export const SURFACE_NAVIGATION_IDS: readonly SurfaceNavigationId[] = [
  ...Object.values(COMMUNICATION_SURFACES),
  ...Object.values(WORK_SURFACES),
  SURFACE_NAVIGATION.clients,
  ...Object.values(SURFACE_NAVIGATION.documents),
  SURFACE_NAVIGATION.closing,
  SURFACE_NAVIGATION.health,
  SURFACE_NAVIGATION.exports
]

const allowedSurfaces = new Set<string>(SURFACE_NAVIGATION_IDS)

/** Converte proxies Vue em estruturas simples compatíveis com `useState`. */
function cloneSurfaceValue<T>(value: T, seen = new WeakMap<object, unknown>()): T {
  if (value === null || typeof value !== 'object') return value
  const raw = toRaw(value as object)
  const previous = seen.get(raw)
  if (previous !== undefined) return previous as T
  if (Array.isArray(raw)) {
    const copy: unknown[] = []
    seen.set(raw, copy)
    for (const item of raw) copy.push(cloneSurfaceValue(item, seen))
    return copy as T
  }
  const copy: Record<string, unknown> = {}
  seen.set(raw, copy)
  for (const [key, item] of Object.entries(raw)) {
    copy[key] = cloneSurfaceValue(item, seen)
  }
  return copy as T
}

export function isSurfaceNavigationId(value: unknown): value is SurfaceNavigationId {
  return typeof value === 'string' && allowedSurfaces.has(value)
}

function assertSurfaceNavigationId(value: string): asserts value is SurfaceNavigationId {
  if (!isSurfaceNavigationId(value)) {
    throw new Error(`Superfície de navegação não allowlisted: ${value}`)
  }
}

export type CommunicationSurface = typeof COMMUNICATION_SURFACES[keyof typeof COMMUNICATION_SURFACES]

const surfaceEntries = new Map<string, {
  state: Ref<unknown>
  createDefaults: () => unknown
}>()

function stateKey(surface: string, resetKey: unknown): string {
  return `surface-navigation:${surface}:${String(resetKey ?? 'default')}`
}

/**
 * Estado efêmero de navegação. `useState` mantém lista/detalhe durante a SPA,
 * mas a chave de sessão impede reutilização ao trocar identidade ou tenant.
 */
export function useSurfaceNavigationState<T extends object>(
  surface: SurfaceNavigationId,
  defaults: T | (() => T),
  options: SurfaceOptions<T> = {}
): SurfaceNavigationState<T> {
  const resetKey = typeof options.resetKey === 'function'
    ? options.resetKey()
    : options.resetKey?.value
  const createDefaults = () => cloneSurfaceValue(
    typeof defaults === 'function' ? defaults() : defaults
  )
  const key = stateKey(surface, resetKey)
  const state = useState<T>(key, createDefaults)
  surfaceEntries.set(key, {
    state: state as Ref<unknown>,
    createDefaults
  })
  const normalize = (value: T) => options.normalize ? options.normalize(value) : value

  function replace(value: T): void {
    state.value = normalize(cloneSurfaceValue(value))
  }

  function patch(value: Partial<T>): void {
    replace({ ...state.value, ...value })
  }

  function reset(): void {
    replace(createDefaults())
  }

  return { state, patch, replace, reset }
}

export type SurfaceNavigationIntent<T = unknown> = {
  surface: SurfaceNavigationId
  payload: T
}

const intents = new Map<string, unknown>()
let intentGeneration = 0
let lastIntentIdentityContext: string | null = null

function transferGuestIntents(identityContext: string): void {
  const guestPrefix = `guest:none:${intentGeneration}:`
  const identityPrefix = `${identityContext}:${intentGeneration}:`

  for (const [key, payload] of [...intents.entries()]) {
    if (!key.startsWith(guestPrefix)) continue
    intents.delete(key)
    intents.set(`${identityPrefix}${key.slice(guestPrefix.length)}`, payload)
  }
}

function intentContext(): string {
  try {
    const { user } = useSanctumAuth()
    const raw = user.value as Record<string, unknown> | null | undefined
    const identity = raw && typeof raw.data === 'object' && raw.data !== null
      ? raw.data as Record<string, unknown>
      : raw
    const tenant = identity?.current_tenant
    const tenantId = tenant && typeof tenant === 'object'
      ? (tenant as Record<string, unknown>).id
      : null
    const identityContext = `${String(identity?.id ?? 'guest')}:${String(tenantId ?? 'none')}`
    if (lastIntentIdentityContext !== null && lastIntentIdentityContext !== identityContext) {
      const isFirstAuthenticatedIdentity = lastIntentIdentityContext === 'guest:none'
        && identity?.id !== undefined
        && identity?.id !== null

      if (isFirstAuthenticatedIdentity) {
        transferGuestIntents(identityContext)
      } else {
        intents.clear()
        intentGeneration += 1
      }
    }
    lastIntentIdentityContext = identityContext
    return `${identityContext}:${intentGeneration}`
  } catch {
    return `guest:none:${intentGeneration}`
  }
}

function intentKey(surface: SurfaceNavigationId): string {
  return `${intentContext()}:${surface}`
}

/** Publica uma intenção transitória; o consumidor a remove antes de devolver. */
export function publishSurfaceNavigationIntent<T>(surface: SurfaceNavigationId, payload: T): void {
  assertSurfaceNavigationId(surface)
  intents.set(intentKey(surface), cloneSurfaceValue(payload))
}

/** Consome uma intenção no máximo uma vez. */
export function consumeSurfaceNavigationIntent<T>(surface: SurfaceNavigationId): T | null {
  assertSurfaceNavigationId(surface)
  const key = intentKey(surface)
  const payload = intents.get(key)
  intents.delete(key)
  return payload === undefined ? null : payload as T
}

/** Publica o recorte e navega sem serializá-lo na URL. */
export async function navigateWithSurfaceIntent<T>(
  surface: SurfaceNavigationId,
  payload: T,
  path: string
): Promise<void> {
  publishSurfaceNavigationIntent(surface, payload)
  await navigateTo(path)
}

/** Usado no logout/troca de tenant para não reter estado de outro contexto. */
export function clearSurfaceNavigationState(): void {
  intentGeneration += 1
  intents.clear()
  for (const entry of surfaceEntries.values()) {
    entry.state.value = entry.createDefaults()
  }
}

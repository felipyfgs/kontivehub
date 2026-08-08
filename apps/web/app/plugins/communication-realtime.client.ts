import type Echo from 'laravel-echo'
import type Pusher from 'pusher-js'
import type { RealtimeEvent, RealtimeService, RealtimeState } from '~/types/communication/realtime'
import { canViewCommunication, unwrapMeUser } from '~/utils/permissions'
import type { MeIdentity } from '~/utils/permissions'
import {
  communicationRealtimeConfiguration,
  communicationRealtimeStateForConnection,
  communicationRealtimeTransports,
  resolveCommunicationRealtimeHost
} from '~/utils/communication-realtime'

interface ChannelAuthorizationParams {
  socketId: string
  channelName: string
}

interface ChannelAuthorizationData {
  auth: string
  channel_data?: string
  shared_secret?: string
}

type ChannelAuthorizationCallback = (
  error: Error | null,
  data: ChannelAuthorizationData | null
) => void

export default defineNuxtPlugin(() => {
  const runtime = useRuntimeConfig().public
  const settings = communicationRealtimeConfiguration({
    communicationEnabled: runtime.communicationEnabled,
    reverb: runtime.reverb
  })
  const { isAuthenticated, user } = useSanctumAuth<MeIdentity>()
  const identity = computed(() => unwrapMeUser(user.value))
  const active = ref(false)
  const state = ref<RealtimeState>('disabled')
  const subscribedChannelCount = ref(0)
  const transportState = ref<'connecting' | 'connected' | 'unavailable'>('unavailable')
  let pusher: Pusher | null = null
  let echo: Echo<'reverb'> | null = null
  let startRequest: Promise<void> | null = null
  let retryTimer: ReturnType<typeof setTimeout> | null = null
  let connectionEpoch = 0

  function refreshRealtimeState(): void {
    if (!active.value) {
      state.value = 'disabled'
      return
    }
    if (subscribedChannelCount.value > 0) {
      state.value = 'connected'
      return
    }
    if (transportState.value === 'unavailable') {
      state.value = 'unavailable'
      return
    }
    state.value = 'connecting'
  }

  function noSubscription(): () => void {
    return () => undefined
  }

  const service: RealtimeService = {
    get enabled() {
      return active.value
    },
    state: readonly(state),
    channelsReady: computed(() => false),
    subscribeInbox: () => noSubscription(),
    subscribeTenant: () => noSubscription(),
    disconnect: () => teardown()
  }

  const sanctum = useSanctumClient()
  const handlers = new Map<number, Set<(event: RealtimeEvent) => void>>()
  const channelCallbacks = new Map<number, (event: RealtimeEvent) => void>()
  const subscribedInboxes = new Set<number>()
  const tenantHandlers = new Map<number, Set<(event: RealtimeEvent) => void>>()
  const tenantCallbacks = new Map<number, (event: RealtimeEvent) => void>()
  const subscribedTenants = new Set<number>()

  function bindConnectionEvents(client: Pusher): void {
    client.connection.bind('state_change', ({ current }: { current: string }) => {
      transportState.value = communicationRealtimeStateForConnection(current)
      if (transportState.value !== 'connected') {
        subscribedChannelCount.value = 0
        subscribedInboxes.clear()
        subscribedTenants.clear()
      }
      refreshRealtimeState()
    })
    client.connection.bind('error', () => {
      transportState.value = 'unavailable'
      subscribedChannelCount.value = 0
      subscribedInboxes.clear()
      subscribedTenants.clear()
      refreshRealtimeState()
    })
  }

  function markSubscribed(kind: 'inbox' | 'tenant', id: number): void {
    const set = kind === 'inbox' ? subscribedInboxes : subscribedTenants
    if (set.has(id)) return
    set.add(id)
    subscribedChannelCount.value += 1
    refreshRealtimeState()
  }

  function markUnsubscribed(kind: 'inbox' | 'tenant', id: number): void {
    const set = kind === 'inbox' ? subscribedInboxes : subscribedTenants
    if (!set.has(id)) return
    set.delete(id)
    subscribedChannelCount.value = Math.max(0, subscribedChannelCount.value - 1)
    refreshRealtimeState()
  }

  function ensureInboxChannel(inboxId: number): void {
    if (!echo || channelCallbacks.has(inboxId)) return
    const channelCallback = (event: RealtimeEvent) => {
      for (const subscriber of handlers.get(inboxId) ?? []) subscriber(event)
    }
    channelCallbacks.set(inboxId, channelCallback)
    echo.private(`communication.inbox.${inboxId}`)
      .listen('.communication.event', channelCallback)
      .subscribed(() => {
        markSubscribed('inbox', inboxId)
      })
      .error(() => {
        markUnsubscribed('inbox', inboxId)
        transportState.value = 'unavailable'
        refreshRealtimeState()
      })
  }

  function ensureTenantChannel(tenantId: number): void {
    if (!echo || tenantCallbacks.has(tenantId)) return
    const channelCallback = (event: RealtimeEvent) => {
      for (const subscriber of tenantHandlers.get(tenantId) ?? []) subscriber(event)
    }
    tenantCallbacks.set(tenantId, channelCallback)
    echo.private(`communication.tenant.${tenantId}`)
      .listen('.communication.event', channelCallback)
      .subscribed(() => {
        markSubscribed('tenant', tenantId)
      })
      .error(() => {
        markUnsubscribed('tenant', tenantId)
        transportState.value = 'unavailable'
        refreshRealtimeState()
      })
  }

  service.channelsReady = computed(() => subscribedChannelCount.value > 0)
  service.subscribeInbox = (inboxId, handler) => {
    if (!active.value) return noSubscription()
    const currentHandlers = handlers.get(inboxId) ?? new Set()
    currentHandlers.add(handler)
    handlers.set(inboxId, currentHandlers)

    ensureInboxChannel(inboxId)

    return () => {
      const subscribers = handlers.get(inboxId)
      subscribers?.delete(handler)
      if (subscribers?.size) return
      const callback = channelCallbacks.get(inboxId)
      if (callback) {
        echo?.private(`communication.inbox.${inboxId}`)
          .stopListening('.communication.event', callback)
      }
      echo?.leave(`communication.inbox.${inboxId}`)
      handlers.delete(inboxId)
      channelCallbacks.delete(inboxId)
      markUnsubscribed('inbox', inboxId)
    }
  }
  service.subscribeTenant = (tenantId, handler) => {
    if (!active.value) return noSubscription()
    const currentHandlers = tenantHandlers.get(tenantId) ?? new Set()
    currentHandlers.add(handler)
    tenantHandlers.set(tenantId, currentHandlers)

    ensureTenantChannel(tenantId)

    return () => {
      const subscribers = tenantHandlers.get(tenantId)
      subscribers?.delete(handler)
      if (subscribers?.size) return
      const callback = tenantCallbacks.get(tenantId)
      if (callback) {
        echo?.private(`communication.tenant.${tenantId}`)
          .stopListening('.communication.event', callback)
      }
      echo?.leave(`communication.tenant.${tenantId}`)
      tenantHandlers.delete(tenantId)
      tenantCallbacks.delete(tenantId)
      markUnsubscribed('tenant', tenantId)
    }
  }

  function teardown(): void {
    connectionEpoch += 1
    if (retryTimer) {
      clearTimeout(retryTimer)
      retryTimer = null
    }
    for (const inboxId of handlers.keys()) echo?.leave(`communication.inbox.${inboxId}`)
    for (const tenantId of tenantHandlers.keys()) echo?.leave(`communication.tenant.${tenantId}`)
    handlers.clear()
    channelCallbacks.clear()
    tenantHandlers.clear()
    tenantCallbacks.clear()
    subscribedInboxes.clear()
    subscribedTenants.clear()
    subscribedChannelCount.value = 0
    echo?.disconnect()
    echo = null
    pusher?.disconnect()
    pusher = null
    startRequest = null
    active.value = false
    transportState.value = 'unavailable'
    refreshRealtimeState()
  }

  function start(): Promise<void> {
    if (echo || !settings.enabled) return Promise.resolve()
    if (startRequest) return startRequest

    const epoch = ++connectionEpoch
    active.value = true
    transportState.value = 'connecting'

    const request = Promise.all([
      import('laravel-echo'),
      import('pusher-js')
    ]).then(([{ default: EchoClient }, { default: PusherClient }]) => {
      if (epoch !== connectionEpoch || !realtimeContext.value) return
      const wsHost = resolveCommunicationRealtimeHost(settings.host, window.location.hostname)
      const transports = communicationRealtimeTransports(settings.forceTLS)
      pusher = new PusherClient(settings.key, {
        cluster: 'mt1', wsHost, wsPort: settings.port, wssPort: settings.port,
        forceTLS: settings.forceTLS, enabledTransports: transports, disableStats: true,
        channelAuthorization: { customHandler: async (params: ChannelAuthorizationParams, callback: ChannelAuthorizationCallback) => {
          try {
            const response = await sanctum<ChannelAuthorizationData>('/api/broadcasting/auth', {
              method: 'POST', body: { socket_id: params.socketId, channel_name: params.channelName }
            })
            callback(null, response)
          } catch (caught) {
            callback(caught instanceof Error ? caught : new Error('Falha ao autorizar canal privado.'), null)
          }
        } }
      })
      echo = new EchoClient<'reverb'>({
        broadcaster: 'reverb', key: settings.key, client: pusher, wsHost,
        wsPort: settings.port, wssPort: settings.port, forceTLS: settings.forceTLS,
        enabledTransports: transports
      })
      bindConnectionEvents(pusher)
      for (const inboxId of handlers.keys()) ensureInboxChannel(inboxId)
      for (const tenantId of tenantHandlers.keys()) ensureTenantChannel(tenantId)
      refreshRealtimeState()
    }).catch(() => {
      if (epoch !== connectionEpoch) return
      echo?.disconnect()
      echo = null
      pusher?.disconnect()
      pusher = null
      transportState.value = 'unavailable'
      refreshRealtimeState()
      if (realtimeContext.value && retryTimer === null) {
        retryTimer = setTimeout(() => {
          retryTimer = null
          if (realtimeContext.value && !echo) void start()
        }, 5_000)
      }
    }).finally(() => {
      if (startRequest === request) startRequest = null
    })
    startRequest = request
    return request
  }

  const realtimeContext = computed(() => {
    const current = identity.value
    return settings.enabled
      && isAuthenticated.value
      && canViewCommunication(current)
      && current?.context_status === 'ok'
      && current.current_tenant?.id != null
  })

  watch(
    () => [realtimeContext.value, identity.value?.current_tenant?.id ?? null] as const,
    ([allowed, tenantId], previous) => {
      const [wasAllowed, previousTenantId] = previous ?? ([false, null] as const)
      if (!allowed) {
        teardown()
        return
      }
      if ((startRequest || echo) && (wasAllowed !== allowed || previousTenantId !== tenantId)) teardown()
      void start()
    },
    { immediate: true }
  )

  function retryWhenOnline(): void {
    if (!realtimeContext.value || echo) return
    if (retryTimer) {
      clearTimeout(retryTimer)
      retryTimer = null
    }
    void start()
  }

  window.addEventListener('online', retryWhenOnline)
  onScopeDispose(() => {
    window.removeEventListener('online', retryWhenOnline)
    teardown()
  })

  return { provide: { communicationRealtime: service } }
})

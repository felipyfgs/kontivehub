import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { RealtimeEvent, RealtimeService, RealtimeState } from '~/types/communication/realtime'
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
  const state = ref<RealtimeState>(settings.enabled ? 'connecting' : 'disabled')
  const subscribedChannelCount = ref(0)
  const transportState = ref<'connecting' | 'connected' | 'unavailable'>(
    settings.enabled ? 'connecting' : 'unavailable'
  )

  function refreshRealtimeState(): void {
    if (!settings.enabled) {
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

  const noopService: RealtimeService = {
    enabled: false,
    state: readonly(state),
    channelsReady: computed(() => false),
    subscribeInbox: () => () => undefined,
    subscribeTenant: () => () => undefined,
    disconnect: () => undefined
  }

  if (!settings.enabled) {
    return { provide: { communicationRealtime: noopService } }
  }

  const wsHost = resolveCommunicationRealtimeHost(settings.host, window.location.hostname)
  const transports = communicationRealtimeTransports(settings.forceTLS)
  const sanctum = useSanctumClient()
  const pusher = new Pusher(settings.key, {
    cluster: 'mt1',
    wsHost,
    wsPort: settings.port,
    wssPort: settings.port,
    forceTLS: settings.forceTLS,
    enabledTransports: transports,
    disableStats: true,
    channelAuthorization: {
      customHandler: async (
        params: ChannelAuthorizationParams,
        callback: ChannelAuthorizationCallback
      ) => {
        try {
          const response = await sanctum<ChannelAuthorizationData>('/api/broadcasting/auth', {
            method: 'POST',
            body: {
              socket_id: params.socketId,
              channel_name: params.channelName
            }
          })
          callback(null, response)
        } catch (caught) {
          callback(caught instanceof Error ? caught : new Error('Falha ao autorizar canal privado.'), null)
        }
      }
    }
  })
  const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: settings.key,
    client: pusher,
    wsHost,
    wsPort: settings.port,
    wssPort: settings.port,
    forceTLS: settings.forceTLS,
    enabledTransports: transports
  })

  pusher.connection.bind('state_change', ({ current }: { current: string }) => {
    transportState.value = communicationRealtimeStateForConnection(current)
    if (transportState.value !== 'connected') {
      subscribedChannelCount.value = 0
    }
    refreshRealtimeState()
  })
  pusher.connection.bind('error', () => {
    transportState.value = 'unavailable'
    subscribedChannelCount.value = 0
    refreshRealtimeState()
  })

  const handlers = new Map<number, Set<(event: RealtimeEvent) => void>>()
  const channelCallbacks = new Map<number, (event: RealtimeEvent) => void>()
  const subscribedInboxes = new Set<number>()
  const tenantHandlers = new Map<number, Set<(event: RealtimeEvent) => void>>()
  const tenantCallbacks = new Map<number, (event: RealtimeEvent) => void>()
  const subscribedTenants = new Set<number>()

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

  const service: RealtimeService = {
    enabled: true,
    state: readonly(state),
    channelsReady: computed(() => subscribedChannelCount.value > 0),
    subscribeInbox(inboxId, handler) {
      const currentHandlers = handlers.get(inboxId) ?? new Set()
      currentHandlers.add(handler)
      handlers.set(inboxId, currentHandlers)

      if (!channelCallbacks.has(inboxId)) {
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

      return () => {
        const subscribers = handlers.get(inboxId)
        subscribers?.delete(handler)
        if (subscribers?.size) return
        const callback = channelCallbacks.get(inboxId)
        if (callback) {
          echo.private(`communication.inbox.${inboxId}`)
            .stopListening('.communication.event', callback)
        }
        echo.leave(`communication.inbox.${inboxId}`)
        handlers.delete(inboxId)
        channelCallbacks.delete(inboxId)
        markUnsubscribed('inbox', inboxId)
      }
    },
    subscribeTenant(tenantId, handler) {
      const currentHandlers = tenantHandlers.get(tenantId) ?? new Set()
      currentHandlers.add(handler)
      tenantHandlers.set(tenantId, currentHandlers)

      if (!tenantCallbacks.has(tenantId)) {
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

      return () => {
        const subscribers = tenantHandlers.get(tenantId)
        subscribers?.delete(handler)
        if (subscribers?.size) return
        const callback = tenantCallbacks.get(tenantId)
        if (callback) {
          echo.private(`communication.tenant.${tenantId}`)
            .stopListening('.communication.event', callback)
        }
        echo.leave(`communication.tenant.${tenantId}`)
        tenantHandlers.delete(tenantId)
        tenantCallbacks.delete(tenantId)
        markUnsubscribed('tenant', tenantId)
      }
    },
    disconnect() {
      for (const inboxId of handlers.keys()) echo.leave(`communication.inbox.${inboxId}`)
      for (const tenantId of tenantHandlers.keys()) echo.leave(`communication.tenant.${tenantId}`)
      handlers.clear()
      channelCallbacks.clear()
      tenantHandlers.clear()
      tenantCallbacks.clear()
      subscribedInboxes.clear()
      subscribedTenants.clear()
      subscribedChannelCount.value = 0
      echo.disconnect()
      transportState.value = 'unavailable'
      refreshRealtimeState()
    }
  }

  return { provide: { communicationRealtime: service } }
})

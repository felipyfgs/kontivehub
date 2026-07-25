import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type {
  CommunicationRealtimeEvent,
  CommunicationRealtimeService,
  CommunicationRealtimeState
} from '~/types/communication'
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
  const state = ref<CommunicationRealtimeState>(settings.enabled ? 'connecting' : 'disabled')
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

  const noopService: CommunicationRealtimeService = {
    enabled: false,
    state: readonly(state),
    channelsReady: computed(() => false),
    subscribeInbox: () => () => undefined,
    subscribeOffice: () => () => undefined,
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

  const handlers = new Map<number, Set<(event: CommunicationRealtimeEvent) => void>>()
  const channelCallbacks = new Map<number, (event: CommunicationRealtimeEvent) => void>()
  const subscribedInboxes = new Set<number>()
  const officeHandlers = new Map<number, Set<(event: CommunicationRealtimeEvent) => void>>()
  const officeCallbacks = new Map<number, (event: CommunicationRealtimeEvent) => void>()
  const subscribedOffices = new Set<number>()

  function markSubscribed(kind: 'inbox' | 'office', id: number): void {
    const set = kind === 'inbox' ? subscribedInboxes : subscribedOffices
    if (set.has(id)) return
    set.add(id)
    subscribedChannelCount.value += 1
    refreshRealtimeState()
  }

  function markUnsubscribed(kind: 'inbox' | 'office', id: number): void {
    const set = kind === 'inbox' ? subscribedInboxes : subscribedOffices
    if (!set.has(id)) return
    set.delete(id)
    subscribedChannelCount.value = Math.max(0, subscribedChannelCount.value - 1)
    refreshRealtimeState()
  }

  const service: CommunicationRealtimeService = {
    enabled: true,
    state: readonly(state),
    channelsReady: computed(() => subscribedChannelCount.value > 0),
    subscribeInbox(inboxId, handler) {
      const currentHandlers = handlers.get(inboxId) ?? new Set()
      currentHandlers.add(handler)
      handlers.set(inboxId, currentHandlers)

      if (!channelCallbacks.has(inboxId)) {
        const channelCallback = (event: CommunicationRealtimeEvent) => {
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
    subscribeOffice(officeId, handler) {
      const currentHandlers = officeHandlers.get(officeId) ?? new Set()
      currentHandlers.add(handler)
      officeHandlers.set(officeId, currentHandlers)

      if (!officeCallbacks.has(officeId)) {
        const channelCallback = (event: CommunicationRealtimeEvent) => {
          for (const subscriber of officeHandlers.get(officeId) ?? []) subscriber(event)
        }
        officeCallbacks.set(officeId, channelCallback)
        echo.private(`communication.office.${officeId}`)
          .listen('.communication.event', channelCallback)
          .subscribed(() => {
            markSubscribed('office', officeId)
          })
          .error(() => {
            markUnsubscribed('office', officeId)
            transportState.value = 'unavailable'
            refreshRealtimeState()
          })
      }

      return () => {
        const subscribers = officeHandlers.get(officeId)
        subscribers?.delete(handler)
        if (subscribers?.size) return
        const callback = officeCallbacks.get(officeId)
        if (callback) {
          echo.private(`communication.office.${officeId}`)
            .stopListening('.communication.event', callback)
        }
        echo.leave(`communication.office.${officeId}`)
        officeHandlers.delete(officeId)
        officeCallbacks.delete(officeId)
        markUnsubscribed('office', officeId)
      }
    },
    disconnect() {
      for (const inboxId of handlers.keys()) echo.leave(`communication.inbox.${inboxId}`)
      for (const officeId of officeHandlers.keys()) echo.leave(`communication.office.${officeId}`)
      handlers.clear()
      channelCallbacks.clear()
      officeHandlers.clear()
      officeCallbacks.clear()
      subscribedInboxes.clear()
      subscribedOffices.clear()
      subscribedChannelCount.value = 0
      echo.disconnect()
      transportState.value = 'unavailable'
      refreshRealtimeState()
    }
  }

  return { provide: { communicationRealtime: service } }
})

import { useDebounceFn } from '@vueuse/core'
import type { RealtimeEvent } from '~/types/communication/realtime'

export function useCommunicationProfilePictureRealtime(
  refresh: () => void | Promise<void>,
  shouldRefresh: (event: RealtimeEvent) => boolean = () => true
) {
  const api = useApi()
  const realtime = useNuxtApp().$communicationRealtime
  const { sessionEpoch } = useDashboard()
  const unsubscribers = new Map<number, () => void>()
  let connectSequence = 0
  let disposed = false

  const refreshDebounced = useDebounceFn(() => {
    if (disposed) return
    void refresh()
  }, 100)

  function disconnect(): void {
    for (const unsubscribe of unsubscribers.values()) unsubscribe()
    unsubscribers.clear()
  }

  function onEvent(event: RealtimeEvent): void {
    if (event.type !== 'contact.profile_picture.updated' || !shouldRefresh(event)) return
    refreshDebounced()
  }

  async function connect(): Promise<void> {
    const sequence = ++connectSequence
    const epoch = sessionEpoch.value
    disconnect()
    if (!realtime.enabled) return

    try {
      const response = await api.communication.inboxes.list()
      if (sequence !== connectSequence || epoch !== sessionEpoch.value) return
      for (const inbox of response.data) {
        unsubscribers.set(inbox.id, realtime.subscribeInbox(inbox.id, onEvent))
      }
    } catch {
      // A tela continua funcional e será atualizada no próximo load explícito.
    }
  }

  const stopSessionWatch = watch(sessionEpoch, () => {
    void connect()
  })

  onMounted(() => {
    void connect()
  })
  onScopeDispose(() => {
    disposed = true
    ++connectSequence
    stopSessionWatch()
    disconnect()
  })
}

import type { CommunicationRealtimeService } from './communication'

declare module '#app' {
  interface NuxtApp {
    $communicationRealtime: CommunicationRealtimeService
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $communicationRealtime: CommunicationRealtimeService
  }
}

export {}

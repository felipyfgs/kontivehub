import type { RealtimeService } from './communication/realtime'

declare module '#app' {
  interface NuxtApp {
    $communicationRealtime: RealtimeService
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $communicationRealtime: RealtimeService
  }
}

export {}

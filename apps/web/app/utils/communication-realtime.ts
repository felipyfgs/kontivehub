export interface CommunicationRealtimeRuntimeInput {
  communicationEnabled?: unknown
  reverb?: {
    key?: unknown
    host?: unknown
    port?: unknown
    scheme?: unknown
  }
}

export interface CommunicationRealtimeConfiguration {
  enabled: boolean
  key: string
  host: string
  port: number
  forceTLS: boolean
}

/** Sanitiza runtime config e mantém Reverb fail-closed diante de configuração parcial. */
export function communicationRealtimeConfiguration(
  input: CommunicationRealtimeRuntimeInput
): CommunicationRealtimeConfiguration {
  const key = String(input.reverb?.key || '').trim()
  const host = String(input.reverb?.host || '').trim()
  const port = Number(input.reverb?.port || 0)
  const scheme = String(input.reverb?.scheme || 'https').toLowerCase()
  const validPort = Number.isInteger(port) && port > 0 && port <= 65_535
  const enabled = input.communicationEnabled === true
    && key !== ''
    && key !== 'communication-disabled'
    && host !== ''
    && validPort

  return {
    enabled,
    key,
    host,
    port: validPort ? port : 0,
    forceTLS: scheme === 'https' || scheme === 'wss'
  }
}

/**
 * Prefere o hostname do browser para o WebSocket (localhost vs PUBLIC_HOST),
 * evitando conectar no IP público quando a UI está em localhost.
 */
export function resolveCommunicationRealtimeHost(
  configuredHost: string,
  browserHost?: string | null
): string {
  const browser = String(browserHost || '').trim()
  if (browser !== '' && browser !== '0.0.0.0') {
    return browser
  }
  return String(configuredHost || '').trim()
}

export function communicationRealtimeTransports(forceTLS: boolean): Array<'ws' | 'wss'> {
  return forceTLS ? ['wss'] : ['ws']
}

export function communicationRealtimeStateForConnection(state: string): 'connecting' | 'connected' | 'unavailable' {
  if (state === 'connected') return 'connected'
  if (state === 'connecting' || state === 'initialized') return 'connecting'
  return 'unavailable'
}

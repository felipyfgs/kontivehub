/**
 * Utilitários do fluxo público de ativação por link (#token=…).
 * O token nunca deve permanecer no fragmento após a SPA ler.
 */

/** Extrai o token de um hash de URL (`#token=…` ou `#token=…&…`). */
export function extractActivationTokenFromHash(hash: string): string | null {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash
  if (!raw) return null

  // Preferir URLSearchParams no fragmento (token=…); fallback para prefixo token=.
  try {
    const params = new URLSearchParams(raw)
    const fromParams = params.get('token')
    if (fromParams && fromParams.length >= 32) {
      return fromParams
    }
  } catch {
    // ignore
  }

  const prefix = 'token='
  if (raw.startsWith(prefix)) {
    const value = decodeURIComponent(raw.slice(prefix.length).split('&')[0] || '')
    return value.length >= 32 ? value : null
  }

  return null
}

/**
 * Remove o fragmento da URL atual sem recarregar (history.replaceState).
 * Seguro chamar no client; no-op se não houver hash.
 */
export function stripActivationHashFromLocation(
  location: Pick<Location, 'hash' | 'pathname' | 'search'> = window.location,
  historyApi: Pick<History, 'replaceState'> = window.history
): void {
  if (!location.hash) return
  historyApi.replaceState(null, '', `${location.pathname}${location.search}`)
}

/**
 * Lê o token do hash e remove o fragmento imediatamente.
 * Retorna null se ausente ou inválido.
 */
export function consumeActivationTokenFromLocation(
  location: Pick<Location, 'hash' | 'pathname' | 'search'> = window.location,
  historyApi: Pick<History, 'replaceState'> = window.history
): string | null {
  const token = extractActivationTokenFromHash(location.hash || '')
  stripActivationHashFromLocation(location, historyApi)
  return token
}

export type ResetPasswordCredentials = { token: string, email: string }

function validCredentials(token: string | null, email: string | null): ResetPasswordCredentials | null {
  if (!token || !email || !email.includes('@')) return null
  return { token, email }
}

function credentialsFromFragment(hash: string): ResetPasswordCredentials | null {
  const params = new URLSearchParams(hash.replace(/^#/, ''))
  return validCredentials(params.get('token'), params.get('email'))
}

/** Consome credenciais do fragmento e o remove antes de requests. */
export function consumeResetPasswordCredentials(
  location: Pick<Location, 'hash' | 'pathname'> = window.location,
  historyApi: Pick<History, 'replaceState'> = window.history
): ResetPasswordCredentials | null {
  const credentials = credentialsFromFragment(location.hash)
  if (location.hash) historyApi.replaceState(null, '', location.pathname)
  return credentials
}

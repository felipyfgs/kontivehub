export type ResetPasswordCredentials = { token: string, email: string }

let pendingCredentials: ResetPasswordCredentials | null = null

function validCredentials(token: string | null, email: string | null): ResetPasswordCredentials | null {
  if (!token || !email || !email.includes('@')) return null
  return { token, email }
}

function credentialsFromFragment(hash: string): ResetPasswordCredentials | null {
  const params = new URLSearchParams(hash.replace(/^#/, ''))
  return validCredentials(params.get('token'), params.get('email'))
}

/** Mantém credenciais somente em memória durante a canonicalização pré-mount. */
export function stageResetPasswordCredentials(token: string, email: string): boolean {
  pendingCredentials = validCredentials(token, email)
  return pendingCredentials !== null
}

export function stageResetPasswordCredentialsFromFragment(hash: string): boolean {
  const credentials = credentialsFromFragment(hash)
  return stageResetPasswordCredentials(credentials?.token || '', credentials?.email || '')
}

/** Consome credenciais one-shot e limpa um fragmento residual antes de requests. */
export function consumeResetPasswordCredentials(
  location: Pick<Location, 'hash' | 'pathname'> = window.location,
  historyApi: Pick<History, 'replaceState'> = window.history
): ResetPasswordCredentials | null {
  const credentials = credentialsFromFragment(location.hash) ?? pendingCredentials
  pendingCredentials = null
  if (location.hash) historyApi.replaceState(null, '', location.pathname)
  return credentials
}

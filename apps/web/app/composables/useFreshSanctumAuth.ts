interface LoginCredentials extends Record<string, unknown> {
  email: string
  password: string
}

const csrfEndpoint = '/sanctum/csrf-cookie'
const csrfCookie = 'XSRF-TOKEN'

export function useFreshSanctumAuth<T>() {
  const auth = useSanctumAuth<T>()
  const client = useSanctumClient()

  async function loginWithFreshCsrf(credentials: LoginCredentials): Promise<unknown> {
    await client(csrfEndpoint)
    refreshCookie(csrfCookie)

    return auth.login(credentials, false)
  }

  return {
    ...auth,
    loginWithFreshCsrf
  }
}

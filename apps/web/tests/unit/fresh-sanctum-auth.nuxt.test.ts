import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useFreshSanctumAuth } from '../../app/composables/useFreshSanctumAuth'

const mocks = vi.hoisted(() => ({
  client: vi.fn(),
  login: vi.fn(),
  refreshCookie: vi.fn()
}))

mockNuxtImport('refreshCookie', () => mocks.refreshCookie)

describe('useFreshSanctumAuth', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('useSanctumClient', () => mocks.client)
    vi.stubGlobal('useSanctumAuth', () => ({ login: mocks.login }))
  })

  it('renova e sincroniza o cookie CSRF antes de enviar credenciais', async () => {
    const order: string[] = []
    const credentials = {
      email: 'admin@example.test',
      password: 'not-a-real-secret'
    }

    mocks.client.mockImplementation(async () => {
      order.push('csrf')
    })
    mocks.refreshCookie.mockImplementation(() => {
      order.push('refresh-cookie')
    })
    mocks.login.mockImplementation(async () => {
      order.push('login')
    })

    const { loginWithFreshCsrf } = useFreshSanctumAuth()
    await loginWithFreshCsrf(credentials)

    expect(order).toEqual(['csrf', 'refresh-cookie', 'login'])
    expect(mocks.client).toHaveBeenCalledWith('/sanctum/csrf-cookie')
    expect(mocks.refreshCookie).toHaveBeenCalledWith('XSRF-TOKEN')
    expect(mocks.login).toHaveBeenCalledWith(credentials, false)
  })

  it('não envia credenciais quando a renovação CSRF falha', async () => {
    const failure = new Error('csrf unavailable')
    mocks.client.mockRejectedValue(failure)

    const { loginWithFreshCsrf } = useFreshSanctumAuth()

    await expect(loginWithFreshCsrf({
      email: 'admin@example.test',
      password: 'not-a-real-secret'
    })).rejects.toBe(failure)
    expect(mocks.refreshCookie).not.toHaveBeenCalled()
    expect(mocks.login).not.toHaveBeenCalled()
  })
})

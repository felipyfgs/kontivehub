import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  fetchInitialOnboardingAvailable,
  guestAuthPathWhenOnboardingAvailable,
  invalidateInitialOnboardingAvailable,
  onboardingNavigateTarget,
  shouldBypassInitialOnboardingRedirect
} from '../../app/utils/initial-onboarding-gate'

afterEach(() => {
  invalidateInitialOnboardingAvailable()
  vi.unstubAllGlobals()
})

describe('initial-onboarding-gate', () => {
  it('não redireciona quando onboarding indisponível', () => {
    expect(guestAuthPathWhenOnboardingAvailable('/', false)).toBeNull()
    expect(guestAuthPathWhenOnboardingAvailable('/login', false)).toBeNull()
  })

  it('manda /, /login e rotas protegidas para /onboarding quando disponível', () => {
    expect(guestAuthPathWhenOnboardingAvailable('/', true)).toBe('/onboarding')
    expect(guestAuthPathWhenOnboardingAvailable('/login', true)).toBe('/onboarding')
    expect(guestAuthPathWhenOnboardingAvailable('/clients', true)).toBe('/onboarding')
  })

  it('preserva onboarding, ativação e reset de senha', () => {
    expect(shouldBypassInitialOnboardingRedirect('/onboarding')).toBe(true)
    expect(shouldBypassInitialOnboardingRedirect('/onboarding/')).toBe(true)
    expect(shouldBypassInitialOnboardingRedirect('/activate')).toBe(true)
    expect(shouldBypassInitialOnboardingRedirect('/first-access')).toBe(true)
    expect(shouldBypassInitialOnboardingRedirect('/reset-password')).toBe(true)
    expect(shouldBypassInitialOnboardingRedirect('/reset-password/')).toBe(true)
    expect(guestAuthPathWhenOnboardingAvailable('/onboarding', true)).toBeNull()
    expect(guestAuthPathWhenOnboardingAvailable('/activate', true)).toBeNull()
    expect(guestAuthPathWhenOnboardingAvailable('/reset-password', true)).toBeNull()
  })

  it('preserva hash #token= no destino do navigate', () => {
    expect(onboardingNavigateTarget('#token=abc')).toEqual({
      path: '/onboarding',
      hash: '#token=abc'
    })
    expect(onboardingNavigateTarget('')).toEqual({ path: '/onboarding' })
  })

  it('deduplica a consulta guest e invalida o resultado após mudança de sessão', async () => {
    const fetch = vi.fn().mockResolvedValue({ data: { available: true } })
    vi.stubGlobal('$fetch', fetch)

    const [first, second] = await Promise.all([
      fetchInitialOnboardingAvailable('/api'),
      fetchInitialOnboardingAvailable('/api')
    ])

    expect(first).toBe(true)
    expect(second).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)

    invalidateInitialOnboardingAvailable()
    await fetchInitialOnboardingAvailable('/api')
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('não restaura um resultado pendente depois de invalidar o contexto', async () => {
    let resolve!: (value: { data: { available: boolean } }) => void
    vi.stubGlobal('$fetch', vi.fn(() => new Promise((done) => {
      resolve = done
    })))

    const pending = fetchInitialOnboardingAvailable('/api')
    invalidateInitialOnboardingAvailable()
    resolve({ data: { available: true } })
    await pending

    const next = vi.fn().mockResolvedValue({ data: { available: false } })
    vi.stubGlobal('$fetch', next)
    await expect(fetchInitialOnboardingAvailable('/api')).resolves.toBe(false)
    expect(next).toHaveBeenCalledTimes(1)
  })
})

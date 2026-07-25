import { describe, expect, it } from 'vitest'
import {
  guestAuthPathWhenOnboardingAvailable,
  onboardingNavigateTarget,
  shouldBypassInitialOnboardingRedirect
} from '../../app/utils/initial-onboarding-gate'

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
})

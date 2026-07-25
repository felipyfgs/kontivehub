/**
 * Gate do onboarding inicial (instalação pristine).
 * Quando disponível, o middleware manda visitantes para /onboarding
 * em vez de /login — o token continua só no fragmento #token=.
 */

import { resolveApiUrl } from '~/utils/api-url'

/** Paths guest que não devem ser redirecionados para /onboarding. */
export function shouldBypassInitialOnboardingRedirect(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  return normalized === '/onboarding'
    || normalized === '/activate'
    || normalized === '/first-access'
    || normalized === '/reset-password'
}

export function guestAuthPathWhenOnboardingAvailable(
  path: string,
  onboardingAvailable: boolean
): '/onboarding' | null {
  if (!onboardingAvailable) return null
  if (shouldBypassInitialOnboardingRedirect(path)) return null
  return '/onboarding'
}

/** Preserva `#token=` se o guest abriu a URL com fragmento. */
export function onboardingNavigateTarget(hash?: string): { path: '/onboarding', hash?: string } {
  const raw = (hash || '').trim()
  if (raw && (raw.includes('token=') || raw.startsWith('#token='))) {
    return { path: '/onboarding', hash: raw.startsWith('#') ? raw : `#${raw}` }
  }
  return { path: '/onboarding' }
}

export async function fetchInitialOnboardingAvailable(apiBase = ''): Promise<boolean> {
  try {
    const url = resolveApiUrl('/api/v1/onboarding/status', apiBase)
    const res = await $fetch<{ data?: { available?: boolean } }>(url, {
      credentials: 'include'
    })
    return res?.data?.available === true
  } catch {
    return false
  }
}

import { safeRedirectForIdentity } from '~/utils/auth-redirect'
import type { MeUser } from '~/types/api'

const AUTH_RETURN_KEY = 'kontivehub.auth.return.v1'

export function saveAuthReturn(path: string): void {
  if (!import.meta.client) return
  // Apenas o pathname interno é persistido; query/hash nunca atravessam o login.
  if (!path.startsWith('/') || path.startsWith('//')) return
  const pathname = new URL(path, window.location.origin).pathname
  sessionStorage.setItem(AUTH_RETURN_KEY, pathname)
}

export function consumeAuthReturn(identity?: MeUser | null): string | null {
  if (!import.meta.client) return null
  const value = sessionStorage.getItem(AUTH_RETURN_KEY)
  sessionStorage.removeItem(AUTH_RETURN_KEY)
  return safeRedirectForIdentity(value, identity)
}

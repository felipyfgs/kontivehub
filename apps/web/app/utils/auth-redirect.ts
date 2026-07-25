import type { MeUser } from '~/types/api'
import { isPlatformAdmin } from '~/utils/permissions'

/**
 * Destino pós-login (sem open redirect).
 * PLATFORM_ADMIN → /admin; OPERATOR → /work; demais → /.
 */
export function homeForIdentity(user?: MeUser | null): string {
  if (isPlatformAdmin(user)) {
    return '/admin'
  }
  if (user?.role === 'OPERATOR') {
    return '/work'
  }
  return '/'
}

/** Redirect query só para path interno relativo. */
export function safeRedirectTarget(raw: unknown): string | null {
  const value = Array.isArray(raw) ? raw[0] : raw
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
    return null
  }
  if (
    value.startsWith('/login')
    || value.startsWith('/two-factor')
    || value.startsWith('/activate')
    || value.startsWith('/first-access')
    || value.startsWith('/onboarding')
  ) {
    return null
  }
  return value
}

/** PLATFORM_ADMIN sem Office resolvido — só superfícies globais. */
export function lacksOfficeContext(user?: MeUser | null): boolean {
  return user?.context_status === 'office_context_required'
}

/** Rotas globais disponíveis ao PLATFORM_ADMIN mesmo sem contexto tenant. */
export function isPlatformAdminPath(path: string): boolean {
  const pathname = path.split(/[?#]/, 1)[0]?.replace(/\/+$/, '') || '/'
  return pathname === '/admin' || pathname.startsWith('/admin/')
}

/** Sem Office, qualquer destino fora da administração global volta ao hub. */
export function requiresPlatformAdminHome(
  user: MeUser | null | undefined,
  path: string
): boolean {
  return isPlatformAdmin(user)
    && lacksOfficeContext(user)
    && !isPlatformAdminPath(path)
}

/** Redirect interno compatível com o contexto resolvido da identidade. */
export function safeRedirectForIdentity(
  raw: unknown,
  user?: MeUser | null
): string | null {
  const target = safeRedirectTarget(raw)
  if (!target || requiresPlatformAdminHome(user, target)) {
    return null
  }
  return target
}

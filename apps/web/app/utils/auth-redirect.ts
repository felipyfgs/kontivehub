import type { MeUser } from '~/types/api'
import { isPlatformAdmin } from '~/utils/permissions'

/**
 * Destino pós-login (sem open redirect).
 * Administração da plataforma → /admin/tenants; usuários com Work → /work; demais → /.
 */
export function homeForIdentity(user?: MeUser | null): string {
  if (isPlatformAdmin(user)) {
    return '/admin/tenants'
  }
  if (user?.effective_permissions.includes('work.view')) {
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
    || value.startsWith('/activate')
    || value.startsWith('/first-access')
    || value.startsWith('/onboarding')
  ) {
    return null
  }
  return value
}

/** PLATFORM_ADMIN sem Tenant resolvido — só superfícies globais. */
export function lacksTenantContext(user?: MeUser | null): boolean {
  return user?.context_status === 'tenant_context_required'
}

/** Rotas globais disponíveis ao PLATFORM_ADMIN mesmo sem contexto tenant. */
export function isPlatformAdminPath(path: string): boolean {
  const pathname = path.split(/[?#]/, 1)[0]?.replace(/\/+$/, '') || '/'
  return pathname.startsWith('/admin/')
}

/** Sem Tenant, qualquer destino fora da administração global volta ao hub. */
export function requiresPlatformAdminHome(
  user: MeUser | null | undefined,
  path: string
): boolean {
  return isPlatformAdmin(user)
    && lacksTenantContext(user)
    && !isPlatformAdminPath(path)
}

/** Redirect interno válido para o contexto resolvido da identidade. */
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

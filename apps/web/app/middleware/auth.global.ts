import {
  canAccessOfficeSettings,
  canAccessPlatformAdmin,
  hasConfirmedAdminAccess,
  unwrapMeUser
} from '~/utils/permissions'
import type { MeIdentity } from '~/utils/permissions'
import { isAuthPublicPath } from '~/utils/auth-public'
import {
  homeForIdentity,
  isPlatformAdminPath,
  requiresPlatformAdminHome
} from '~/utils/auth-redirect'
import {
  fetchInitialOnboardingAvailable,
  guestAuthPathWhenOnboardingAvailable,
  onboardingNavigateTarget
} from '~/utils/initial-onboarding-gate'

export default defineNuxtRouteMiddleware(async (to) => {
  const { isAuthenticated, refreshIdentity, user } = useSanctumAuth()
  // Rotas públicas de auth/ativação/onboarding; 2FA legado redireciona.
  const guestOnly = isAuthPublicPath(to.path)
  const legacyTwoFactor = to.path === '/two-factor/setup' || to.path.startsWith('/two-factor/')

  // Instalação pristine: redireciona antes de /me (evita 401 no console do browser).
  if (!isAuthenticated.value) {
    const apiBase = String(useRuntimeConfig().public.apiBase || '')
    const onboardingAvailable = await fetchInitialOnboardingAvailable(apiBase)
    if (guestAuthPathWhenOnboardingAvailable(to.path, onboardingAvailable)) {
      return navigateTo(onboardingNavigateTarget(to.hash))
    }
  }

  if (!guestOnly || isAuthenticated.value) {
    try {
      await refreshIdentity()
    } catch {
      // A ausência de sessão é tratada logo abaixo sem revelar o motivo.
      user.value = null
    }
  }

  if (!isAuthenticated.value) {
    if (guestOnly) return undefined
    return navigateTo({
      path: '/login',
      query: { redirect: to.fullPath }
    })
  }

  const identity = unwrapMeUser(user.value as MeIdentity)

  // Já autenticado: rotas guest e setup 2FA legado → home do papel.
  if (guestOnly || legacyTwoFactor) {
    return navigateTo(homeForIdentity(identity))
  }

  // CT-e não é Configurações: alias legado → catálogo filtrado (sem layout Settings).
  if (to.path.replace(/\/+$/, '') === '/settings/cte') {
    const accepted = new Set([
      'kind',
      'direction',
      'q',
      'client_id',
      'establishment_id',
      'fiscal_role',
      'acquisition_source',
      'artifact_quality',
      'coverage_status',
      'status',
      'competence',
      'issued_from',
      'issued_to',
      'missing_party_name',
      'issuer_cnpj',
      'taker_cnpj'
    ])
    const query: Record<string, string> = { kind: 'CTE' }
    for (const [key, raw] of Object.entries(to.query)) {
      if (!accepted.has(key) || key === 'kind') continue
      const value = Array.isArray(raw) ? raw[0] : raw
      if (typeof value === 'string' && value) query[key] = value
    }
    return navigateTo({ path: '/docs/catalog', query }, { replace: true })
  }

  // Procurações manuais removidas do tenant — redireciona para Conta/Escritório.
  if (to.path.replace(/\/+$/, '') === '/settings/proxies') {
    return navigateTo('/conta/escritorio', { replace: true })
  }

  const legacyAccountRoutes: Record<string, string> = {
    '/settings': '/conta/escritorio',
    '/settings/usage': '/conta/consumo',
    '/settings/consumo': '/conta/consumo',
    '/settings/subscription': '/conta/assinatura',
    '/settings/team': '/conta/equipe',
    '/settings/departments': '/conta/departamentos',
    '/admin/owner': '/admin/offices'
  }
  const normalizedPath = to.path.replace(/\/+$/, '') || '/'
  if (legacyAccountRoutes[normalizedPath]) {
    return navigateTo(legacyAccountRoutes[normalizedPath], { replace: true })
  }

  // Departamentos: /admin/departments → Conta
  if (to.path === '/admin/departments' || to.path.startsWith('/admin/departments/')) {
    return navigateTo('/conta/departamentos', { replace: true })
  }

  // `/admin/*` reservado à plataforma (PLATFORM_ADMIN).
  const isAdminPath = isPlatformAdminPath(to.path)
  if (isAdminPath) {
    if (canAccessPlatformAdmin(identity)) {
      return undefined
    }
    // Office ADMIN que ainda aponte para /admin → Conta/Escritório.
    if (hasConfirmedAdminAccess(identity)) {
      return navigateTo('/conta/escritorio', { replace: true })
    }
    return navigateTo('/')
  }

  // O perfil global sem Office só pode usar superfícies de /admin.
  if (requiresPlatformAdminHome(identity, to.path)) {
    return navigateTo('/admin', { replace: true })
  }

  // Configuração do escritório (exceto /conta perfil): ADMIN office ou PLATFORM_ADMIN.
  if (to.path.startsWith('/conta/') && !canAccessOfficeSettings(identity)) {
    return navigateTo('/conta')
  }
})

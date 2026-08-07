import {
  canAccessTenantSettings,
  canAccessPlatformAdmin,
  hasTenantAdminAccess,
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
  invalidateInitialOnboardingAvailable,
  onboardingNavigateTarget
} from '~/utils/initial-onboarding-gate'
import { refreshIdentitySingleFlight } from '~/utils/identity-refresh'
import { saveAuthReturn } from '~/utils/auth-return'

export default defineNuxtRouteMiddleware(async (to) => {
  const nuxtApp = useNuxtApp()
  const { isAuthenticated, refreshIdentity, user } = useSanctumAuth()
  // Rotas públicas de autenticação, ativação e onboarding.
  const guestOnly = isAuthPublicPath(to.path)

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
      await refreshIdentitySingleFlight(nuxtApp, refreshIdentity)
    } catch {
      // A ausência de sessão é tratada logo abaixo sem revelar o motivo.
      user.value = null
    }
  }

  if (!isAuthenticated.value) {
    if (guestOnly) return undefined
    saveAuthReturn(to.fullPath)
    return navigateTo('/login')
  }

  // Uma transição autenticada pode ter concluído instalação ou trocado a sessão.
  invalidateInitialOnboardingAvailable()

  const identity = unwrapMeUser(user.value as MeIdentity)

  // Já autenticado: rotas de visitante voltam ao destino inicial.
  if (guestOnly) {
    return navigateTo(homeForIdentity(identity))
  }

  // `/admin/*` reservado à plataforma (PLATFORM_ADMIN).
  const isAdminPath = isPlatformAdminPath(to.path)
  if (isAdminPath) {
    if (canAccessPlatformAdmin(identity)) {
      return undefined
    }
    // Administração do tenant não concede acesso à administração da plataforma.
    if (hasTenantAdminAccess(identity)) {
      return navigateTo('/conta/escritorio', { replace: true })
    }
    return navigateTo('/')
  }

  // O perfil global sem Tenant só pode usar superfícies de /admin.
  if (requiresPlatformAdminHome(identity, to.path)) {
    return navigateTo('/admin/tenants', { replace: true })
  }

  // Configuração do escritório exige a permissão efetiva correspondente.
  if (to.path.startsWith('/conta/') && !canAccessTenantSettings(identity)) {
    return navigateTo('/conta')
  }
})

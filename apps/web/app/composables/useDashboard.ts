import { createSharedComposable } from '@vueuse/core'
import {
  canAssociateCategories as userCanAssociateCategories,
  canCreateExport as userCanCreateExport,
  canExecuteHighRiskMutation as userCanExecuteHighRiskMutation,
  canImportDocuments as userCanImportDocuments,
  canManageClients as userCanManageClients,
  canManageClientCategoryCatalog as userCanManageClientCategoryCatalog,
  canAssignClientCategories as userCanAssignClientCategories,
  canManageCredentials as userCanManageCredentials,
  canTriageMailbox as userCanTriageMailbox,
  canTriggerSync as userCanTriggerSync,
  canAccessTenantSettings as userCanAccessTenantSettings,
  canAccessPlatformAdmin as userCanAccessPlatformAdmin,
  isPlatformPrivilegedContext as userIsPlatformPrivilegedContext,
  unwrapMeUser
} from '~/utils/permissions'
import type { MeIdentity } from '~/utils/permissions'
import { canUseAssistant } from '~/utils/assistant'
import { clearSurfaceNavigationState } from '~/composables/useSurfaceNavigationState'
import { EXPORT_CREATE_PATH } from '~/utils/export-routes'
import { createDashboardContextualCommandRegistry } from '~/utils/dashboard-contextual-command-registry'

const _useDashboard = () => {
  const route = useRoute()
  const router = useRouter()
  const { user, isAuthenticated, refreshIdentity } = useSanctumAuth()
  const isNotificationsSlideoverOpen = ref(false)
  const isAssistantSlideoverOpen = ref(false)
  const isClientFormOpen = ref(false)
  const isExportFormOpen = ref(false)
  /** Incrementado a cada "Novo cliente" global — força modo create na lista. */
  const clientFormCreateNonce = ref(0)
  const sessionEpoch = ref(0)
  const contextualCommands = createDashboardContextualCommandRegistry()

  const me = computed(() => unwrapMeUser(user.value as MeIdentity))
  const assistantAvailable = computed(() => canUseAssistant(me.value))

  if (import.meta.client) {
    onMounted(() => {
      if (isAuthenticated.value) {
        void refreshIdentity().catch(() => {})
      }
    })
  }

  const canManageClients = computed(() => userCanManageClients(me.value))
  const canManageClientCategoryCatalog = computed(() => userCanManageClientCategoryCatalog(me.value))
  const canAssignClientCategories = computed(() => userCanAssignClientCategories(me.value))
  const canManageCredentials = computed(() => userCanManageCredentials(me.value))
  const canTriggerSync = computed(() => userCanTriggerSync(me.value))
  const canCreateExport = computed(() => userCanCreateExport(me.value))
  const canImportDocuments = computed(() => userCanImportDocuments(me.value))
  /** Configuração do escritório (perfil/certificado) — não é mais sinônimo de /admin. */
  const canAccessAdministration = computed(() => userCanAccessTenantSettings(me.value))
  const canAccessPlatformAdmin = computed(() => userCanAccessPlatformAdmin(me.value))
  const canAccessPlatformSerpro = computed(() => userCanAccessPlatformAdmin(me.value))
  const isPlatformPrivileged = computed(() => userIsPlatformPrivilegedContext(me.value))
  const canAssociateCategories = computed(() => userCanAssociateCategories(me.value))
  const canTriageMailbox = computed(() => userCanTriageMailbox(me.value))
  const canExecuteHighRiskMutation = computed(() => userCanExecuteHighRiskMutation(me.value))

  async function openClientCreate() {
    if (!canManageClients.value) return
    if (route.path !== '/clients') {
      await router.push('/clients')
    }
    // Sempre modo create: zera cliente residual na lista via watch do nonce.
    clientFormCreateNonce.value += 1
    isClientFormOpen.value = true
  }

  async function openExportCreate() {
    if (!canCreateExport.value) return
    if (route.path !== EXPORT_CREATE_PATH) {
      await router.push(EXPORT_CREATE_PATH)
    }
    isExportFormOpen.value = true
  }

  function bumpSessionEpoch() {
    sessionEpoch.value += 1
  }

  function toggleAssistantSlideover() {
    if (!assistantAvailable.value) {
      isAssistantSlideoverOpen.value = false
      return
    }
    isAssistantSlideoverOpen.value = !isAssistantSlideoverOpen.value
  }

  function openAssistantSlideover() {
    if (!assistantAvailable.value) return
    isAssistantSlideoverOpen.value = true
  }

  function closeAssistantSlideover() {
    isAssistantSlideoverOpen.value = false
  }

  defineShortcuts({
    'g-h': () => router.push('/'),
    'g-c': () => router.push('/clients'),
    'g-n': () => router.push('/docs'),
    'g-d': () => router.push('/docs'),
    'g-e': () => router.push('/exports'),
    'g-f': () => router.push('/closing'),
    'g-s': () => router.push('/syncs'),
    'g-o': () => router.push('/health'),
    'g-m': () => router.push('/monitoring'),
    'g-i': () => router.push('/communication'),
    'g-w': () => router.push('/work'),
    'g-k': () => router.push('/work/calendar'),
    'g-u': () => router.push('/conta/consumo'),
    'g-a': () => {
      if (canAccessPlatformAdmin.value) {
        void router.push('/admin/tenants')
      } else if (canAccessAdministration.value) {
        void router.push('/conta/escritorio')
      }
    },
    'n': () => {
      isNotificationsSlideoverOpen.value = !isNotificationsSlideoverOpen.value
    },
    'shift_a': () => {
      if (!assistantAvailable.value) return
      openAssistantSlideover()
    }
  })

  watch(() => route.fullPath, () => {
    isNotificationsSlideoverOpen.value = false
    isAssistantSlideoverOpen.value = false
    if (route.path !== '/clients') {
      isClientFormOpen.value = false
    }
    if (route.path !== '/exports' && !route.path.startsWith('/exports/')) {
      isExportFormOpen.value = false
    }
  })

  // Limpa alertas e estado de UI sensível quando a identidade muda ou a sessão termina.
  watch(
    () => [me.value?.id ?? null, isAuthenticated.value] as const,
    ([nextId, authenticated], [prevId, wasAuthenticated]) => {
      const identityChanged = wasAuthenticated && authenticated && nextId !== prevId
      // Sem o middleware de query removido, não há mais intenção de navegação
      // criada como guest a preservar: qualquer troca fecha painéis.
      const authenticationChanged = authenticated !== wasAuthenticated

      if (identityChanged || authenticationChanged) {
        isNotificationsSlideoverOpen.value = false
        isAssistantSlideoverOpen.value = false
        isClientFormOpen.value = false
        isExportFormOpen.value = false
        sessionEpoch.value += 1
        clearSurfaceNavigationState()
      }
    }
  )

  watch(sessionEpoch, () => {
    contextualCommands.clear()
  })

  return {
    assistantAvailable,
    bumpSessionEpoch,
    canAccessAdministration,
    canAccessPlatformAdmin,
    canAccessPlatformSerpro,
    canAssociateCategories,
    canCreateExport,
    canExecuteHighRiskMutation,
    canImportDocuments,
    canManageClients,
    canManageClientCategoryCatalog,
    canAssignClientCategories,
    canManageCredentials,
    canTriageMailbox,
    canTriggerSync,
    clientFormCreateNonce,
    closeAssistantSlideover,
    isAssistantSlideoverOpen,
    isClientFormOpen,
    isExportFormOpen,
    isNotificationsSlideoverOpen,
    isPlatformPrivileged,
    me,
    contextualCommandGroups: contextualCommands.groups,
    openAssistantSlideover,
    openClientCreate,
    openExportCreate,
    registerContextualCommandGroups: contextualCommands.register,
    sessionEpoch,
    toggleAssistantSlideover
  }
}

export const useDashboard = createSharedComposable(_useDashboard)

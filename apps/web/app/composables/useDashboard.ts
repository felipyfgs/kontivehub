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
  canAccessOfficeSettings as userCanAccessOfficeSettings,
  canAccessPlatformAdmin as userCanAccessPlatformAdmin,
  canAccessPlatformSerproConsole as userCanAccessPlatformSerproConsole,
  isPlatformPrivilegedContext as userIsPlatformPrivilegedContext,
  unwrapMeUser
} from '~/utils/permissions'
import type { MeIdentity } from '~/utils/permissions'
import { canUseAssistant } from '~/utils/assistant'

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
  const canManageCredentials = computed(() =>
    userCanManageCredentials(me.value) || userIsPlatformPrivilegedContext(me.value)
  )
  const canTriggerSync = computed(() => userCanTriggerSync(me.value))
  const canCreateExport = computed(() => userCanCreateExport(me.value))
  const canImportDocuments = computed(() => userCanImportDocuments(me.value))
  /** Configuração do escritório (perfil/A1) — não é mais sinônimo de /admin. */
  const canAccessAdministration = computed(() => userCanAccessOfficeSettings(me.value))
  const canAccessPlatformAdmin = computed(() => userCanAccessPlatformAdmin(me.value))
  const canAccessPlatformSerpro = computed(() => userCanAccessPlatformSerproConsole(me.value))
  const isPlatformPrivileged = computed(() => userIsPlatformPrivilegedContext(me.value))
  const canAssociateCategories = computed(() => userCanAssociateCategories(me.value))
  const canTriageMailbox = computed(() => userCanTriageMailbox(me.value))
  const canExecuteHighRiskMutation = computed(() =>
    userCanExecuteHighRiskMutation(me.value) || userIsPlatformPrivilegedContext(me.value)
  )

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
    if (route.path !== '/exports') {
      await router.push('/exports')
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
        void router.push('/admin')
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
    if (route.path !== '/exports') {
      isExportFormOpen.value = false
    }
  })

  // Limpa alertas e estado de UI sensível quando a identidade muda ou a sessão termina.
  watch(
    () => [me.value?.id ?? null, isAuthenticated.value] as const,
    ([nextId, authenticated], [prevId, wasAuthenticated]) => {
      const identityChanged = prevId !== undefined && nextId !== prevId
      const loggedOut = wasAuthenticated && !authenticated

      if (identityChanged || loggedOut) {
        isNotificationsSlideoverOpen.value = false
        isAssistantSlideoverOpen.value = false
        isClientFormOpen.value = false
        isExportFormOpen.value = false
        sessionEpoch.value += 1
      }
    }
  )

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
    openAssistantSlideover,
    openClientCreate,
    openExportCreate,
    sessionEpoch,
    toggleAssistantSlideover
  }
}

export const useDashboard = createSharedComposable(_useDashboard)

/**
 * Troca explícita de escritório entre memberships autorizadas.
 * Invalida stores/queries tenant-scoped via sessionEpoch + refreshIdentity.
 * Páginas devem: (1) watch sessionEpoch; (2) zerar seleção/paginação/detalhe;
 * (3) descartar respostas em voo comparando epoch no resolve.
 */
import type { TenantMembership } from '~/types/api'

/** Helper para reset local de UI tenant-scoped ao trocar escritório. */
export function resetTenantScopedUi(handlers: {
  clearSelection?: () => void
  clearPagination?: () => void
  clearDetail?: () => void
  clearCaches?: () => void
}) {
  handlers.clearSelection?.()
  handlers.clearPagination?.()
  handlers.clearDetail?.()
  handlers.clearCaches?.()
}

export function useTenantSwitch() {
  const api = useApi()
  const toast = useToast()
  const { refreshIdentity } = useSanctumAuth()
  const { sessionEpoch, bumpSessionEpoch } = useDashboard()

  const memberships = ref<TenantMembership[]>([])
  const currentTenantId = ref<number | null>(null)
  const loading = ref(false)
  const switching = ref(false)
  const loadError = ref<string | null>(null)

  async function loadMemberships() {
    loading.value = true
    try {
      const res = await api.tenants.memberships()
      memberships.value = res.data.memberships || []
      currentTenantId.value = res.data.current_tenant_id
      loadError.value = null
    } catch (caught) {
      loadError.value = apiErrorMessage(caught, 'Não foi possível carregar os escritórios.')
      memberships.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * Confirma troca: POST /tenants/switch → refresh me → bump epoch → reload rota.
   */
  async function switchTo(tenantId: number): Promise<boolean> {
    if (switching.value) return false
    if (tenantId === currentTenantId.value) return true

    const target = memberships.value.find(m => m.tenant_id === tenantId)
    if (!target) {
      toast.add({
        title: 'Escritório não autorizado',
        description: 'Só é possível trocar entre memberships ativas.',
        color: 'error'
      })
      return false
    }

    switching.value = true
    try {
      await api.tenants.switch(tenantId)
      await refreshIdentity()
      bumpSessionEpoch()
      currentTenantId.value = tenantId
      memberships.value = memberships.value.map(m => ({
        ...m,
        is_current: m.tenant_id === tenantId
      }))
      toast.add({
        title: 'Escritório alterado',
        description: target.tenant_name || `Escritório #${tenantId}`,
        color: 'success'
      })
      // Recarrega a rota atual sem misturar dados do tenant anterior.
      const path = useRoute().fullPath
      if (import.meta.client) {
        await navigateTo(path, { replace: true, external: false })
        // Força remount de páginas que leem dados tenant-scoped.
        window.location.assign(path)
      }
      return true
    } catch (caught) {
      toast.add({
        title: apiErrorMessage(caught, 'Falha ao trocar de escritório.'),
        color: 'error'
      })
      return false
    } finally {
      switching.value = false
    }
  }

  return {
    memberships,
    currentTenantId,
    loading,
    switching,
    loadError,
    loadMemberships,
    switchTo,
    sessionEpoch
  }
}

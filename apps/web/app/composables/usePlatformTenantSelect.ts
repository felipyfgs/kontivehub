/**
 * Seletor global de escritórios para PLATFORM_ADMIN.
 * Consome envelope canônico { tenants, selected_tenant_id, default_tenant_id }.
 * Sem fallback para /platform/tenants.
 */
import type { PlatformTenantSummary } from '~/types/api'
import { isPlatformAdmin } from '~/utils/permissions'

export function usePlatformTenantSelect() {
  const route = useRoute()
  const api = useApi()
  const toast = useToast()
  const { refreshIdentity } = useSanctumAuth()
  const { me, bumpSessionEpoch, sessionEpoch } = useDashboard()

  const tenants = ref<PlatformTenantSummary[]>([])
  const selectedTenantId = ref<number | null>(null)
  const defaultTenantId = ref<number | null>(null)
  const loading = ref(false)
  const switching = ref(false)
  const loadError = ref<string | null>(null)
  const q = ref('')

  const enabled = computed(() => isPlatformAdmin(me.value))
  const currentTenantId = computed(() => me.value?.current_tenant?.id ?? null)
  const privileged = computed(() => me.value?.access_mode === 'platform_privileged')

  /** Tenants selecionáveis no seletor (selectable=true ou is_active). */
  const selectableTenants = computed(() =>
    tenants.value.filter(o => o.selectable !== false && o.is_active !== false)
  )

  async function loadTenants(params?: { q?: string }) {
    if (!enabled.value) {
      tenants.value = []
      selectedTenantId.value = null
      defaultTenantId.value = null
      return
    }
    loading.value = true
    loadError.value = null
    try {
      const res = await api.platform.tenants.list({
        per_page: 100,
        q: params?.q ?? (q.value || undefined)
      })
      const envelope = res.data
      // Contrato canônico: data.tenants — nunca tratar data como array.
      tenants.value = Array.isArray(envelope?.tenants) ? envelope.tenants : []
      selectedTenantId.value = envelope?.selected_tenant_id ?? null
      defaultTenantId.value = envelope?.default_tenant_id ?? null
    } catch (caught) {
      tenants.value = []
      loadError.value = apiErrorMessage(caught, 'Não foi possível listar escritórios da plataforma.')
    } finally {
      loading.value = false
    }
  }

  function redirectAfterSelection(redirectTo?: string) {
    if (import.meta.client) {
      window.location.assign(redirectTo || route.fullPath)
    }
  }

  async function selectTenant(tenantId: number, redirectTo?: string): Promise<boolean> {
    if (!enabled.value || switching.value) return false
    if (tenantId === currentTenantId.value && privileged.value) {
      if (redirectTo) redirectAfterSelection(redirectTo)
      return true
    }

    switching.value = true
    try {
      await api.platform.tenants.select(tenantId)
      await refreshIdentity()
      bumpSessionEpoch()
      const label = tenants.value.find(o => o.id === tenantId)?.name
      toast.add({
        title: 'Escritório selecionado',
        description: label || `Escritório #${tenantId}`,
        color: 'success'
      })
      redirectAfterSelection(redirectTo)
      return true
    } catch (caught) {
      const code = (caught as { data?: { code?: string } })?.data?.code
      const msg = code === 'privileged_context_disabled'
        ? 'Contexto privilegiado desligado (PLATFORM_PRIVILEGED_CONTEXT). Habilite no .env da raiz e reinicie a API.'
        : apiErrorMessage(caught, 'Falha ao selecionar escritório.')
      toast.add({
        title: msg,
        color: 'error'
      })
      return false
    } finally {
      switching.value = false
    }
  }

  async function clearSelection(): Promise<boolean> {
    if (!enabled.value || switching.value) return false
    switching.value = true
    try {
      await api.platform.tenants.clear()
      await refreshIdentity()
      bumpSessionEpoch()
      toast.add({
        title: 'Seleção de sessão encerrada',
        color: 'neutral'
      })
      if (import.meta.client) {
        window.location.assign('/admin/tenants')
      }
      return true
    } catch (caught) {
      toast.add({
        title: apiErrorMessage(caught, 'Falha ao limpar seleção.'),
        color: 'error'
      })
      return false
    } finally {
      switching.value = false
    }
  }

  return {
    tenants,
    selectableTenants,
    selectedTenantId,
    defaultTenantId,
    loading,
    switching,
    loadError,
    q,
    enabled,
    currentTenantId,
    privileged,
    loadTenants,
    selectTenant,
    clearSelection,
    sessionEpoch
  }
}

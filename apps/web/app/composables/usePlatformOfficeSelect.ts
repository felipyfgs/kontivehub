/**
 * Seletor global de escritórios para PLATFORM_ADMIN.
 * Consome envelope canônico { offices, selected_office_id, default_office_id }.
 * Sem fallback para /platform/tenants.
 */
import type { PlatformOfficeSummary } from '~/types/api'
import { isPlatformAdmin } from '~/utils/permissions'

export function usePlatformOfficeSelect() {
  const route = useRoute()
  const api = useApi()
  const toast = useToast()
  const { refreshIdentity } = useSanctumAuth()
  const { me, bumpSessionEpoch, sessionEpoch } = useDashboard()

  const offices = ref<PlatformOfficeSummary[]>([])
  const selectedOfficeId = ref<number | null>(null)
  const defaultOfficeId = ref<number | null>(null)
  const loading = ref(false)
  const switching = ref(false)
  const loadError = ref<string | null>(null)
  const q = ref('')

  const enabled = computed(() => isPlatformAdmin(me.value))
  const currentOfficeId = computed(() => me.value?.current_office?.id ?? me.value?.office?.id ?? null)
  const privileged = computed(() => me.value?.access_mode === 'platform_privileged')

  /** Offices selecionáveis no seletor (selectable=true ou is_active). */
  const selectableOffices = computed(() =>
    offices.value.filter(o => o.selectable !== false && o.is_active !== false)
  )

  async function loadOffices(params?: { q?: string }) {
    if (!enabled.value) {
      offices.value = []
      selectedOfficeId.value = null
      defaultOfficeId.value = null
      return
    }
    loading.value = true
    loadError.value = null
    try {
      const res = await api.platform.offices.list({
        per_page: 100,
        q: params?.q ?? (q.value || undefined)
      })
      const envelope = res.data
      // Contrato canônico: data.offices — nunca tratar data como array.
      offices.value = Array.isArray(envelope?.offices) ? envelope.offices : []
      selectedOfficeId.value = envelope?.selected_office_id ?? null
      defaultOfficeId.value = envelope?.default_office_id ?? null
    } catch (caught) {
      offices.value = []
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

  async function selectOffice(officeId: number, redirectTo?: string): Promise<boolean> {
    if (!enabled.value || switching.value) return false
    if (officeId === currentOfficeId.value && privileged.value) {
      if (redirectTo) redirectAfterSelection(redirectTo)
      return true
    }

    switching.value = true
    try {
      await api.platform.offices.select(officeId)
      await refreshIdentity()
      bumpSessionEpoch()
      const label = offices.value.find(o => o.id === officeId)?.name
      toast.add({
        title: 'Escritório selecionado',
        description: label || `Escritório #${officeId}`,
        color: 'success'
      })
      redirectAfterSelection(redirectTo)
      return true
    } catch (caught) {
      const code = (caught as { data?: { code?: string } })?.data?.code
      const msg = code === 'privileged_context_disabled'
        ? 'Contexto privilegiado desligado (PLATFORM_PRIVILEGED_CONTEXT). Habilite no apps/api/.env e reinicie o PHP.'
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
      await api.platform.offices.clear()
      await refreshIdentity()
      bumpSessionEpoch()
      toast.add({
        title: 'Seleção de sessão encerrada',
        color: 'neutral'
      })
      if (import.meta.client) {
        window.location.assign('/admin')
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
    offices,
    selectableOffices,
    selectedOfficeId,
    defaultOfficeId,
    loading,
    switching,
    loadError,
    q,
    enabled,
    currentOfficeId,
    privileged,
    loadOffices,
    selectOffice,
    clearSelection,
    sessionEpoch
  }
}

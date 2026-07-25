/**
 * Inventário e execução de consultas manuais (somente leitura).
 * GET nunca dispara SERPRO; POST exige confirmed:true no backend.
 */
import type {
  ManualConsultAction,
  ManualConsultExecuteResult,
  ManualConsultInventory
} from '~/types/fiscal-modules'
import { apiErrorMessage } from '~/utils/api-error'

export function useManualConsultExplorer() {
  const api = useApi()
  const toast = useToast()

  const loading = ref(false)
  const executing = ref(false)
  const loadError = ref<string | null>(null)
  const inventory = ref<ManualConsultInventory | null>(null)

  const actions = computed<ManualConsultAction[]>(() => inventory.value?.actions ?? [])
  const readyCount = computed(() => inventory.value?.meta.ready ?? 0)
  const totalCount = computed(() => inventory.value?.meta.total ?? 0)

  async function loadInventory(params?: {
    client_id?: number
    surface_key?: string
    module_key?: string
  }): Promise<ManualConsultInventory | null> {
    loading.value = true
    loadError.value = null
    try {
      const res = await api.fiscal.manualConsults.inventory(params)
      inventory.value = res.data
      // Contrato: inventário é local — serpro_called deve ser false.
      if (res.data.meta.serpro_called !== false) {
        loadError.value = 'Resposta de inventário inválida (coleta implícita).'
      }
      return res.data
    } catch (caught) {
      inventory.value = null
      loadError.value = apiErrorMessage(caught, 'Falha ao carregar inventário de consultas.')
      return null
    } finally {
      loading.value = false
    }
  }

  async function execute(input: {
    action_id: string
    client_id: number
    params?: Record<string, unknown>
    silent?: boolean
  }): Promise<ManualConsultExecuteResult | null> {
    if (!input.client_id || input.client_id < 1) {
      if (!input.silent) {
        toast.add({ title: 'Informe o cliente para consultar.', color: 'warning' })
      }
      return null
    }
    if (!input.action_id) {
      if (!input.silent) {
        toast.add({ title: 'Selecione uma ação de consulta.', color: 'warning' })
      }
      return null
    }

    executing.value = true
    try {
      const res = await api.fiscal.manualConsults.execute({
        action_id: input.action_id,
        client_id: input.client_id,
        confirmed: true,
        params: input.params
      })
      if (!input.silent) {
        toast.add({
          title: res.data.async ? 'Consulta assíncrona enfileirada' : 'Consulta enfileirada',
          description: res.data.module_route
            ? `Resultado em ${res.data.module_route}`
            : undefined,
          color: 'success'
        })
      }
      return res.data
    } catch (caught) {
      if (!input.silent) {
        toast.add({
          title: apiErrorMessage(caught, 'Falha ao executar consulta manual.'),
          color: 'error'
        })
      }
      return null
    } finally {
      executing.value = false
    }
  }

  return {
    loading,
    executing,
    loadError,
    inventory,
    actions,
    readyCount,
    totalCount,
    loadInventory,
    execute
  }
}

/**
 * Abre ClientsClientFormModal a partir de um client_id da carteira monitoring.
 */
import type { Client } from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'

export function useMonitoringClientEdit(onSaved?: () => void | Promise<void>) {
  const api = useApi()
  const toast = useToast()
  const { canManageClients, canManageCredentials } = useDashboard()

  const formOpen = ref(false)
  const formClient = ref<Client | null>(null)
  const formLoading = ref(false)

  async function openEditClient(clientId: number) {
    if (!canManageClients.value) {
      toast.add({
        title: 'Sem permissão para editar clientes',
        color: 'warning'
      })
      return
    }
    formLoading.value = true
    try {
      const response = await api.clients.get(clientId)
      formClient.value = response.data
      formOpen.value = true
    } catch (caught) {
      toast.add({
        title: apiErrorMessage(caught, 'Não foi possível carregar o cliente.'),
        color: 'error'
      })
    } finally {
      formLoading.value = false
    }
  }

  async function onFormSaved() {
    formOpen.value = false
    formClient.value = null
    await onSaved?.()
  }

  watch(formOpen, (open) => {
    if (!open) formClient.value = null
  })

  return {
    canManageClients,
    canManageCredentials,
    formOpen,
    formClient,
    formLoading,
    openEditClient,
    onFormSaved
  }
}

import type {
  MailboxMonitoringMode,
  MailboxMonitoringStatus,
  MailboxSyncPreview
} from '~/types/mailbox-monitoring'

export function useMailboxMonitoring() {
  const api = useApi()
  const status = ref<MailboxMonitoringStatus | null>(null)
  const preview = ref<MailboxSyncPreview | null>(null)
  const loading = ref(false)
  const previewing = ref(false)
  const confirming = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      status.value = (await api.fiscal.mailbox.monitoring.get()).data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Falha ao carregar o monitoramento da Caixa Postal.')
    } finally {
      loading.value = false
    }
  }

  async function save(input: { enabled: boolean, mode: MailboxMonitoringMode }) {
    saving.value = true
    error.value = null
    try {
      status.value = (await api.fiscal.mailbox.monitoring.update(input)).data
      return true
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Falha ao salvar o monitoramento.')
      return false
    } finally {
      saving.value = false
    }
  }

  async function previewNow() {
    previewing.value = true
    error.value = null
    try {
      preview.value = (await api.fiscal.mailbox.monitoring.preview({ force_all: true })).data
      return preview.value
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível calcular a atualização.')
      return null
    } finally {
      previewing.value = false
    }
  }

  async function confirmNow() {
    confirming.value = true
    error.value = null
    try {
      const idempotencyKey = typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `mailbox-${Date.now()}-${Math.random().toString(36).slice(2)}`
      const result = await api.fiscal.mailbox.monitoring.sync({
        force_all: true,
        idempotency_key: idempotencyKey
      })
      await load()
      return result.data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível iniciar a atualização.')
      return null
    } finally {
      confirming.value = false
    }
  }

  return {
    status,
    preview,
    loading,
    previewing,
    confirming,
    saving,
    error,
    load,
    save,
    previewNow,
    confirmNow
  }
}

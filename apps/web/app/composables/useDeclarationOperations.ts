import type {
  DeclarationOperation,
  DeclarationOperationMutation,
  DeclarationOperationPreflight,
  DeclarationOperationReadResult
} from '~/types/fiscal-modules'
import { apiErrorData, apiErrorMessage } from '~/utils/api-error'

const POLLABLE_MUTATION_STATUSES = new Set(['PENDING', 'SENT', 'RECONCILING'])

function idempotencyKey() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `decl-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export function useDeclarationOperations() {
  const api = useApi()
  const busy = ref(false)
  const polling = ref(false)
  const error = ref<string | null>(null)
  const preflight = ref<DeclarationOperationPreflight | null>(null)
  const mutation = ref<DeclarationOperationMutation | null>(null)
  const readResult = ref<DeclarationOperationReadResult | null>(null)
  const currentIdempotencyKey = ref(idempotencyKey())
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollAttempts = 0

  function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer)
    pollTimer = null
    polling.value = false
  }

  function reset() {
    stopPolling()
    error.value = null
    preflight.value = null
    mutation.value = null
    readResult.value = null
    currentIdempotencyKey.value = idempotencyKey()
    pollAttempts = 0
  }

  async function runRead(
    operation: DeclarationOperation,
    clientId: number,
    params: Record<string, unknown>
  ): Promise<DeclarationOperationReadResult | null> {
    busy.value = true
    error.value = null
    try {
      const response = await api.fiscal.declarations.operations.read(operation.action_id, {
        client_id: clientId,
        confirmed: true,
        params
      })
      readResult.value = response.data
      return response.data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível enfileirar a consulta declarativa.')
      return null
    } finally {
      busy.value = false
    }
  }

  async function requestPreflight(
    operation: DeclarationOperation,
    clientId: number,
    params: Record<string, unknown>
  ): Promise<DeclarationOperationPreflight | null> {
    busy.value = true
    error.value = null
    preflight.value = null
    mutation.value = null
    try {
      const response = await api.fiscal.declarations.operations.preflight(operation.action_id, {
        client_id: clientId,
        idempotency_key: currentIdempotencyKey.value,
        params
      })
      preflight.value = response.data
      return response.data
    } catch (caught) {
      const denied = apiErrorData<DeclarationOperationPreflight>(caught)
      if (denied?.action_id) {
        preflight.value = denied
        error.value = denied.denial_message
          || denied.eligibility?.messages?.[0]
          || 'O preflight da operação foi bloqueado.'
        return denied
      }
      error.value = apiErrorMessage(caught, 'O preflight da operação foi bloqueado.')
      return null
    } finally {
      busy.value = false
    }
  }

  async function executeMutation(input: {
    operation: DeclarationOperation
    clientId: number
    params: Record<string, unknown>
    password: string
    confirmationPhrase: string
  }): Promise<DeclarationOperationMutation | null> {
    const prepared = preflight.value
    if (!prepared?.eligible || !prepared.preflight_token || !prepared.confirmation_phrase) {
      error.value = 'Faça um preflight elegível antes de executar.'
      return null
    }

    busy.value = true
    error.value = null
    try {
      await api.confirmPassword(input.password)
      const response = await api.fiscal.declarations.operations.execute(input.operation.action_id, {
        client_id: input.clientId,
        idempotency_key: currentIdempotencyKey.value,
        preflight_token: prepared.preflight_token,
        confirmation_phrase: input.confirmationPhrase,
        confirmed: true,
        params: input.params
      })
      mutation.value = response.data
      schedulePolling()
      return response.data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'A operação declarativa não foi executada.')
      return null
    } finally {
      busy.value = false
    }
  }

  async function refreshMutation(): Promise<DeclarationOperationMutation | null> {
    if (!mutation.value?.id) return null
    try {
      const response = await api.fiscal.declarations.operations.getMutation(mutation.value.id)
      mutation.value = response.data
      return response.data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível atualizar o estado da operação.')
      stopPolling()
      return null
    }
  }

  function schedulePolling() {
    stopPolling()
    const status = String(mutation.value?.status || '').toUpperCase()
    if (!mutation.value?.id || !POLLABLE_MUTATION_STATUSES.has(status) || pollAttempts >= 40) return
    polling.value = true
    pollTimer = setTimeout(async () => {
      pollAttempts++
      await refreshMutation()
      schedulePolling()
    }, 2500)
  }

  async function reconcile(password: string): Promise<DeclarationOperationMutation | null> {
    if (!mutation.value?.id) return null
    busy.value = true
    error.value = null
    try {
      await api.confirmPassword(password)
      const response = await api.fiscal.declarations.operations.reconcile(mutation.value.id)
      mutation.value = response.data
      schedulePolling()
      return response.data
    } catch (caught) {
      error.value = apiErrorMessage(caught, 'Não foi possível reconciliar o resultado.')
      return null
    } finally {
      busy.value = false
    }
  }

  onScopeDispose(stopPolling)

  return {
    busy,
    polling,
    error,
    preflight,
    mutation,
    readResult,
    reset,
    runRead,
    requestPreflight,
    executeMutation,
    refreshMutation,
    reconcile,
    stopPolling
  }
}

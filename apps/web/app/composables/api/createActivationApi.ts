import type {
  ActivationCompleteResult,
  ActivationInspectResult
} from '~/types/api'
import type { ApiClient } from './types'

/**
 * Endpoints públicos de ativação / primeiro acesso (sem sessão prévia).
 * Segredos só no body; respostas com no-store no backend.
 */
export function createActivationApi(client: ApiClient) {
  return {
    activations: {
      inspect: (token: string) =>
        client<{ data: ActivationInspectResult }>('/api/v1/activations/inspect', {
          method: 'POST',
          body: { token }
        }),
      complete: (body: {
        token: string
        password: string
        password_confirmation: string
      }) =>
        client<{ data: ActivationCompleteResult }>('/api/v1/activations/complete', {
          method: 'POST',
          body
        }),
      firstAccess: (body: {
        email: string
        temporary_password: string
        password: string
        password_confirmation: string
      }) =>
        client<{ data: ActivationCompleteResult }>('/api/v1/first-access/complete', {
          method: 'POST',
          body
        })
    }
  }
}

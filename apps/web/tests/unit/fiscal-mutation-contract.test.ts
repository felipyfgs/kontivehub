import { describe, expect, it, vi } from 'vitest'
import { createFiscalApi } from '../../app/composables/api/createFiscalApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'
import type { FiscalMutationExecutionRequest } from '../../app/types/api'

describe('fiscal mutation contract', () => {
  it('reenvia token de preflight e chave de idempotência obrigatórios no execute', async () => {
    const clientMock = vi.fn().mockResolvedValue({ data: { id: 1 } })
    const api = createFiscalApi(
      clientMock as unknown as ApiClient,
      vi.fn((path: string) => path) as ApiUrl
    )
    const body: FiscalMutationExecutionRequest = {
      client_id: 7,
      operation_key: 'fiscal.mutation',
      solution_code: 'SOLUTION',
      service_code: 'SERVICE',
      operation_code: 'EXECUTE',
      payload: {},
      idempotency_key: 'idem-123',
      preflight_token: 'preflight-token',
      confirmation_phrase: 'CONFIRMAR',
      confirmed: true
    }

    await api.fiscal.mutations.execute(body)

    expect(clientMock).toHaveBeenCalledWith('/api/v1/fiscal/mutations', {
      method: 'POST',
      body
    })
  })
})

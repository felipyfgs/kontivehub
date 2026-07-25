import { describe, expect, it, vi } from 'vitest'
import { createAuthApi } from '../../app/composables/api/createAuthApi'
import { createClientsApi } from '../../app/composables/api/createClientsApi'
import { createFiscalApi } from '../../app/composables/api/createFiscalApi'
import { createWorkApi } from '../../app/composables/api/createWorkApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'

function harness() {
  const clientMock = vi.fn(async () => ({ data: [] }))
  const client = clientMock as unknown as ApiClient
  const apiUrl = vi.fn((path: string) => path) as ApiUrl
  return { client, clientMock, apiUrl }
}

describe('critical use-case journeys', () => {
  it('identity and tenant switching use the canonical membership contract', async () => {
    const { client, clientMock } = harness()
    const api = createAuthApi(client)

    await api.tenants.memberships()
    await api.tenants.switch(42)

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/tenants/memberships')
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/tenants/switch', {
      method: 'POST',
      body: { office_id: 42 }
    })
  })

  it('client lifecycle keeps tenant ownership server-side and declares mutations', async () => {
    const { client, clientMock } = harness()
    const api = createClientsApi(client)

    await api.clients.list({ q: 'Cliente E2E', page: 1 })
    await api.clients.bulkStatus({ client_ids: [7], is_active: false })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/clients', {
      query: { q: 'Cliente E2E', page: 1 }
    })
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/clients/bulk-status', {
      method: 'PATCH',
      body: { client_ids: [7], is_active: false }
    })
    expect(JSON.stringify(clientMock.mock.calls)).not.toContain('office_id')
  })

  it('operational work maps templates, tasks and processes to distinct methods', async () => {
    const { client, clientMock, apiUrl } = harness()
    const api = createWorkApi(client, apiUrl)

    await api.work.templates.catalog()
    await api.work.queue({ scope: 'default' })
    await api.work.processes.create({ client_id: 7, title: 'Fechamento E2E' })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/work/template-catalog')
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/work/queue', {
      query: { scope: 'default' }
    })
    expect(clientMock).toHaveBeenNthCalledWith(3, '/api/v1/work/processes', {
      method: 'POST',
      body: { client_id: 7, title: 'Fechamento E2E' }
    })
  })

  it('fiscal monitoring reads portfolio without accepting a client office id', async () => {
    const { client, clientMock, apiUrl } = harness()
    const api = createFiscalApi(client, apiUrl)

    await api.fiscal.monitoringInsights()
    await api.fiscal.modules.clients('simples_mei', { submodule: 'PGDASD', page: 1 })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/fiscal/monitoring/insights', {
      signal: undefined
    })
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/fiscal/modules/simples_mei/clients', {
      query: { submodule: 'PGDASD', page: 1 },
      signal: undefined
    })
    expect(JSON.stringify(clientMock.mock.calls)).not.toContain('office_id')
  })
})

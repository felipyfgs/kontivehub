import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { createFiscalApi } from '../../app/composables/api/createFiscalApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'

describe('FGTS eSocial BX oficial', () => {
  it('expõe coverage, readiness tenant e limites oficiais no cliente fiscal', () => {
    const api = readFileSync(resolve(process.cwd(), 'app/composables/api/createFiscalApi.ts'), 'utf8')
    const types = readFileSync(resolve(process.cwd(), 'app/types/api.ts'), 'utf8')

    expect(api).toContain('/api/v1/fiscal/fgts/readiness')
    expect(api).toContain('query: { client_id: clientId }')
    expect(types).toContain('export interface FgtsEsocialReadiness')
    expect(types).toContain('locally_remaining: number')
    expect(types).toContain('source_available: boolean')
    expect(types).toContain('transport?: \'SOAP_1_1_MTLS\' | string')
    expect(types).toContain('automatic_events?:')
    expect(types).toContain('context_required_events?:')
    expect(types).toContain('minimum_lag_minutes: number')
    expect(types).toContain('max_query_interval_days: number')
    const readinessType = types.slice(
      types.indexOf('export interface FgtsEsocialReadiness'),
      types.indexOf('export interface FiscalMutationPreflight')
    )
    expect(readinessType).not.toContain('pfx')
    expect(readinessType).not.toContain('password')
  })

  it('mostra fonte oficial, quota e bloqueio sem prometer guia pelo BX', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/fgts.vue'), 'utf8')

    expect(page).toContain('eSocial BX oficial')
    expect(page).toContain('S-1299 e S-5013 automáticos')
    expect(page).toContain('fgts-esocial-coverage')
    expect(page).toContain('fgts-esocial-partial-warning')
    expect(page).toContain('Não consulta guia, pagamento, PIX nem pendências do portal FGTS Digital')
    expect(page).toContain('fgts-esocial-readiness')
    expect(page).toContain('detailReadiness.blockers[0]?.message')
    expect(page).toContain('detailOf(row.original).guide_status || \'UNSUPPORTED\'')
    expect(page).not.toContain('resolver CAPTCHA')
  })

  it('envia readiness e sync tipados ao endpoint tenant-scoped sem office_id', async () => {
    const clientMock = vi.fn()
      .mockResolvedValueOnce({ data: { ready: false, blockers: [] } })
      .mockResolvedValueOnce({
        data: {
          queued: true,
          client_id: 7,
          competence_period_key: '2026-06'
        }
      })
    const api = createFiscalApi(
      clientMock as unknown as ApiClient,
      vi.fn((path: string) => path) as ApiUrl
    )

    await api.fiscal.fgts.readiness(7)
    await api.fiscal.fgts.sync({
      client_id: 7,
      competence_period_key: '2026-06',
      dispatch_job: true
    })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/fiscal/fgts/readiness', {
      query: { client_id: 7 }
    })
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/fiscal/fgts/sync', {
      method: 'POST',
      body: {
        client_id: 7,
        competence_period_key: '2026-06',
        dispatch_job: true
      }
    })
    expect(JSON.stringify(clientMock.mock.calls)).not.toContain('office_id')
  })

  it('nomeia a ação como sync eSocial e declara o custo e a cobertura antes da chamada', () => {
    const action = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/PendingSearchButton.vue'),
      'utf8'
    )

    expect(action).toContain('if (isFgts.value) return \'Sincronizar eSocial\'')
    expect(action).toContain('Confirmar sincronização eSocial')
    expect(action).toContain('guia e pagamento não serão consultados')
    expect(action).toContain('S-5003 exige contexto do trabalhador')
    expect(action).not.toContain('api.fiscal.fgts.sync')
    expect(action).toContain('enqueueReadUpdate')
  })
})

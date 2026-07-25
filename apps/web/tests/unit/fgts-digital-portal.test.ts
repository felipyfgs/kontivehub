import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { createFiscalApi } from '../../app/composables/api/createFiscalApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'

describe('FGTS Digital portal', () => {
  it('mantém contratos tenant-scoped e tipos públicos sem material do browser', async () => {
    const clientMock = vi.fn().mockResolvedValue({ data: {} })
    const api = createFiscalApi(
      clientMock as unknown as ApiClient,
      vi.fn((path: string) => path) as ApiUrl
    )

    await api.fiscal.fgts.digital.readiness(7)
    await api.fiscal.fgts.digital.sync({ client_id: 7 })
    await api.fiscal.fgts.digital.preview({
      client_id: 7,
      guide_type: 'PARAMETERIZED',
      parameters: { competence_period_key: '2026-07', debit_ids: ['private'] }
    })
    await api.fiscal.fgts.digital.emit(9, {
      preview_token: 'x'.repeat(48),
      confirmation_phrase: 'EMITIR FGTS 2026-07 PARAMETERIZED'
    })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/fiscal/fgts/digital/readiness', {
      query: { client_id: 7 }
    })
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/fiscal/fgts/digital/sync', {
      method: 'POST',
      body: { client_id: 7 }
    })
    expect(clientMock).toHaveBeenNthCalledWith(4, '/api/v1/fiscal/fgts/digital/previews/9/emit', {
      method: 'POST',
      body: {
        preview_token: 'x'.repeat(48),
        confirmation_phrase: 'EMITIR FGTS 2026-07 PARAMETERIZED'
      }
    })
    expect(JSON.stringify(clientMock.mock.calls)).not.toContain('office_id')

    const types = readFileSync(resolve(process.cwd(), 'app/types/api.ts'), 'utf8')
    const publicTypes = types.slice(
      types.indexOf('export interface FgtsDigitalCoverage'),
      types.indexOf('export interface FiscalMutationPreflight')
    )
    expect(publicTypes).not.toMatch(/pfx|passphrase|cookie|api_key|proxy_url|captcha_token/i)
    expect(publicTypes).toContain('supports_pix_payment: false')
  })

  it('mostra readiness, guias, pagamento, challenge e preview-autorização sem prometer Pix', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/monitoring/fgts.vue'), 'utf8')

    expect(page).toContain('fgts-digital-coverage')
    expect(page).toContain('fgts-digital-readiness')
    expect(page).toContain('fgts-digital-human-challenge')
    expect(page).toContain('fgts-digital-preview-modal')
    expect(page).toContain('fgts-digital-confirmation')
    expect(page).toContain('IDs dos débitos')
    expect(page).toContain('armazenados somente no vault até a execução')
    expect(page).toContain('o hub nunca inicia pagamento ou Pix')
    expect(page).toContain('detailDigitalGuides')
    expect(page).toContain('FGTS_DIGITAL_PORTAL')
    expect(page).toContain('api.fiscal.guides.issueDownloadToken')
  })
})

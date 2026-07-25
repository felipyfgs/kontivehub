import { describe, expect, it, vi } from 'vitest'
import { createMeiPublicServicesApi } from '~/composables/api/createMeiPublicServicesApi'
import {
  hasIntegralDasnReceipt,
  meiAttemptStatusMeta,
  meiCoverageMeta,
  meiProviderMeta,
  shouldPollMeiAttempt
} from '~/utils/mei-public-services'
import type { ApiClient } from '~/composables/api/types'
import type { MeiAutomationAttempt } from '~/types/mei-public-services'

const attempt = (status: MeiAutomationAttempt['status']): MeiAutomationAttempt => ({
  id: 10,
  client_id: 20,
  operation_key: 'dasnsimei.consultimadecrec',
  provider: 'RECEITA_PORTAL',
  status,
  source_provenance: 'RECEITA_PORTAL',
  verification_kind: 'PORTAL_ARTIFACT',
  fallback_reason: null,
  error_code: null,
  error_message: null,
  metadata: {},
  artifacts: [],
  started_at: null,
  last_synced_at: null,
  finished_at: null,
  created_at: null
})

describe('contratos dos serviços públicos MEI', () => {
  it('usa somente rotas Laravel e envia idempotência da emissão no header', async () => {
    const client = vi.fn().mockResolvedValue({ data: attempt('QUEUED') }) as ApiClient
    const api = createMeiPublicServicesApi(client, path => `https://app.test${path}`)

    await api.preflightDas({
      client_id: 20,
      competencies: ['2026-01'],
      output_format: 'PDF'
    }, 'das-idempotency-001')

    await api.generateDas({
      client_id: 20,
      competencies: ['2026-01'],
      output_format: 'PDF',
      preflight_token: 'preflight-001',
      confirmation_phrase: 'CONFIRMAR EMISSAO DAS',
      confirmed: true
    }, 'das-idempotency-001')

    expect(client).toHaveBeenCalledWith(
      '/api/v1/fiscal/simples-mei/pgmei/das/preflight',
      expect.objectContaining({
        method: 'POST',
        body: expect.not.objectContaining({ office_id: expect.anything() }),
        headers: { 'Idempotency-Key': 'das-idempotency-001' }
      })
    )
    expect(client).toHaveBeenCalledWith(
      '/api/v1/fiscal/simples-mei/pgmei/das',
      expect.objectContaining({
        method: 'POST',
        body: expect.not.objectContaining({ office_id: expect.anything() }),
        headers: { 'Idempotency-Key': 'das-idempotency-001' }
      })
    )
    expect(api.artifactDownloadUrl(10, 'artifact-1')).toBe(
      'https://app.test/api/v1/fiscal/mei-automation/attempts/10/artifacts/artifact-1/download'
    )
  })

  it('preserva progresso, cobertura parcial e proveniência', () => {
    expect(shouldPollMeiAttempt(attempt('RUNNING'))).toBe(true)
    expect(shouldPollMeiAttempt(attempt('WAITING_USER_ACTION'))).toBe(false)
    expect(shouldPollMeiAttempt(attempt('UNCERTAIN'))).toBe(false)
    expect(meiCoverageMeta('SUMMARY').label).toBe('Resumo')
    expect(hasIntegralDasnReceipt('SUMMARY', true)).toBe(false)
    expect(hasIntegralDasnReceipt('FULL', true)).toBe(true)
    expect(meiProviderMeta('RECEITA_PORTAL').label).toBe('Portal Receita')
    expect(meiProviderMeta('SERPRO').label).toBe('SERPRO')
    expect(meiProviderMeta('SERPRO', 'PORTAL_DRIFT').label).toBe('Contingência')
    expect(meiAttemptStatusMeta('WAITING_USER_ACTION').label).toBe('Ação necessária')
  })
})

import { describe, expect, it } from 'vitest'
import type { SitfisRefreshResponse, SitfisShowResponse } from '~/types/fiscal-modules'

describe('sitfis show/refresh typing', () => {
  it('SitfisShowResponse carries evidence download contract', () => {
    const view: SitfisShowResponse = {
      evidence_artifact_id: 42,
      can_refresh: true,
      block_reason: null,
      is_negative_certificate: false,
      links: { evidence_download: '/api/v1/fiscal/evidence/42/download' }
    }
    expect(view.links?.evidence_download).toContain('/evidence/42/')
    expect(view.is_negative_certificate).toBe(false)
  })

  it('SitfisRefreshResponse distinguishes enqueue honesty', () => {
    const withinTtl: SitfisRefreshResponse = {
      enqueued: false,
      reason: 'WITHIN_TTL'
    }
    const queued: SitfisRefreshResponse = {
      enqueued: true,
      reason: 'ENQUEUED'
    }
    expect(withinTtl.enqueued).toBe(false)
    expect(queued.enqueued).toBe(true)
  })
})

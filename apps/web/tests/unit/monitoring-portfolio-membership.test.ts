import { describe, expect, it } from 'vitest'
import {
  monitoringDestinationAfterClientCreate
} from '~/utils/monitoring-post-create'
import { monitoringBulkActionState } from '~/utils/monitoring-actions'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

describe('monitoring-portfolio-membership', () => {
  it('redirect pós-create aponta para as rotas próprias de SN e MEI', () => {
    expect(monitoringDestinationAfterClientCreate('SIMPLES_NACIONAL')).toEqual({
      path: '/monitoring/simples'
    })
    expect(monitoringDestinationAfterClientCreate('MEI')).toEqual({
      path: '/monitoring/mei'
    })
    expect(monitoringDestinationAfterClientCreate('LUCRO_PRESUMIDO')).toBeNull()
  })

  it('bulk actions expõe membership sem seleção', () => {
    const state = monitoringBulkActionState({
      moduleKey: 'dctfweb',
      selectedCount: 0,
      canAssociate: false,
      canEnqueue: false,
      canExport: false,
      canMembership: true
    })
    expect(state.membership).toBe(true)
    expect(state.visible).toBe(true)
  })

  it('modal e API de membership estão ligados', () => {
    const modal = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/AssociateMonitoringClientsModal.vue'),
      'utf8'
    )
    expect(modal).toContain('associate-monitoring-clients-modal')
    expect(modal).toContain('monitoringMembership')

    const api = readFileSync(
      resolve(process.cwd(), 'app/composables/api/createFiscalApi.ts'),
      'utf8'
    )
    expect(api).toContain('monitoringMembership')
    expect(api).toContain('/fiscal/monitoring/membership/exclude')
  })
})

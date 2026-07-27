import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import { monitoringActionMatrix } from '~/utils/monitoring-actions'

function user(effectivePermissions: string[]): MeUser {
  return {
    id: 1,
    effective_permissions: effectivePermissions,
    context_status: 'ok'
  } as MeUser
}

describe('monitoringActionMatrix', () => {
  it('bloqueia ações sem permissões efetivas', () => {
    const matrix = monitoringActionMatrix(user([]))
    expect(matrix.every(action => action.allowed === false)).toBe(true)
    expect(matrix.find(a => a.id === 'enqueue_read')?.reason).toContain('Sem permissão')
  })

  it('libera apenas as capacidades concedidas', () => {
    const matrix = monitoringActionMatrix(user([
      'clients.manage',
      'fiscal.sync.trigger',
      'exports.create'
    ]))
    expect(matrix.find(a => a.id === 'add_client')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'enqueue_read')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'export_portfolio')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'high_risk_mutation')?.allowed).toBe(false)
    expect(matrix.find(a => a.id === 'high_risk_mutation')?.reason).toContain('Sem permissão')
  })
})

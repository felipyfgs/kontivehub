import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import { monitoringActionMatrix } from '~/utils/monitoring-actions'

function user(role: MeUser['role']): MeUser {
  return {
    id: 1,
    role,
    context_status: 'ready'
  } as MeUser
}

describe('monitoringActionMatrix', () => {
  it('blocks mutations for VIEWER', () => {
    const matrix = monitoringActionMatrix(user('VIEWER'))
    expect(matrix.every(action => action.allowed === false)).toBe(true)
    expect(matrix.find(a => a.id === 'enqueue_read')?.reason).toContain('VIEWER')
  })

  it('allows operator reads/exports but not high-risk', () => {
    const matrix = monitoringActionMatrix(user('OPERATOR'))
    expect(matrix.find(a => a.id === 'add_client')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'enqueue_read')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'export_portfolio')?.allowed).toBe(true)
    expect(matrix.find(a => a.id === 'high_risk_mutation')?.allowed).toBe(false)
    expect(matrix.find(a => a.id === 'high_risk_mutation')?.reason).toContain('ADMIN')
  })
})

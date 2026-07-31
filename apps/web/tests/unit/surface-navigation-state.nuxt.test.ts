import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'
import {
  SURFACE_NAVIGATION,
  clearSurfaceNavigationState,
  consumeSurfaceNavigationIntent,
  publishSurfaceNavigationIntent,
  useSurfaceNavigationState
} from '../../app/composables/useSurfaceNavigationState'

const auth = vi.hoisted(() => ({
  user: { value: null as Record<string, unknown> | null }
}))

describe('useSurfaceNavigationState', () => {
  beforeEach(() => {
    auth.user.value = null
    vi.stubGlobal('useSanctumAuth', () => ({ user: auth.user }))
    clearSurfaceNavigationState()
  })

  it('preserva estado na mesma superfície/contexto e isola outra sessão', () => {
    const surface = SURFACE_NAVIGATION.clients
    const first = useSurfaceNavigationState(surface, { page: 1, q: '' }, { resetKey: ref('user-1:tenant-1') })
    first.patch({ page: 3, q: 'cliente' })

    const sameContext = useSurfaceNavigationState(surface, { page: 1, q: '' }, { resetKey: ref('user-1:tenant-1') })
    const otherTenant = useSurfaceNavigationState(surface, { page: 1, q: '' }, { resetKey: ref('user-1:tenant-2') })

    expect(sameContext.state.value).toEqual({ page: 3, q: 'cliente' })
    expect(otherTenant.state.value).toEqual({ page: 1, q: '' })

    clearSurfaceNavigationState()
    expect(first.state.value).toEqual({ page: 1, q: '' })
    expect(otherTenant.state.value).toEqual({ page: 1, q: '' })
  })

  it('consome intenções no máximo uma vez e devolve uma cópia', () => {
    const surface = SURFACE_NAVIGATION.health
    const payload = { tab: 'overdue', filters: { departmentId: 7 } }
    publishSurfaceNavigationIntent(surface, payload)
    payload.filters.departmentId = 9

    expect(consumeSurfaceNavigationIntent(surface)).toEqual({
      tab: 'overdue',
      filters: { departmentId: 7 }
    })
    expect(consumeSurfaceNavigationIntent(surface)).toBeNull()
  })

  it('remove proxies Vue de arrays antes de persistir o estado', () => {
    const navigation = useSurfaceNavigationState(
      SURFACE_NAVIGATION.documents.catalog,
      { labelIds: [] as number[] },
      { resetKey: ref('user-1:tenant-1') }
    )
    const selected = reactive([9, 10])

    expect(() => navigation.patch({ labelIds: selected })).not.toThrow()
    expect(navigation.state.value.labelIds).toEqual([9, 10])
    expect(navigation.state.value.labelIds).not.toBe(selected)
  })

  it('rejeita superfícies fora do catálogo allowlisted', () => {
    expect(() => publishSurfaceNavigationIntent('unknown.surface' as never, {})).toThrow(
      'Superfície de navegação não allowlisted'
    )
  })

  it('descarta intenção quando usuário ou tenant muda antes do consumo', () => {
    auth.user.value = { id: 1, current_tenant: { id: 10 } }
    publishSurfaceNavigationIntent(SURFACE_NAVIGATION.health, { severity: 'high' })

    auth.user.value = { id: 1, current_tenant: { id: 11 } }
    expect(consumeSurfaceNavigationIntent(SURFACE_NAVIGATION.health)).toBeNull()

    auth.user.value = { id: 1, current_tenant: { id: 10 } }
    expect(consumeSurfaceNavigationIntent(SURFACE_NAVIGATION.health)).toBeNull()
  })

  it('transfere uma intenção anônima somente para o primeiro login', () => {
    publishSurfaceNavigationIntent(SURFACE_NAVIGATION.health, { severity: 'high' })

    auth.user.value = { id: 1, current_tenant: { id: 10 } }
    expect(consumeSurfaceNavigationIntent(SURFACE_NAVIGATION.health)).toEqual({ severity: 'high' })
    expect(consumeSurfaceNavigationIntent(SURFACE_NAVIGATION.health)).toBeNull()

    publishSurfaceNavigationIntent(SURFACE_NAVIGATION.health, { severity: 'low' })
    auth.user.value = { id: 2, current_tenant: { id: 20 } }
    expect(consumeSurfaceNavigationIntent(SURFACE_NAVIGATION.health)).toBeNull()
  })
})

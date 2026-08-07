import { describe, expect, it, vi } from 'vitest'
import { refreshIdentitySingleFlight } from '../../app/utils/identity-refresh'

describe('identity refresh single-flight', () => {
  it('compartilha refreshes concorrentes e permite recuperação depois de falha', async () => {
    const scope = {}
    let release!: () => void
    const refresh = vi.fn(() => new Promise<void>((resolve) => {
      release = resolve
    }))

    const first = refreshIdentitySingleFlight(scope, refresh)
    const second = refreshIdentitySingleFlight(scope, () => refresh())

    expect(refresh).toHaveBeenCalledTimes(1)
    expect(first).toBe(second)
    release()
    await first

    const independent = vi.fn().mockResolvedValue(undefined)
    await refreshIdentitySingleFlight({}, independent)
    expect(independent).toHaveBeenCalledTimes(1)

    const failed = vi.fn().mockRejectedValueOnce(new Error('sessão expirada'))
    await expect(refreshIdentitySingleFlight(scope, failed)).rejects.toThrow('sessão expirada')
    await refreshIdentitySingleFlight(scope, vi.fn().mockResolvedValue(undefined))
  })

  it('não reutiliza um resultado concluído após logout ou troca de tenant', async () => {
    const scope = {}
    const observedContexts: Array<number | null> = []
    let tenantId: number | null = 10
    const refresh = vi.fn(async () => {
      observedContexts.push(tenantId)
    })

    await refreshIdentitySingleFlight(scope, refresh)
    tenantId = null
    await refreshIdentitySingleFlight(scope, refresh)
    tenantId = 20
    await refreshIdentitySingleFlight(scope, refresh)

    expect(refresh).toHaveBeenCalledTimes(3)
    expect(observedContexts).toEqual([10, null, 20])
  })
})

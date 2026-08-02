import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSavedListPresets } from '~/composables/useSavedListPresets'
import type { SavedListFilter } from '~/types/saved-list-filters'

const mocks = vi.hoisted(() => ({
  addToast: vi.fn()
}))

vi.mock('~/composables/useApi', () => ({
  useApi: () => ({ savedListFilters: {} })
}))

vi.mock('~/composables/useDashboard', () => ({
  useDashboard: () => ({ me: { value: null } })
}))

const filter: SavedListFilter = {
  id: 1,
  surface: 'communication.conversations',
  name: 'Pendentes',
  visibility: 'personal',
  schema_version: 1,
  payload: {
    status: 'PENDING',
    sort_by: 'priority_desc'
  },
  author: { id: 1, name: 'Operador' },
  permissions: { update: true, delete: true, share: false },
  created_at: null,
  updated_at: null
}

describe('useSavedListPresets', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('useToast', () => ({ add: mocks.addToast }))
  })

  it('apresenta a falha assíncrona sem afirmar que a visão foi aplicada', async () => {
    const onApply = vi.fn().mockRejectedValue(new Error('Falha controlada'))
    const { applyPreset } = useSavedListPresets({
      surface: 'communication.conversations',
      getPayload: () => filter.payload,
      canSave: () => true,
      onApply
    })

    await applyPreset(filter)

    expect(onApply).toHaveBeenCalledWith(filter.payload, filter)
    expect(mocks.addToast).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Não foi possível aplicar a visão.',
      color: 'error'
    }))
  })
})

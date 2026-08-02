import { computed, ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { createDashboardContextualCommandRegistry } from '~/utils/dashboard-contextual-command-registry'

describe('registro contextual da busca global', () => {
  it('substitui o mesmo owner e torna cleanup antigo idempotente', () => {
    const registry = createDashboardContextualCommandRegistry()
    const firstCleanup = registry.register('communication', [{
      id: 'first',
      items: [{ label: 'Primeiro' }]
    }])
    const secondCleanup = registry.register('communication', [{
      id: 'second',
      items: [{ label: 'Segundo' }]
    }])

    expect(registry.groups.value.map(group => group.id)).toEqual(['second'])
    firstCleanup()
    expect(registry.groups.value.map(group => group.id)).toEqual(['second'])
    secondCleanup()
    expect(registry.groups.value).toEqual([])
  })

  it('mantém grupos reativos e limpa todos na troca de sessão', () => {
    const registry = createDashboardContextualCommandRegistry()
    const enabled = ref(false)
    registry.register('communication', computed(() => [{
      id: 'communication',
      items: enabled.value ? [{ label: 'Responder' }] : []
    }]))

    expect(registry.groups.value[0]?.items).toEqual([])
    enabled.value = true
    expect(registry.groups.value[0]?.items?.[0]?.label).toBe('Responder')
    registry.clear()
    expect(registry.groups.value).toEqual([])
  })
})

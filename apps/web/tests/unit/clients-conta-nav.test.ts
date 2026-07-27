import { describe, expect, it } from 'vitest'
import { ACCOUNT_NAVIGATION, accountNavigationItems } from '~/utils/account-navigation'
import { clientDetailHref } from '~/utils/client-detail-tabs'
import type { MeUser } from '~/types/api'

const admin = {
  id: 3,
  tenant_role: 'tenant_admin',
  effective_permissions: [
    'tenant.settings.view',
    'tenant.users.manage',
    'work.catalog.manage'
  ],
  current_tenant: {
    id: 1,
    name: 'Escritório Teste',
    slug: 'escritorio-teste'
  },
  context_status: 'ok'
} as MeUser

describe('clients + conta navigation', () => {
  it('builds client detail hrefs for active tabs', () => {
    expect(clientDetailHref(9, 'cadastro')).toBe('/clients/9/cadastro')
    expect(clientDetailHref(9, 'contato')).toBe('/clients/9/contato')
    expect(clientDetailHref(9, 'contratos')).toBe('/clients/9/contratos')
  })

  it('exposes conta paths via account navigation', () => {
    expect(ACCOUNT_NAVIGATION.tenant.to).toBe('/conta/escritorio')
    expect(ACCOUNT_NAVIGATION.team.to).toBe('/conta/equipe')
    expect(ACCOUNT_NAVIGATION.usage.to).toBe('/conta/consumo')

    const items = accountNavigationItems(admin)
    expect(items.some(item => item.to === '/conta')).toBe(true)
    expect(items.some(item => item.to.startsWith('/conta/'))).toBe(true)
  })
})

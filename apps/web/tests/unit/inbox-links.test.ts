import { describe, expect, it } from 'vitest'
import { normalizeTenantPath, resolveInboxItemLink } from '~/utils/inbox-links'

describe('normalizeTenantPath', () => {
  it('reescreve /settings/consumo para /conta/consumo', () => {
    expect(normalizeTenantPath('/settings/consumo')).toBe('/conta/consumo')
    expect(normalizeTenantPath('/settings/usage')).toBe('/conta/consumo')
  })
})

describe('resolveInboxItemLink', () => {
  it('usa link de usage canônico mesmo com path legado', () => {
    expect(resolveInboxItemLink({
      type: 'usage_high',
      links: { usage: '/settings/consumo' },
      reasons: ['USAGE_ALERT']
    })).toBe('/conta/consumo')
  })
})

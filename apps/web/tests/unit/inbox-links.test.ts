import { describe, expect, it } from 'vitest'
import { resolveInboxItemLink, validateTenantPath } from '~/utils/inbox-links'

describe('validateTenantPath', () => {
  it('aceita somente deep-links internos atuais', () => {
    expect(validateTenantPath('/conta/consumo')).toBe('/conta/consumo')
    expect(validateTenantPath('https://example.com/conta/consumo')).toBeNull()
  })
})

describe('resolveInboxItemLink', () => {
  it('usa o destino canônico quando o link recebido é inválido', () => {
    expect(resolveInboxItemLink({
      type: 'usage_high',
      links: { usage: 'https://example.com/conta/consumo' },
      reasons: ['USAGE_ALERT']
    })).toBe('/conta/consumo')
  })
})

import { describe, expect, it } from 'vitest'
import {
  clientCrmHref,
  clientFiscalHref
} from '~/utils/client-cross-links'

describe('client-cross-links', () => {
  it('monta href CRM e fiscal do mesmo cliente', () => {
    expect(clientCrmHref(1)).toBe('/clients/1/cadastro')
    expect(clientCrmHref(1, 'dados-adicionais')).toBe('/clients/1/dados-adicionais')
    expect(clientFiscalHref(1)).toBe('/monitoring/clients/1')
    expect(clientFiscalHref(1, 'ccmei')).toBe('/monitoring/clients/1/ccmei')
  })
})

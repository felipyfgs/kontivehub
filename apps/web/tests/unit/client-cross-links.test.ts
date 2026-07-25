import { describe, expect, it } from 'vitest'
import {
  clientCrmHref,
  clientFiscalHref,
  isLegacyFiscalSegment,
  legacyFiscalSegmentToHref
} from '~/utils/client-cross-links'

describe('client-cross-links', () => {
  it('monta href CRM e fiscal do mesmo cliente', () => {
    expect(clientCrmHref(1)).toBe('/clients/1/cadastro')
    expect(clientCrmHref(1, 'configuracao')).toBe('/clients/1/dados-adicionais')
    expect(clientCrmHref(1, 'dados-adicionais')).toBe('/clients/1/dados-adicionais')
    expect(clientFiscalHref(1)).toBe('/monitoring/clients/1')
    expect(clientFiscalHref(1, 'ccmei')).toBe('/monitoring/clients/1/ccmei')
  })

  it('mapeia segmentos legados fiscais para o monitoring', () => {
    expect(legacyFiscalSegmentToHref(3, 'fiscal')).toBe('/monitoring/clients/3')
    expect(legacyFiscalSegmentToHref(3, 'ccmei')).toBe('/monitoring/clients/3/ccmei')
    expect(legacyFiscalSegmentToHref(3, 'renuncias')).toBe('/monitoring/clients/3/renunciations')
    expect(legacyFiscalSegmentToHref(3, 'certificado')).toBeNull()
    expect(isLegacyFiscalSegment('fiscal')).toBe(true)
    expect(isLegacyFiscalSegment('certificado')).toBe(false)
  })
})

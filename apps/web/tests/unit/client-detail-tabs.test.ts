import { describe, expect, it } from 'vitest'
import {
  clientDetailHref,
  clientIsMei,
  clientToolbarTabForPath,
  primaryTabItems
} from '~/utils/client-detail-tabs'

describe('client-detail-tabs (layout master)', () => {
  it('monta href canônico das abas', () => {
    expect(clientDetailHref(7, 'cadastro')).toBe('/clients/7/cadastro')
    expect(clientDetailHref(7, 'dados-adicionais')).toBe('/clients/7/dados-adicionais')
    expect(clientDetailHref(7, 'contato')).toBe('/clients/7/contato')
    expect(clientDetailHref(7, 'departamento')).toBe('/clients/7/departamento')
    expect(clientDetailHref(7, 'observacoes')).toBe('/clients/7/observacoes')
    expect(clientDetailHref(7, 'contratos')).toBe('/clients/7/contratos')
  })

  it('ativa toolbar correta por path', () => {
    expect(clientToolbarTabForPath('/clients/7/cadastro')).toBe('cadastro')
    expect(clientToolbarTabForPath('/clients/7/dados-adicionais')).toBe('dados-adicionais')
    expect(clientToolbarTabForPath('/clients/7/contato')).toBe('contato')
    expect(clientToolbarTabForPath('/clients/7/departamento')).toBe('departamento')
    expect(clientToolbarTabForPath('/clients/7/observacoes')).toBe('observacoes')
    expect(clientToolbarTabForPath('/clients/7/configuracao')).toBeNull()
    expect(clientToolbarTabForPath('/clients/7/ccmei')).toBeNull()
  })

  it('expõe abas primárias do layout mockup', () => {
    expect(primaryTabItems().map(i => i.value)).toEqual([
      'cadastro',
      'dados-adicionais',
      'contato',
      'departamento',
      'observacoes',
      'contratos'
    ])
  })

  it('detecta MEI com evidência positiva', () => {
    expect(clientIsMei({ tax_regime: 'MEI' })).toBe(true)
    expect(clientIsMei({ tax_regime: 'SIMPLES_NACIONAL', establishments: [{ mei_optant: false }] })).toBe(false)
  })
})

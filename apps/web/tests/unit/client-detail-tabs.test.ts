import { describe, expect, it } from 'vitest'
import {
  clientDetailHref,
  clientIsMei,
  clientToolbarTabForPath,
  legacyClientPathToHref,
  legacySectionToHref,
  parseClientDetailQuery,
  primaryTabItems,
  queryToClientDetailHref
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

  it('redireciona legado fiscal → monitoring e CRM para o resto', () => {
    expect(clientDetailHref(7, 'cadastro', 'contatos')).toBe('/clients/7/contato')
    expect(clientDetailHref(7, 'dados-adicionais', 'certificado')).toBe('/clients/7/dados-adicionais')
    expect(legacyClientPathToHref(3, 'fiscal')).toBe('/monitoring/clients/3')
    expect(legacyClientPathToHref(3, 'ccmei')).toBe('/monitoring/clients/3/ccmei')
    expect(legacyClientPathToHref(3, 'renuncias')).toBe('/monitoring/clients/3/renunciations')
    expect(legacyClientPathToHref(3, 'estabelecimentos')).toBe('/clients/3/cadastro')
    expect(legacyClientPathToHref(3, 'certificado')).toBe('/clients/3/dados-adicionais')
    expect(legacyClientPathToHref(3, 'configuracao')).toBe('/clients/3/dados-adicionais')
    expect(legacySectionToHref(3, 'sincronizacao')).toBe('/clients/3/dados-adicionais')
  })

  it('parseia query legada para tabs novas ou monitoring', () => {
    expect(parseClientDetailQuery({})).toEqual({ tab: 'cadastro', panel: 'dados' })
    expect(parseClientDetailQuery({ tab: 'fiscal', panel: 'ccmei' })).toEqual({
      tab: 'cadastro'
    })
    expect(queryToClientDetailHref(7, { tab: 'fiscal' })).toBe('/monitoring/clients/7')
    expect(queryToClientDetailHref(7, { tab: 'fiscal', panel: 'ccmei' })).toBe(
      '/monitoring/clients/7/ccmei'
    )
    expect(queryToClientDetailHref(7, { tab: 'integracoes', panel: 'certificado' })).toBe(
      '/clients/7/dados-adicionais'
    )
    expect(queryToClientDetailHref(7, { panel: 'contatos' })).toBe('/clients/7/contato')
  })

  it('ativa toolbar correta por path', () => {
    expect(clientToolbarTabForPath('/clients/7/cadastro')).toBe('cadastro')
    expect(clientToolbarTabForPath('/clients/7/dados-adicionais')).toBe('dados-adicionais')
    expect(clientToolbarTabForPath('/clients/7/contato')).toBe('contato')
    expect(clientToolbarTabForPath('/clients/7/departamento')).toBe('departamento')
    expect(clientToolbarTabForPath('/clients/7/observacoes')).toBe('observacoes')
    expect(clientToolbarTabForPath('/clients/7/configuracao')).toBe('dados-adicionais')
    expect(clientToolbarTabForPath('/clients/7/ccmei')).toBe('cadastro')
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

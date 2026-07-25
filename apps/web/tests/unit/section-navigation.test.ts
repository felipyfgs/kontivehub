import { describe, expect, it } from 'vitest'
import { ACCOUNT_NAV_ITEMS, ACCOUNT_NAVIGATION } from '~/utils/account-navigation'
import { clientDetailNav } from '~/utils/client-detail-navigation'
import { resolveNavSelection } from '~/utils/navigation-hierarchy'
import {
  toDesktopSubtabItems,
  toDesktopTabItems,
  toMobileSelectOptions
} from '~/utils/section-navigation'

describe('section-navigation mappers', () => {
  it('Conta (flat): cada seção é folha — sem grupos/subtabs', () => {
    const path = ACCOUNT_NAVIGATION.team.to
    const selection = resolveNavSelection(ACCOUNT_NAV_ITEMS, path)
    const tabs = toDesktopTabItems(selection, path)
    const subtabs = toDesktopSubtabItems(selection, path)

    expect(tabs.map(item => item.label)).toEqual([
      'Perfil',
      'Escritório',
      'Departamentos',
      'Equipe',
      'Assinatura',
      'Consumo'
    ])
    expect(tabs.find(item => item.id === 'account-team')).toMatchObject({
      active: true,
      to: ACCOUNT_NAVIGATION.team.to
    })
    expect(subtabs).toEqual([])
    expect(tabs.every(item => !('children' in item))).toBe(true)
  })

  it('Conta escritório: folha ativa sem subtabs', () => {
    const path = ACCOUNT_NAVIGATION.office.to
    const selection = resolveNavSelection(ACCOUNT_NAV_ITEMS, path)
    const tabs = toDesktopTabItems(selection, path)
    const subtabs = toDesktopSubtabItems(selection, path)

    expect(tabs.find(item => item.id === 'account-office')).toMatchObject({
      active: true,
      to: ACCOUNT_NAVIGATION.office.to
    })
    expect(subtabs).toEqual([])
  })

  it('cliente: abas do layout master na toolbar', () => {
    const nav = clientDetailNav(7)
    expect(nav.map(item => ('label' in item ? item.label : ''))).toEqual([
      'Dados cadastrais',
      'Dados adicionais',
      'Contatos',
      'Departamentos',
      'Observações',
      'Contratos'
    ])
    expect(nav.every(item => !('children' in item))).toBe(true)
    const adicionais = nav.find(item => item.id === 'client-dados-adicionais')
    expect(adicionais && 'to' in adicionais ? adicionais.to : null).toBe('/clients/7/dados-adicionais')
  })

  it('mobile Conta: labels planos (sem Grupo · Folha)', () => {
    const options = toMobileSelectOptions(ACCOUNT_NAV_ITEMS)
    expect(options.map(item => item.label)).toEqual([
      'Perfil',
      'Escritório',
      'Departamentos',
      'Equipe',
      'Assinatura',
      'Consumo'
    ])
  })
})

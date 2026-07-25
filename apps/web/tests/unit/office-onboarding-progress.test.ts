import { describe, expect, it } from 'vitest'
import {
  onboardingIsInProgress,
  onboardingStageIndex,
  onboardingStatusLabel
} from '../../app/utils/office-settings'

describe('progresso do onboarding fiscal do escritório', () => {
  it('mantém a ordem das seis etapas automáticas', () => {
    expect(onboardingStageIndex('CONFIGURANDO')).toBe(0)
    expect(onboardingStageIndex('CARREGANDO_PROCURACOES')).toBe(3)
    expect(onboardingStageIndex('PRONTO')).toBe(5)
  })

  it('faz polling apenas nos estados transitórios', () => {
    expect(onboardingIsInProgress('validating')).toBe(true)
    expect(onboardingIsInProgress('loading_proxy_powers')).toBe(true)
    expect(onboardingIsInProgress('ready')).toBe(false)
    expect(onboardingIsInProgress('technical_error')).toBe(false)
  })

  it('usa linguagem operacional sem exigir OAuth manual', () => {
    expect(onboardingStatusLabel('authorizing')).toBe('Autorizando acesso')
    expect(onboardingStatusLabel('loading_proxy_powers')).toBe('Carregando procurações')
    expect(onboardingStatusLabel('syncing')).toBe('Sincronizando dados')
  })
})

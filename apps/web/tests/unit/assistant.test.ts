import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { canUseAssistant, isAssistantAvailable } from '~/utils/assistant'
import type { MeUser } from '~/types/api'

function source(rel: string): string {
  return readFileSync(resolve(__dirname, '../../', rel), 'utf8')
}

function me(partial: Partial<MeUser> = {}): MeUser {
  return {
    id: 1,
    name: 'Test',
    email: 't@example.com',
    two_factor_confirmed: false,
    two_factor_required: false,
    requires_two_factor_setup: false,
    office: null,
    role: 'ADMIN',
    ...partial
  }
}

describe('assistant availability', () => {
  it('exige me.assistant.enabled === true', () => {
    expect(isAssistantAvailable(undefined)).toBe(false)
    expect(canUseAssistant(me())).toBe(false)
    expect(canUseAssistant(me({ assistant: { enabled: false } }))).toBe(false)
    expect(canUseAssistant(me({ assistant: { enabled: true } }))).toBe(true)
  })
})

describe('assistant UI source contracts', () => {
  it('slideover preserva MVP JSON e sugestões Work de leitura', () => {
    const slideover = source('app/components/AssistantSlideover.vue')
    expect(slideover).toContain('useAssistantChat')
    expect(slideover).toContain('sendMessage')
    expect(slideover).toContain('UChatTool')
    expect(slideover).toContain('Aprovar')
    expect(slideover).toContain('Negar')
    expect(slideover).toContain('WORK_SUGGESTIONS')
    expect(slideover).toContain('Liste os departamentos Work')
    expect(slideover).toContain('Quais módulos de monitoramento posso usar?')
  })

  it('superfícies de abertura usam canUseAssistant / assistantAvailable', () => {
    const util = source('app/utils/assistant.ts')
    expect(util).toContain('canUseAssistant')
    expect(util).toContain('me?.assistant?.enabled === true')

    const dashboard = source('app/composables/useDashboard.ts')
    expect(dashboard).toContain('canUseAssistant(me.value)')
    expect(dashboard).toContain('assistantAvailable')
  })
})

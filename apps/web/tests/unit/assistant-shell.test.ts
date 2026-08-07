import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

function source(rel: string): string {
  return readFileSync(resolve(__dirname, '../../', rel), 'utf8')
}

describe('contrato UI do assistente no shell', () => {
  it('monta AssistantSlideover e trigger no shell', () => {
    const layout = source('app/layouts/default.vue')
    expect(layout).toContain('<AssistantSlideover')
    expect(layout).toContain('<AssistantTriggerButton')
  })

  it('useDashboard abre o sheet com gate assistantAvailable no shift_a', () => {
    const dashboard = source('app/composables/useDashboard.ts')
    expect(dashboard).toContain('openAssistantSlideover')
    expect(dashboard).toContain('shift_a')
    expect(dashboard).toContain('assistantAvailable')
    expect(dashboard).toMatch(/shift_a[\s\S]*?assistantAvailable\.value/)
    expect(dashboard).toMatch(/function openAssistantSlideover\(\)[\s\S]*?assistantAvailable\.value/)
  })

  it('slideover usa layout ChatPalette canônico (body p-0 + prompt slot)', () => {
    const slideover = source('app/components/AssistantSlideover.vue')
    expect(slideover).toContain('bootstrapOnOpen')
    expect(slideover).not.toContain('v-if="available"')
    expect(slideover).toContain('UChatPalette')
    expect(slideover).toContain('body: \'flex-1 flex flex-col min-h-0 overflow-hidden p-0 sm:p-0\'')
    expect(slideover).toContain('#prompt')
    expect(slideover).toContain('assistant-suggestions')
    expect(slideover).toContain('Quais modelos de processo temos?')
    expect(slideover).toContain('side: \'right\'')
    expect(slideover).toContain('variant: \'soft\'')
    expect(slideover).toContain('UChatShimmer')
    expect(slideover).toContain(':error="promptError"')
    expect(slideover).not.toContain('@ai-sdk/vue')
    expect(slideover).not.toContain('@comark')
  })

  it('AssistantTriggerButton exige auth e assistantAvailable', () => {
    const trigger = source('app/components/AssistantTriggerButton.vue')
    expect(trigger).toContain('assistantAvailable')
    expect(trigger).toContain('isAuthenticated.value && assistantAvailable.value')
  })

  it('UserMenu não oferece Assistente (abertura só no header/sidebar)', () => {
    const menu = source('app/components/UserMenu.vue')
    expect(menu).not.toContain('assistantAvailable')
    expect(menu).not.toContain('openAssistantSlideover')
    expect(menu).not.toContain('label: \'Assistente\'')
  })
})

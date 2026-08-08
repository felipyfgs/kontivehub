import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const timeline = readFileSync(
  resolve(process.cwd(), 'app/components/communication/TimelinePanel.vue'),
  'utf8'
)

describe('layout da timeline de comunicação', () => {
  it('encaminha os attrs do painel desktop à raiz UDashboardPanel', () => {
    expect(timeline).toContain('defineOptions({ inheritAttrs: false })')
    expect(timeline).toMatch(/<UDashboardPanel\s+v-bind="\$attrs"/)
  })

  it('ancora conversas curtas no rodapé sem esconder o início de históricos longos', () => {
    expect(timeline).toContain('ref="messagesContent" class="flex min-h-full flex-col"')
    expect(timeline).toContain('data-testid="communication-timeline-content"')
    expect(timeline).toContain('class="flex flex-1 flex-col"')
    expect(timeline).toContain('data-testid="communication-message-stack"')
    expect(timeline).toContain('class="mt-auto space-y-3.5 sm:space-y-4"')
    expect(timeline).not.toMatch(/ref="messagesContent"[^>]*justify-end/)
    expect(timeline).not.toContain('<template v-else>')
  })

  it('preserva o acompanhamento condicional e a restauração da paginação', () => {
    expect(timeline).toContain('shouldFollowCommunicationTimeline')
    expect(timeline).toContain('if (followingLatest.value && paginationDirection.value === null)')
    expect(timeline).toContain('container.scrollHeight - paginationScrollHeight')
    expect(timeline).toContain('data-testid="communication-new-messages"')
  })
})

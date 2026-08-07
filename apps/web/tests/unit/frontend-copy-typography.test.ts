import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('copy e tipografia operacional', () => {
  it('mantém os rótulos operacionais de Guias em pt-BR', () => {
    const guides = source('app/pages/monitoring/guides.vue')

    expect(guides).toContain('label: \'Baixar\'')
    expect(guides).toContain('label="Tentar carregar resumo"')
    expect(guides).toContain('Em aberto (métrica do resumo):')
    expect(guides).not.toContain('label: \'Download\'')
    expect(guides).not.toContain('label="Retry overview"')
  })

  it('não usa tipografia operacional abaixo do token de 12 px', () => {
    const targets = [
      'app/components/docs/Detail.vue',
      'app/components/clients/ProcuracaoBadge.vue',
      'app/components/clients/CategoryBadge.vue',
      'app/components/monitoring/insights/NotificationsFeed.vue',
      'app/components/monitoring/insights/PendingCard.vue',
      'app/components/communication/TimelinePanel.vue',
      'app/components/communication/MessageContent.vue',
      'app/components/communication/ConversationListFilters.vue',
      'app/components/communication/ConversationList.vue'
    ]

    for (const target of targets) {
      expect(source(target)).not.toMatch(/text-\[(?:8|9|10|11)px\]/)
    }
  })
})

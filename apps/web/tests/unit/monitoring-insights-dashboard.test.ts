import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const pagePath = resolve(__dirname, '../../app/pages/monitoring/index.vue')
const page = readFileSync(pagePath, 'utf8')

describe('monitoring insights dashboard fidelity', () => {
  it('usa um payload agregado e organiza prioridades, saúde e contexto', () => {
    expect(page).toContain('monitoringInsights')
    expect(page).toContain('monitoring-priorities-section')
    expect(page).toContain('monitoring-health-section')
    expect(page).toContain('monitoring-context-section')
    expect(page).toContain('xl:col-span-7')
    expect(page).toContain('xl:col-span-5')
  })

  it('mantém copy honesta e distingue dados locais de estimativa', () => {
    expect(page.toLowerCase()).not.toContain('sublimite')
    expect(page).not.toContain('Excluído')
    expect(page).not.toContain('SPEDs')
    expect(page).toContain('Nenhum indicador foi estimado')
    expect(page).toContain('Dados locais')
    expect(page).toContain('sem iniciar consultas externas')
  })

  it('reposiciona consulta manual abaixo dos insights', () => {
    const gridIdx = page.indexOf('monitoring-context-section')
    const manualIdx = page.indexOf('monitoring-manual-consult-explorer')
    expect(gridIdx).toBeGreaterThan(-1)
    expect(manualIdx).toBeGreaterThan(gridIdx)
  })

  it('preserva scroll vertical do panel e bloqueia overflow horizontal', () => {
    expect(page).toContain(`:ui="{ body: 'overflow-x-hidden' }"`)
    expect(page).toContain('grid min-w-0 grid-cols-1')
  })

  it('mantém snapshot confirmado quando somente o refresh falha', () => {
    expect(page).toContain('A última leitura confirmada continua visível')
    expect(page).toContain('insights-refresh-error')
    expect(page).toContain('initialLoadError')
  })
})

describe('monitoring insights cards copy', () => {
  const cardsDir = resolve(__dirname, '../../app/components/monitoring/insights')

  it('RBT12 card declara que não é sublimite', () => {
    const src = readFileSync(resolve(cardsDir, 'Rbt12ChartCard.vue'), 'utf8')
    expect(src).toContain('RBT12')
    expect(src).toContain('Não é sublimite anual')
    expect(src).not.toMatch(/title>\s*Sublimites/i)
  })

  it('mailbox card não inventa bucket Excluído', () => {
    const src = readFileSync(resolve(cardsDir, 'MailboxBucketsCard.vue'), 'utf8')
    expect(src).toContain('Importante')
    expect(src).toContain('Em dia')
    expect(src).toContain('Outros')
    expect(src).toContain('sem “Excluído”')
  })

  it('DIRF aparece como UNSUPPORTED no progresso', () => {
    const src = readFileSync(resolve(cardsDir, 'ObligationsProgressCard.vue'), 'utf8')
    expect(src).toContain('UNSUPPORTED')
    expect(src).toContain('Sem dados reais')
  })

  it('ancora a medição dos gráficos em divs DOM, não no UPageCard', () => {
    for (const filename of ['Rbt12ChartCard.vue', 'SitfisDonutCard.vue']) {
      const src = readFileSync(resolve(cardsDir, filename), 'utf8')
      expect(src).toContain('ref="chartCard"')
      expect(src).not.toContain('<UPageCard\n    ref="chartCard"')
      expect(src).toContain('min-w-0')
    }
  })

  it('atividade recente aceita somente deep-link local', () => {
    const src = readFileSync(resolve(cardsDir, 'NotificationsFeed.vue'), 'utf8')
    expect(src).toContain(`item.deep_link?.startsWith('/')`)
    expect(src).toContain('/monitoring/clients/${item.client_id}')
  })

  it('traduz severidades e não mistura itens antigos com erro parcial', () => {
    const src = readFileSync(resolve(cardsDir, 'PendingCard.vue'), 'utf8')
    expect(src).toContain(`HIGH: 'Alta'`)
    expect(src).toContain(`MEDIUM: 'Média'`)
    expect(src).toContain('v-if="!error && data?.items?.length"')
  })
})

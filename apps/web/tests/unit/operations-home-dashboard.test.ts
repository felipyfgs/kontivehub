import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const pagePath = resolve(__dirname, '../../app/pages/index.vue')
const page = readFileSync(pagePath, 'utf8')

describe('operations home cockpit', () => {
  it('usa summary + inbox e preserva lastGood no refresh', () => {
    expect(page).toContain('api.operations.summary()')
    expect(page).toContain('api.operations.inbox')
    expect(page).toContain('lastGoodSummary')
    expect(page).toContain('sessionEpoch')
  })

  it('renderiza seções do cockpit com deep-links canônicos', () => {
    expect(page).toContain('HomeBlocksBanner')
    expect(page).toContain('HomeFiscalSlice')
    expect(page).toContain('HomeSerproOffice')
    expect(page).toContain('HomeCommunication')
    expect(page).toContain('HomeWorkKpisBlock')
    expect(page).toContain('HomeOperations')
    expect(page).toContain('title="Início"')
  })

  it('não embute insights fiscais densos nem charts de /monitoring', () => {
    expect(page).not.toContain('monitoringInsights')
    expect(page).not.toContain('Rbt12ChartCard')
    expect(page).not.toContain('SitfisDonutCard')
  })
})

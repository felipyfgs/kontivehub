import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('comunicação informativa do monitor fiscal', () => {
  it('expõe preferências, histórico e envio fail-closed no modal PGDAS-D', () => {
    const modal = read('app/components/monitoring/PgdasdCommunicationModals.vue')

    expect(modal).toContain('displayedPreference')
    expect(modal).toContain('loadTracking')
    expect(modal).toContain('Histórico local de comunicação')
    expect(modal).toContain('Nenhum transporte está ativo nesta capacidade')
    expect(modal).toContain('WhatsApp nativo disponível')
    expect(modal).toContain('<USwitch')
    expect(modal).toContain('savePreferences')
    expect(modal).toContain('updatePreferences')
    expect(modal).toContain('requestSend')
    expect(modal).toContain('label="Enviar"')
    expect(modal).toContain('if (isFgts.value) return document.download_href?.trim() || undefined')
  })

  it('monta o modal nas carteiras PGDAS-D, MEI, DCTFWeb e FGTS', () => {
    const simples = read('app/components/monitoring/simples-mei/Portfolio.vue')
    const dctfweb = read('app/pages/monitoring/dctfweb/index.vue')
    const fgts = read('app/pages/monitoring/fgts.vue')

    expect(simples.match(/<MonitoringPgdasdCommunicationModals/gu)?.length).toBeGreaterThanOrEqual(1)
    expect(simples).toContain('context="PGMEI"')
    expect(dctfweb).toContain('context="DCTFWEB"')
    expect(dctfweb).toContain('<MonitoringPgdasdCommunicationModals')
    expect(fgts).toContain('context="FGTS"')
    expect(fgts).toContain('<MonitoringPgdasdCommunicationModals')
  })

  it('remove switches individuais e em lote das tabelas e toolbars', () => {
    const sources = [
      read('app/utils/pgdasd-table.ts'),
      read('app/utils/pgmei-table.ts'),
      read('app/utils/dctfweb-table.ts'),
      read('app/components/monitoring/pgmei/BulkActions.vue')
    ]

    for (const source of sources) {
      expect(source).not.toContain('AutomaticSwitch')
      expect(source).not.toContain('BulkAutomaticSwitch')
      expect(source).not.toContain('updateBulk')
      expect(source).not.toContain('batchAutomatic')
      expect(source).not.toContain('Ligar automático')
    }

    for (const removed of [
      'app/components/monitoring/pgdasd/AutomaticSwitch.vue',
      'app/components/monitoring/pgdasd/BulkAutomaticActions.vue',
      'app/components/monitoring/pgmei/AutomaticSwitch.vue',
      'app/components/monitoring/pgmei/BulkAutomaticActions.vue',
      'app/components/monitoring/PgmeiCommunicationModals.vue'
    ]) {
      expect(existsSync(resolve(process.cwd(), removed)), removed).toBe(false)
    }
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const readWebSource = (path: string) =>
  readFileSync(resolve(process.cwd(), path), 'utf8')

describe('browser console regressions', () => {
  it('configura o Crosshair com o mesmo accessor horizontal da série', () => {
    const chart = readWebSource('app/components/clients/ClientListDashboard.vue')
    const crosshair = chart.match(/<VisCrosshair[\s\S]*?\/>/)?.[0]

    expect(crosshair).toContain(':x="x"')
    expect(crosshair).toContain(':template="template"')
  })

  it('redireciona o deep link anterior de certificado para o cadastro canônico', () => {
    const cadastro = readWebSource('app/pages/clients/[id]/cadastro.vue')
    const parityMatrix = readWebSource('tests/fixtures/template-parity-matrix.md')

    expect(cadastro).toContain(`alias: ['/clients/:id/certificado']`)
    expect(cadastro).toContain(`clientDetailHref(String(to.params.id || ''))`)
    expect(cadastro).toContain('{ replace: true }')
    expect(parityMatrix).toContain('alias anterior `/clients/:id/certificado`')
  })
})

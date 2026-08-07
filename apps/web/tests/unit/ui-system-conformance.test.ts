import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('conformidade do sistema visual', () => {
  it('alinha o canvas escuro do metadado ao PWA', () => {
    const app = source('app/app.vue')

    expect(app).toContain('colorMode.value === \'dark\' ? \'#09090b\' : \'white\'')
    expect(app).toContain('key: \'theme-color\'')
  })

  it('mantém a autenticação tonal, sem efeitos decorativos ou copy interna', () => {
    const auth = source('app/layouts/auth.vue')
    const login = source('app/pages/login.vue')

    expect(auth).toContain('bg-elevated/50')
    expect(auth).toContain('border-r border-default')
    expect(auth).not.toMatch(/gradient|blur|uso interno/i)
    expect(login).not.toMatch(/ambiente interno|uso interno/i)
    expect(login).toContain('Ambiente protegido')
  })

  it('preserva a Home operacional no painel analítico, sem dados sintéticos', () => {
    const home = source('app/pages/index.vue')

    expect(home).toContain('<ShellPagePanel id="home">')
    expect(home).toContain('<UDashboardToolbar')
    expect(home).toContain('HomeOperations')
    expect(home).not.toMatch(/HomeChart|HomeDateRangePicker|HomePeriodSelect|HomeSales/)
    expect(home).toContain('class="mb-2 text-sm font-semibold text-highlighted"')
  })

  it('mantém os rótulos remanescentes em pt-BR', () => {
    const guides = source('app/pages/monitoring/guides.vue')
    const health = source('app/pages/health.vue')
    const syncs = source('app/pages/syncs.vue')

    expect(guides).toContain('Baixar documento protegido')
    expect(health).toContain('Global desativado')
    expect(health).toContain('\'habilitado\' : \'desabilitado\'')
    expect(syncs).toContain('count === 1 ? \'canal\' : \'canais\'')
    expect(syncs).toContain('Em espera (fila vazia)')
    expect(syncs).not.toContain('stream(s)')
    expect(syncs).not.toContain('em quiet')
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('tema do dashboard de referência', () => {
  it('usa green/zinc e a escala verde canônica como tema inicial', () => {
    const config = source('app/app.config.ts')
    const css = source('app/assets/css/main.css')

    expect(config).toContain('primary: \'green\'')
    expect(config).toContain('neutral: \'zinc\'')
    expect(config).not.toContain('primary: \'orange\'')

    expect(css).toContain('--font-sans: \'Public Sans\', sans-serif')
    expect(css).toContain('--color-green-400: #00DC82')
    expect(css).toContain('--color-green-500: #00C16A')
    expect(css).toContain('--color-green-950: #052E16')
    expect(css).not.toContain('--color-orange-500')
  })

  it('mantém somente a alternância de aparência no menu do produto', () => {
    const menu = source('app/components/UserMenu.vue')

    expect(menu).toContain('label: \'Aparência\'')
    expect(menu).not.toContain('label: \'Tema\'')
    expect(menu).not.toContain('Cor primária')
    expect(menu).not.toContain('Cor neutra')
  })

  it('não permite mutação runtime da identidade e preserva o menu do produto', () => {
    const menu = source('app/components/UserMenu.vue')

    expect(menu).not.toContain('useAppConfig')
    expect(menu).not.toContain('appConfig.ui.colors')
    expect(menu).not.toContain('chip-leading')
    expect(menu).toContain('label: \'Aparência\'')
    expect(menu).toContain('label: \'Instalar aplicativo\'')
    expect(menu).toContain('label: \'Sair\'')
    expect(menu).not.toContain('label: \'Templates\'')
  })
})

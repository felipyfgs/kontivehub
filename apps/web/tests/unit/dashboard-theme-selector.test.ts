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

  it('oferece as paletas primária e neutra do seletor de referência', () => {
    const menu = source('app/components/UserMenu.vue')

    expect(menu).toContain('const colors = [\'red\', \'orange\', \'amber\', \'yellow\', \'lime\', \'green\', \'emerald\', \'teal\', \'cyan\', \'sky\', \'blue\', \'indigo\', \'violet\', \'purple\', \'fuchsia\', \'pink\', \'rose\']')
    expect(menu).toContain('const neutrals = [\'slate\', \'gray\', \'zinc\', \'neutral\', \'stone\']')
    expect(menu).toContain('label: \'Tema\'')
    expect(menu).toContain('label: \'Cor primária\'')
    expect(menu).toContain('label: \'Cor neutra\'')
    expect(menu).toContain('checked: appConfig.ui.colors.primary === color')
    expect(menu).toContain('checked: appConfig.ui.colors.neutral === color')
  })

  it('aplica a seleção no app config e preserva o menu do produto', () => {
    const menu = source('app/components/UserMenu.vue')

    expect(menu).toContain('const appConfig = useAppConfig()')
    expect(menu).toContain('appConfig.ui.colors.primary = color')
    expect(menu).toContain('appConfig.ui.colors.neutral = color')
    expect(menu).toContain('<template #chip-leading="{ item }">')
    expect(menu).toContain('var(--color-${(item as any).chip}-500)')
    expect(menu).toContain('label: \'Aparência\'')
    expect(menu).toContain('label: \'Instalar aplicativo\'')
    expect(menu).toContain('label: \'Sair\'')
    expect(menu).not.toContain('label: \'Templates\'')
  })
})

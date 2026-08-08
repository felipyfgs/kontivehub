import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { composerLauncherGroups } from '~/utils/communication-composer-launcher'

const composer = readFileSync(resolve(process.cwd(), 'app/components/communication/Composer.vue'), 'utf8')

describe('communication composer launcher', () => {
  it('mantém quatro grupos estáveis e no máximo quatro ações por camada', () => {
    expect(composerLauncherGroups.map(group => group.label)).toEqual([
      'Arquivos e mídia', 'Cliente e contexto', 'Criar', 'Mais'
    ])
    expect(composerLauncherGroups.every(group => group.actions.length <= 4)).toBe(true)
  })

  it('usa navegação em etapas também no popover desktop', () => {
    expect(composer).toContain('activeLauncherGroup')
    expect(composer).toContain('Voltar aos grupos')
    expect(composer).toContain('@click="() => { launcherGroup = group.id }"')
    expect(composer).not.toContain('v-for="(group, gi) in launcherGroups"')
  })

  it('abre os popovers do composer para cima sem sair do viewport', () => {
    const upwardPopovers = composer.match(/<UPopover[\s\S]{0,180}side: 'top'/g) ?? []

    expect(upwardPopovers).toHaveLength(2)
    expect(composer).toContain('align: \'start\'')
    expect(composer).toContain('collisionPadding: 12')
  })

  it('explica carregamento e indisponibilidade sem renderizar popover vazio', () => {
    expect(composer).toContain('Carregando opções de envio…')
    expect(composer).toContain('Nenhuma opção de envio está disponível')
    expect(composer).toContain('Tentar novamente')
    expect(composer).toContain('loadCapabilities()')
  })

  it('carrega capabilities pela inbox sem depender da hidratação do tenant', () => {
    expect(composer).toContain('const capabilitiesLoading = ref(false)')
    expect(composer).toContain('watch(() => props.inboxId')
    expect(composer).toContain('void loadCapabilities(inboxId ?? null)')
    expect(composer).not.toContain('void loadCapabilities(next)')
  })

  it('centraliza os gatilhos na área de hover e usa linhas touch-safe no menu', () => {
    expect(composer.match(/size-11 justify-center rounded-full p-0/g)?.length ?? 0)
      .toBeGreaterThanOrEqual(4)
    expect(composer).toContain('class="min-h-11 justify-start rounded-lg px-3"')
    expect(composer).toContain('trailing-icon="i-lucide-chevron-right"')
  })
})

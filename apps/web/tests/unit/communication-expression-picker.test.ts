import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  availableComposerExpressionTabs,
  createRecentComposerExpressions,
  insertComposerExpression,
  isPrivateGifAssetPath,
  isPrivateGifResult,
  isPrivateGifPreviewPath,
  resolveComposerExpressionTab,
  searchComposerEmojis
} from '~/utils/communication-expression-picker'

const picker = readFileSync(resolve(process.cwd(), 'app/components/communication/ComposerExpressionPicker.vue'), 'utf8')

describe('communication expression picker', () => {
  it('busca emojis por rótulo e palavra-chave sem depender de serviços externos', () => {
    expect(searchComposerEmojis('coracao').map(emoji => emoji.value)).toContain('😍')
    expect(searchComposerEmojis('aprovar').map(emoji => emoji.value)).toContain('👍')
  })

  it('substitui a seleção ativa e calcula o cursor após um emoji Unicode', () => {
    expect(insertComposerExpression('Olá mundo', '🎉', 4, 9)).toEqual({
      value: 'Olá 🎉', selectionStart: 6, selectionEnd: 6
    })
  })

  it('mantém recentes somente em memória, sem duplicar expressões', () => {
    const recents = createRecentComposerExpressions(2)
    recents.add('😀')
    recents.add('🎉')
    recents.add('😀')
    expect(recents.all()).toEqual(['😀', '🎉'])
    recents.clear()
    expect(recents.all()).toEqual([])
  })

  it('aceita apenas caminhos relativos para previews privados de GIF', () => {
    expect(isPrivateGifPreviewPath('/api/v1/communication/gifs/AbC123dEf456GhI789jKl012MnO345pQr678StUv/preview')).toBe(true)
    expect(isPrivateGifPreviewPath('/api/v1/communication/gifs/result/preview')).toBe(false)
    expect(isPrivateGifPreviewPath('/api/v1/communication/gifs/AbC123dEf456GhI789jKl012MnO345pQr678StUv/download')).toBe(false)
    expect(isPrivateGifPreviewPath('https://provider.example/gif')).toBe(false)
    expect(isPrivateGifPreviewPath('//provider.example/gif')).toBe(false)
  })

  it('exige preview, asset e token privados correspondentes antes de selecionar GIF remoto', () => {
    const token = 'AbC123dEf456GhI789jKl012MnO345pQr678StUv'
    expect(isPrivateGifAssetPath(`/api/v1/communication/gifs/${token}/asset`)).toBe(true)
    expect(isPrivateGifAssetPath(`/api/v1/communication/gifs/${token}/preview`)).toBe(false)
    expect(isPrivateGifAssetPath('https://provider.example/gif.mp4')).toBe(false)
    expect(isPrivateGifResult({
      preview_path: `/api/v1/communication/gifs/${token}/preview`,
      asset_path: `/api/v1/communication/gifs/${token}/asset`,
      asset_token: token
    })).toBe(true)
    expect(isPrivateGifResult({
      preview_path: `/api/v1/communication/gifs/${token}/preview`,
      asset_path: '/api/v1/communication/gifs/ZbC123dEf456GhI789jKl012MnO345pQr678StUv/asset',
      asset_token: token
    })).toBe(false)
  })

  it('sempre resolve uma aba disponível quando emoji está desabilitado', () => {
    const capabilities = { emoji: false, gif: true, sticker: true }
    expect(availableComposerExpressionTabs(capabilities)).toEqual(['GIF', 'STICKER'])
    expect(resolveComposerExpressionTab('EMOJI', capabilities)).toBe('GIF')
    expect(resolveComposerExpressionTab('GIF', { emoji: false, gif: false, sticker: true })).toBe('STICKER')
  })

  it('sincroniza o emoji-mart com o tema claro ou escuro do aplicativo', () => {
    expect(picker).toContain('const colorMode = useColorMode()')
    expect(picker).toContain('theme: emojiTheme.value')
    expect(picker).toContain('pickerInstance?.setAttribute(\'theme\', theme)')
    expect(picker).not.toContain('theme: \'auto\'')
  })

  it('expõe biblioteca de figurinhas com abas Recentes e Favoritas, busca e importação', () => {
    expect(picker).toContain('label="Recentes"')
    expect(picker).toContain('label="Favoritas"')
    expect(picker).toContain('placeholder="Buscar figurinha"')
    expect(picker).toContain('label="Importar para a biblioteca"')
    expect(picker).toContain('label="Usar arquivo local"')
    expect(picker).toContain('stickerViewFilter')
    expect(picker).toContain('stickerPreviewUrls')
    expect(picker).toContain('toggleStickerFavorite')
  })

  it('distingue sincronização parcial de biblioteca vazia e indisponível', () => {
    expect(picker).toContain('Sincronização parcial')
    expect(picker).toContain('Biblioteca indisponível')
    expect(picker).toContain('Nenhuma figurinha recente observada')
    expect(picker).toContain('ainda pode usar um arquivo WebP local')
    expect(picker).toContain('stickerAnnouncement')
    expect(picker).toContain('aria-live="polite"')
  })

  it('mantém favoritos do dispositivo separados dos favoritos do KontiveHub no label', () => {
    expect(picker).toContain('Favorita observada no dispositivo')
    expect(picker).toContain('Importada no KontiveHub')
    expect(picker).toContain('No dispositivo')
    expect(picker).toContain('Remover') // remover dos favoritos do KontiveHub
    expect(picker).toContain('Adicionar')
  })

  it('garante alvos de toque 44px, navegação por teclado e reduced-motion na biblioteca', () => {
    expect(picker).toContain('min-h-11')
    expect(picker).toContain('onGridKeydown')
    expect(picker).toContain('onStickerViewTabKeydown')
    expect(picker).toContain('data-expression-item')
    expect(picker).toContain('data-expression-index')
    expect(picker).toContain('role="grid"')
    expect(picker).toContain('role="gridcell"')
    expect(picker).toContain('motion-reduce:transition-none')
    expect(picker).toContain('motion-reduce:animate-none')
  })

  it('nunca expõe URL de provedor ou caminho privado do WhatsApp', () => {
    expect(picker).not.toContain('provider.example')
    expect(picker).not.toContain('direct_path')
    expect(picker).not.toContain('media_key')
    expect(picker).toContain('isPrivateGifResult')
    expect(picker).toContain('StickerLibraryItem')
  })
})

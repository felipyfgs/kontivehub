import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const picker = readFileSync(
  resolve(process.cwd(), 'app/components/communication/ComposerExpressionPicker.vue'),
  'utf8'
)
const composer = readFileSync(
  resolve(process.cwd(), 'app/components/communication/Composer.vue'),
  'utf8'
)
const draftApi = readFileSync(
  resolve(process.cwd(), 'app/utils/communication-composer-draft-api.ts'),
  'utf8'
)

describe('sticker library composer surface', () => {
  it('expõe recentes, favoritas, parcialidade e favoritos separados do dispositivo', () => {
    expect(picker).toContain('label="Recentes"')
    expect(picker).toContain('label="Favoritas"')
    expect(picker).toContain('Sincronização parcial')
    expect(picker).toContain('Favorita observada no dispositivo')
    expect(picker).toContain('favoritos do KontiveHub')
    expect(picker).toContain('No dispositivo')
  })

  it('mantém upload local independente, importação e estados reais', () => {
    expect(picker).toContain('label="Usar arquivo local"')
    expect(picker).toContain('label="Importar para a biblioteca"')
    expect(picker).toContain('emit(\'selectLocalSticker\', file)')
    expect(picker).toContain('emit(\'importSticker\', file)')
    expect(picker).toContain('stickerView?.status === \'loading\'')
    expect(picker).toContain('stickerView.status === \'error\'')
    expect(picker).toContain('Nenhuma figurinha recente observada')
  })

  it('envia library_sticker_id sem reupload e mantém overlay responsivo acessível', () => {
    expect(composer).toContain('libraryStickerId.value = sticker.id')
    expect(draftApi).toContain('library_sticker_id')
    expect(composer).toContain('<UDrawer')
    expect(composer).toContain('<UPopover')
    expect(composer).toContain('restoreExpressionTriggerFocus')
    expect(picker).toContain('aria-live="polite"')
    expect(picker).toContain('onStickerViewTabKeydown')
    expect(picker).toContain('motion-reduce:transition-none')
    expect(picker).toContain('min-h-11')
  })
})

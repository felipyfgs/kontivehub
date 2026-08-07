import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const component = readFileSync(resolve(process.cwd(), 'app/components/communication/ComposerStickerEditor.vue'), 'utf8')
const composer = readFileSync(resolve(process.cwd(), 'app/components/communication/Composer.vue'), 'utf8')

describe('ComposerStickerEditor', () => {
  it('expõe seleção, recorte, prévia, confirmação transformada e cancelamento com cleanup', () => {
    expect(component).toContain('createCommunicationSticker(source.value')
    expect(component).toContain('crop: crop.value')
    expect(component).toContain('URL.createObjectURL(file)')
    expect(component).toContain('URL.revokeObjectURL(previewUrl.value)')
    expect(component).toContain('emit(\'confirm\', file)')
    expect(component).toContain('emit(\'cancel\')')
    expect(component).toContain('aria-label="Tamanho do recorte da figurinha"')
  })

  it('mantém limites por props e comunica erro acessível', () => {
    expect(component).toContain('maxBytes: props.maxBytes')
    expect(component).toContain('maxDimension: props.maxDimension')
    expect(component).toContain('role="alert"')
  })
})

describe('composer structured and accessibility surfaces', () => {
  it('prévia tipada, thumbnails e anúncios acessíveis estão ligados ao shell', () => {
    expect(composer).toContain('communication-composer-structured-preview')
    expect(composer).toContain('communication-composer-media-preview')
    expect(composer).toContain('confirmStructuredDraft')
    expect(composer).toContain('aria-live="polite"')
    expect(composer).toContain('safe-area-inset-bottom')
    expect(composer).toContain('motion-reduce:')
    expect(composer).toContain('min-h-11')
    expect(composer).toContain('restoreAttachmentTriggerFocus')
  })
})

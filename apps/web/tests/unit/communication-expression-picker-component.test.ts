import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const component = readFileSync(resolve(process.cwd(), 'app/components/communication/ComposerExpressionPicker.vue'), 'utf8')

describe('ComposerExpressionPicker', () => {
  it('expõe contratos explícitos e só delega busca remota à integração Laravel', () => {
    expect(component).toContain('searchGifs?: (query: string)')
    expect(component).toContain('emit(\'selectGif\', gif)')
    expect(component).toContain('emit(\'selectLocalGif\', file)')
    expect(component).toContain('preview_path')
    expect(component).toContain('asset_path')
    expect(component).toContain('asset_token')
    expect(component).toContain('isPrivateGifResult')
    expect(component).not.toMatch(/https?:\/\//)
  })

  it('mantém Escape, foco, grade por teclado e alvos móveis de 44px', () => {
    expect(component).toContain('event.key !== \'Escape\'')
    expect(component).toContain('focusSearch')
    expect(component).toContain('focusSearchInput')
    expect(component).toContain('inputRef?.focus({ preventScroll: true })')
    expect(component).toContain('onGridKeydown')
    expect(component).toContain('min-h-11')
    expect(component).toContain('motion-reduce:transition-none')
  })

  it('usa copy pt-BR e inputs móveis sem zoom automático', () => {
    expect(component).toContain('Não foi possível buscar GIFs agora.')
    expect(component).toContain('Seletor de expressões')
    expect(component).toContain('font-size: 1rem')
  })
})

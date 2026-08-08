import { describe, expect, it, vi } from 'vitest'
import {
  revokeComposerMediaPreviewUrls,
  syncComposerMediaPreviewUrls
} from '~/utils/communication-composer-media-preview'
import type { ComposerMediaItem } from '~/types/communication/composer-draft'

function item(id: string, kind: ComposerMediaItem['kind'] = 'IMAGE'): ComposerMediaItem {
  return {
    clientItemId: id,
    file: new File([id], `${id}.png`, { type: kind === 'DOCUMENT' ? 'application/pdf' : 'image/png' }),
    kind,
    caption: '',
    gif: false,
    ptv: false,
    viewOnce: false
  }
}

describe('communication composer media preview', () => {
  it('cria object URLs só para mídia visual e revoga as removidas', () => {
    const createObjectURL = vi.fn((blob: Blob) => `blob:${(blob as File).name}`)
    const revokeObjectURL = vi.fn()
    const first = syncComposerMediaPreviewUrls(
      [item('one'), item('doc', 'DOCUMENT')],
      new Map(),
      createObjectURL,
      revokeObjectURL
    )

    expect([...first.keys()]).toEqual(['one'])
    expect(createObjectURL).toHaveBeenCalledTimes(1)

    const second = syncComposerMediaPreviewUrls(
      [item('two')],
      first,
      createObjectURL,
      revokeObjectURL
    )
    expect([...second.keys()]).toEqual(['two'])
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:one.png')

    revokeComposerMediaPreviewUrls(second, revokeObjectURL)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:two.png')
  })
})

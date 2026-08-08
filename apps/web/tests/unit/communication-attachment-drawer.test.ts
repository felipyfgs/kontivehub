import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const component = readFileSync(resolve(process.cwd(), 'app/components/communication/ComposerAttachmentDrawer.vue'), 'utf8')
describe('ComposerAttachmentDrawer', () => {
  it('mantém etapas, voltar, safe-area e alvos móveis', () => {
    expect(component).toContain('<UDrawer')
    expect(component).toContain('direction="bottom"')
    expect(component).toContain('selectedGroup')
    expect(component).toContain('Voltar')
    expect(component).toContain('safe-area-inset-bottom')
    expect(component).toContain('min-h-11')
  })
})

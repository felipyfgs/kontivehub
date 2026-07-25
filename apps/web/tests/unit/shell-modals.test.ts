import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const shell = (name: string) =>
  resolve(process.cwd(), `app/components/shell/${name}.vue`)

describe('shell-modals kit', () => {
  it('cascas Shell* de modal existem', () => {
    for (const name of [
      'ModalFooter',
      'FormModal',
      'ConfirmModal',
      'ScrollableModal',
      'LoadingModalBody'
    ]) {
      expect(existsSync(shell(name)), name).toBe(true)
    }
  })

  it('FormModal usa ModalFooter e v-model open', () => {
    const source = readFileSync(shell('FormModal'), 'utf8')
    expect(source).toContain('ShellModalFooter')
    expect(source).toContain('defineModel<boolean>(\'open\'')
    expect(source).toContain('Cancelar')
  })

  it('ConfirmModal mapeia tone danger → error', () => {
    const source = readFileSync(shell('ConfirmModal'), 'utf8')
    expect(source).toContain('tone === \'danger\' ? \'error\'')
    expect(source).toContain('ShellModalFooter')
  })

  it('ScrollableModal define max-h e body overflow', () => {
    const source = readFileSync(shell('ScrollableModal'), 'utf8')
    expect(source).toContain('max-h-[min(90dvh')
    expect(source).toContain('overflow-y-auto')
  })
})

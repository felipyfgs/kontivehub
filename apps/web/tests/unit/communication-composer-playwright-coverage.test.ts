import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const spec = readFileSync(
  resolve(process.cwd(), 'tests/e2e/specs/communication-composer-experience.spec.ts'),
  'utf8'
)

describe('communication composer playwright coverage', () => {
  it('cobre launcher, contexto, formulários, mídia, foco, safe-area e zoom', () => {
    expect(spec).toContain('name: \'desktop\'')
    expect(spec).toContain('name: \'mobile\'')
    expect(spec).toContain('communication-composer-attachment-trigger')
    expect(spec).toContain('communication-composer-context')
    expect(spec).toContain('communication-composer-structured-preview')
    expect(spec).toContain('communication-composer-media-preview')
    expect(spec).toContain('Arquivos e mídia')
    expect(spec).toContain('Enquete')
    expect(spec).toContain('Fotos e vídeos')
    expect(spec).toContain('press(\'Escape\')')
    expect(spec).toContain('reducedMotion: \'reduce\'')
    expect(spec).toContain('style.zoom = \'2\'')
    expect(spec).toContain('paddingBottom')
    expect(spec).toContain('outbound-capabilities')
    expect(spec).toContain('setInputFiles')
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { dctfwebDeclarationMeta, dctfwebDeclarationState } from '~/utils/dctfweb'

describe('dctfweb / sitfis utils smoke', () => {
  it('maps dctfweb declaration states', () => {
    expect(dctfwebDeclarationState('CURRENT')).toBe('CURRENT')
    expect(dctfwebDeclarationState('weird')).toBe('UNVERIFIED')
    expect(dctfwebDeclarationMeta('CURRENT').color).toBe('success')
  })

  it('sitfis-table exports age/detail helpers', () => {
    const source = readFileSync(
      resolve(__dirname, '../../app/utils/sitfis-table.ts'),
      'utf8'
    )
    expect(source).toContain('export function sitfisAgeLabel')
    expect(source).toContain('export function sitfisDetailOf')
  })

  it('download SITFIS do menu usa callback autenticado, não navegação Nuxt', () => {
    const table = readFileSync(
      resolve(__dirname, '../../app/utils/sitfis-table.ts'),
      'utf8'
    )
    const page = readFileSync(
      resolve(__dirname, '../../app/pages/monitoring/sitfis.vue'),
      'utf8'
    )

    expect(table).toContain('onSelect: () => options.onDocument(row)')
    expect(table).not.toContain('to: href')
    expect(page).toContain('onDocument: (row) => { void downloadRowDocument(row) }')
    expect(page).toContain('await downloadAuthenticated(href, fiscalDocumentDownloadFilename({')
  })
})

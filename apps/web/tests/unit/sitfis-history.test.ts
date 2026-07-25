import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { SitfisHistorySearch } from '~/types/fiscal-modules'
import {
  sitfisHistoryDownloadPath,
  sitfisHistoryFilename
} from '~/utils/sitfis-history'

function search(overrides: Partial<SitfisHistorySearch> = {}): SitfisHistorySearch {
  return {
    id: 1,
    observed_at: '2026-07-15T12:00:00+00:00',
    situation: 'PENDING',
    version: 1,
    is_current: true,
    evidence_artifact_id: null,
    links: { evidence_download: null },
    ...overrides
  }
}

describe('sitfis history', () => {
  it('prioriza o link autenticado e deriva fallback apenas com artefato', () => {
    expect(sitfisHistoryDownloadPath(search({
      evidence_artifact_id: 9,
      links: { evidence_download: '/api/v1/fiscal/evidence/9/download' }
    }))).toBe('/api/v1/fiscal/evidence/9/download')

    expect(sitfisHistoryDownloadPath(search({
      evidence_artifact_id: 10,
      links: null
    }))).toBe('/api/v1/fiscal/evidence/10/download')

    expect(sitfisHistoryDownloadPath(search())).toBeNull()
  })

  it('gera nome auditável pela data da busca', () => {
    expect(sitfisHistoryFilename(search())).toBe('relatorio-sitfis-2026-07-15.pdf')
    expect(sitfisHistoryFilename(search({ observed_at: null })))
      .toBe('relatorio-sitfis-historico.pdf')
  })

  it('incorpora o histórico na página sem modal ou refresh implícito', () => {
    const component = readFileSync(
      resolve(__dirname, '../../app/components/monitoring/SitfisHistoryView.vue'),
      'utf8'
    )
    const clientPage = readFileSync(
      resolve(__dirname, '../../app/pages/monitoring/clients/[clientId].vue'),
      'utf8'
    )

    expect(component).toContain('data-testid="sitfis-history-view"')
    expect(component).toContain('Arquivo indisponível')
    expect(component).toContain('await download(path, sitfisHistoryFilename(search))')
    expect(component).not.toContain('ShellScrollableModal')
    expect(component).not.toContain('.refresh(')
    expect(clientPage).toContain('<MonitoringSitfisHistoryView')
    expect(clientPage).toContain('label="Abrir carteira SITFIS"')
  })

  it('menu da carteira navega ao histórico da empresa', () => {
    const table = readFileSync(
      resolve(__dirname, '../../app/utils/sitfis-table.ts'),
      'utf8'
    )

    expect(table).toContain('label: \'Histórico de busca\'')
    expect(table).toContain('to: `/monitoring/clients/${row.client_id}/sitfis`')
    expect(table).not.toContain('onHistory')
  })
})

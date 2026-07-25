import { describe, expect, it } from 'vitest'
import {
  fiscalDocumentDownloadFilename,
  looksLikeJsonErrorBlobText,
  toSanctumApiPath
} from '../../app/utils/authenticated-download'

describe('authenticated-download', () => {
  it('remove o prefixo /api/sanctum do proxy de dev', () => {
    expect(
      toSanctumApiPath(
        '/api/sanctum/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download',
        '/api/sanctum'
      )
    ).toBe('/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download')
  })

  it('mantém path canônico quando apiBase está vazio', () => {
    expect(
      toSanctumApiPath('/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download', '')
    ).toBe('/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download')
  })

  it('extrai pathname de URL absoluta same-origin', () => {
    expect(
      toSanctumApiPath(
        'http://localhost:3000/api/sanctum/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download',
        '/api/sanctum'
      )
    ).toBe('/api/v1/fiscal/simples-mei/pgdasd/artifacts/2/download')
  })

  it('detecta JSON de erro em blob', () => {
    expect(looksLikeJsonErrorBlobText('{"message":"Unauthenticated."}')).toBe(true)
    expect(looksLikeJsonErrorBlobText('%PDF-1.7')).toBe(false)
  })

  it('deriva filename do label do descriptor', () => {
    expect(fiscalDocumentDownloadFilename({ label: 'Baixar DAS', kind: 'PDF' }))
      .toBe('baixar-das.pdf')
  })

  it('usa kind quando label está vazio', () => {
    expect(fiscalDocumentDownloadFilename({ label: '  ', kind: 'DECLARACAO' }))
      .toBe('declaracao.pdf')
  })

  it('fallback genérico sem label/kind', () => {
    expect(fiscalDocumentDownloadFilename()).toBe('documento.pdf')
  })
})

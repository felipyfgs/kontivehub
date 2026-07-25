import { describe, expect, it } from 'vitest'
import type { PgdasdHistoryPeriod } from '~/types/fiscal-modules'
import { buildPgdasdHistoryOperationRows } from '~/utils/pgdasd-history'

describe('buildPgdasdHistoryOperationRows', () => {
  it('monta uma linha por operação com os rótulos do histórico oficial', () => {
    const result = buildPgdasdHistoryOperationRows({
      period_key: '2026-06',
      declarations: [
        { id: 2, normalized_operation_type: 'RECTIFIER', declaration_number: 'DEC-2', transmitted_at: '2026-07-14T14:04:00Z' },
        { id: 1, normalized_operation_type: 'ORIGINAL', declaration_number: 'DEC-1', transmitted_at: '2026-07-10T14:00:00Z' }
      ],
      das: [
        { id: 3, normalized_operation_type: 'DAS_GENERATION', das_number: 'DAS-1' }
      ]
    })

    expect(result.rows.map(row => row.operationLabel)).toEqual([
      'Declaração Original',
      'Declaração Retificadora',
      'Geração de DAS'
    ])
    expect(result.rows.map(row => row.kind)).toEqual(['declaration', 'declaration', 'das'])
  })

  it('associa documentos somente à declaração ou ao DAS com número correspondente', () => {
    const period: PgdasdHistoryPeriod = {
      period_key: '2026-06',
      declarations: [
        { id: 1, declaration_number: 'DEC-1' },
        { id: 2, declaration_number: 'DEC-2' }
      ],
      das: [
        { id: 3, das_number: 'DAS-1' },
        { id: 4, das_number: 'DAS-2' }
      ],
      artifacts: [
        { id: 10, kind: 'RECIBO', declaration_number: 'DEC-1' },
        { id: 11, kind: 'DECLARACAO', declaration_number: 'DEC-2' },
        { id: 12, kind: 'NOTIFICACAO_MAED', declaration_number: 'DEC-2' },
        { id: 13, kind: 'EXTRATO', das_number: 'DAS-1' },
        { id: 14, kind: 'DAS', das_number: 'DAS-2' }
      ]
    }

    const result = buildPgdasdHistoryOperationRows(period)
    expect(result.rows[0]?.documents.receipt.map(item => item.id)).toEqual([10])
    expect(result.rows[0]?.documents.declaration).toEqual([])
    expect(result.rows[1]?.documents.declaration.map(item => item.id)).toEqual([11])
    expect(result.rows[1]?.documents.maed.map(item => item.id)).toEqual([12])
    expect(result.rows[2]?.documents.extract.map(item => item.id)).toEqual([13])
    expect(result.rows[3]?.documents.das.map(item => item.id)).toEqual([14])
    expect(result.otherDocuments).toEqual([])
  })

  it('mantém em outros documentos artefatos sem vínculo, sem match ou com kind incompatível', () => {
    const result = buildPgdasdHistoryOperationRows({
      period_key: '2026-06',
      declarations: [{ id: 1, declaration_number: 'DEC-1' }],
      das: [{ id: 2, das_number: 'DAS-1' }],
      artifacts: [
        { id: 20, kind: 'RECIBO' },
        { id: 21, kind: 'RECIBO', declaration_number: 'DEC-INEXISTENTE' },
        { id: 22, kind: 'EXTRATO', das_number: 'DAS-INEXISTENTE' },
        { id: 23, kind: 'OUTRO', declaration_number: 'DEC-1' }
      ]
    })

    expect(result.rows.every(row => Object.values(row.documents).every(items => items.length === 0))).toBe(true)
    expect(result.otherDocuments.map(item => item.id)).toEqual([20, 21, 22, 23])
  })

  it('remove duplicatas quando o mesmo artefato aparece no período e na operação', () => {
    const receipt = { id: 30, kind: 'RECIBO', declaration_number: 'DEC-1' }
    const result = buildPgdasdHistoryOperationRows({
      declarations: [{ id: 1, declaration_number: 'DEC-1', documents: [receipt] }],
      artifacts: [receipt]
    })

    expect(result.rows[0]?.documents.receipt).toHaveLength(1)
    expect(result.otherDocuments).toEqual([])
  })
})

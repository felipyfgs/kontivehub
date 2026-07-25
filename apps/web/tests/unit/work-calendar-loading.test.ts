import { describe, expect, it } from 'vitest'
import {
  workCalendarLoadPlan,
  workCalendarSnapshotForKey
} from '../../app/utils/work-calendar-loading'

describe('work calendar loading', () => {
  it('recarrega somente o dia ao trocar a data dentro do mesmo range', () => {
    expect(workCalendarLoadPlan(
      { interval: '2026-07-01:2026-07-31', day: '2026-07-10' },
      { interval: '2026-07-01:2026-07-31', day: '2026-07-11' }
    )).toEqual({ interval: false, day: true })
  })

  it('agenda uma única carga de cada recurso quando data e range mudam juntos', () => {
    expect(workCalendarLoadPlan(
      { interval: '2026-07-01:2026-07-31', day: '2026-07-31' },
      { interval: '2026-08-01:2026-08-31', day: '2026-08-01' }
    )).toEqual({ interval: true, day: true })
  })

  it('reutiliza snapshot somente para a mesma chave de intervalo e filtros', () => {
    const snapshot = {
      key: JSON.stringify({
        from: '2026-07-01',
        to: '2026-07-31',
        filters: { status: 'PENDENTE' }
      }),
      data: [{ date: '2026-07-10', total: 2 }]
    }

    expect(workCalendarSnapshotForKey(snapshot.key, snapshot)).toEqual(snapshot.data)
    expect(workCalendarSnapshotForKey(JSON.stringify({
      from: '2026-08-01',
      to: '2026-08-31',
      filters: { status: 'PENDENTE' }
    }), snapshot)).toBeNull()
    expect(workCalendarSnapshotForKey(JSON.stringify({
      from: '2026-07-01',
      to: '2026-07-31',
      filters: { status: 'CONCLUIDA' }
    }), snapshot)).toBeNull()
  })
})

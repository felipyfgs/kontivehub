import { describe, expect, it } from 'vitest'
import type { WorkTaskSummary, TaskStatus } from '../../app/types/work'
import {
  WORK_KANBAN_COLUMNS,
  actionForKanbanDrop,
  canDropOnKanbanColumn,
  completeKanbanDnDDrop,
  groupTasksForKanbanBoard,
  isKanbanBoardTruncated,
  isWorkKanbanColumnStatus,
  kanbanTruncationMessage
} from '../../app/utils/work-kanban-transition'

function task(partial: Partial<WorkTaskSummary> & { id: number, status: TaskStatus }): WorkTaskSummary {
  return {
    title: `Tarefa ${partial.id}`,
    is_critical: false,
    is_required: true,
    requires_evidence: false,
    lock_version: 1,
    ...partial
  }
}

describe('work-kanban-transition', () => {
  it('define exatamente quatro colunas sem DISPENSADA', () => {
    expect([...WORK_KANBAN_COLUMNS]).toEqual([
      'A_FAZER',
      'EM_PROGRESSO',
      'IMPEDIDA',
      'CONCLUIDA'
    ])
    expect(isWorkKanbanColumnStatus('DISPENSADA')).toBe(false)
  })

  it('mapeia drops válidos para ações HTTP', () => {
    expect(actionForKanbanDrop('A_FAZER', 'EM_PROGRESSO')).toEqual({
      kind: 'action',
      action: 'start',
      requiresReason: false
    })
    expect(actionForKanbanDrop('IMPEDIDA', 'EM_PROGRESSO')).toEqual({
      kind: 'action',
      action: 'resume',
      requiresReason: false
    })
    expect(actionForKanbanDrop('A_FAZER', 'IMPEDIDA')).toEqual({
      kind: 'action',
      action: 'block',
      requiresReason: true
    })
    expect(actionForKanbanDrop('EM_PROGRESSO', 'IMPEDIDA')).toEqual({
      kind: 'action',
      action: 'block',
      requiresReason: true
    })
    expect(actionForKanbanDrop('A_FAZER', 'CONCLUIDA')).toEqual({
      kind: 'action',
      action: 'complete',
      requiresReason: false
    })
    expect(actionForKanbanDrop('EM_PROGRESSO', 'CONCLUIDA')).toEqual({
      kind: 'action',
      action: 'complete',
      requiresReason: false
    })
    expect(actionForKanbanDrop('IMPEDIDA', 'CONCLUIDA')).toEqual({
      kind: 'action',
      action: 'complete',
      requiresReason: false
    })
    expect(actionForKanbanDrop('CONCLUIDA', 'A_FAZER')).toEqual({
      kind: 'action',
      action: 'reopen',
      requiresReason: true
    })
  })

  it('trata mesma coluna como noop sem persistência', () => {
    expect(actionForKanbanDrop('A_FAZER', 'A_FAZER')).toEqual({ kind: 'noop' })
    expect(canDropOnKanbanColumn('EM_PROGRESSO', 'EM_PROGRESSO')).toBe(false)
  })

  it('rejeita drops inválidos com mensagem pt_BR', () => {
    const invalid = actionForKanbanDrop('EM_PROGRESSO', 'A_FAZER')
    expect(invalid.kind).toBe('invalid')
    if (invalid.kind === 'invalid') {
      expect(invalid.message).toMatch(/não é permitida/i)
    }
    expect(canDropOnKanbanColumn('CONCLUIDA', 'EM_PROGRESSO')).toBe(false)
    expect(canDropOnKanbanColumn('CONCLUIDA', 'IMPEDIDA')).toBe(false)
  })

  it('agrupa cards e exclui DISPENSADA do board', () => {
    const grouped = groupTasksForKanbanBoard([
      task({ id: 1, status: 'A_FAZER' }),
      task({ id: 2, status: 'EM_PROGRESSO' }),
      task({ id: 3, status: 'IMPEDIDA' }),
      task({ id: 4, status: 'CONCLUIDA' }),
      task({ id: 5, status: 'DISPENSADA' })
    ])

    expect(grouped.A_FAZER.map(t => t.id)).toEqual([1])
    expect(grouped.EM_PROGRESSO.map(t => t.id)).toEqual([2])
    expect(grouped.IMPEDIDA.map(t => t.id)).toEqual([3])
    expect(grouped.CONCLUIDA.map(t => t.id)).toEqual([4])
    expect(
      Object.values(grouped).flat().some(t => t.status === 'DISPENSADA')
    ).toBe(false)
  })

  it('sinaliza truncagem quando total > carregados', () => {
    expect(isKanbanBoardTruncated(150, 100)).toBe(true)
    expect(isKanbanBoardTruncated(50, 50)).toBe(false)
    expect(isKanbanBoardTruncated(10, 20)).toBe(false)
    expect(kanbanTruncationMessage(150, 100)).toContain('150')
    expect(kanbanTruncationMessage(150, 100)).toContain('100')
    expect(kanbanTruncationMessage(150, 100)).toContain('filtros')
    expect(kanbanTruncationMessage(150, 100)).not.toContain('paginação')
  })

  it('encerra sessão DnD com true (false no onDrop deixa o drag preso no vue-dnd-kit)', () => {
    expect(completeKanbanDnDDrop()).toBe(true)
  })
})

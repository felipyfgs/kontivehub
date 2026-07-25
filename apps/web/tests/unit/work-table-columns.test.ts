import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { WORK_TABLE_COL } from '../../app/utils/work-table-columns'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('work-table-columns', () => {
  it('expõe tokens estáveis de largura alinhados entre superfícies', () => {
    expect(WORK_TABLE_COL.primary.td).toContain('w-full max-w-0')
    expect(WORK_TABLE_COL.status.td).toBe('w-36 min-w-32')
    expect(WORK_TABLE_COL.due.td).toBe('w-28 min-w-24')
    expect(WORK_TABLE_COL.assignee.td).toBe('w-36 min-w-28')
  })

  it('coleções pai de Processos e Tarefas consomem o mesmo contrato', () => {
    const processes = source('app/pages/work/processes/index.vue')
    const tasks = source('app/components/work/WorkQueueWorkspace.vue')

    expect(processes).toContain('WORK_TABLE_COL')
    expect(processes).not.toContain('WORK_GROUP_CHILD_ROW_GRID')
    expect(processes).not.toContain('WORK_GROUP_TASK_ROW_CLASS')
    expect(tasks).toContain('WORK_TABLE_COL')
    expect(tasks).toContain('WORK_TABLE_COL.primary')
    expect(tasks).toContain('WORK_TABLE_COL.status')
    expect(tasks).toContain('WORK_TABLE_COL.due')
  })

  it('Lista de Tarefas declara metadados canônicos dos cards mobile', () => {
    const tasks = source('app/components/work/WorkQueueWorkspace.vue')

    expect(tasks).toContain('mobile-cards-test-id="work-queue-mobile-cards"')
    expect(tasks).toContain(':get-row-id="task => String(task.id)"')
    expect(tasks).toContain('title: \'Tarefa\'')
    expect(tasks).toContain('effective_due_date: \'Prazo\'')
    expect(tasks).toContain('client_name: \'Cliente / Processo\'')
    expect(tasks).toContain('assignee_name: \'Responsável\'')
  })
})

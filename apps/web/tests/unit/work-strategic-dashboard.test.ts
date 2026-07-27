import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { WorkDepartment, WorkKpis } from '../../app/types/work'
import { workQueuePath } from '../../app/composables/useWorkQueueFilters'
import {
  buildWorkDashboardKpis,
  buildWorkDepartmentRows,
  workCompletionPercent,
  workOperationalLevel
} from '../../app/utils/work-strategic-dashboard'

function fixture(): WorkKpis {
  return {
    generated_at: '2026-07-22T12:00:00-03:00',
    tenant_timezone: 'America/Sao_Paulo',
    today: '2026-07-22',
    kpis: {
      total_open: 30,
      atrasadas: 7,
      em_multa: 2,
      vence_hoje: 4,
      em_progresso: 11,
      concluidas: 10,
      sem_responsavel: 5
    },
    by_department: [
      {
        work_department_id: 20,
        open: 8,
        completed: 2,
        overdue: 3,
        fine: 1,
        unassigned: 1,
        total_relevant: 10,
        completed_percent: 20,
        total: 8
      },
      {
        work_department_id: null,
        open: 12,
        completed: 3,
        overdue: 2,
        fine: 0,
        unassigned: 4,
        total_relevant: 15,
        completed_percent: 20,
        total: 12
      }
    ],
    by_assignee: [],
    top_risks: [],
    processes_without_owner: []
  }
}

const departments: WorkDepartment[] = [{
  id: 20,
  name: 'Fiscal',
  code: 'FISCAL',
  is_active: true
}]

describe('work strategic dashboard', () => {
  it('mapeia os seis indicadores para a fila canônica e preserva tons de risco', () => {
    const cards = buildWorkDashboardKpis(fixture())

    expect(cards).toHaveLength(6)
    expect(cards.map(card => card.value)).toEqual([30, 7, 2, 4, 11, 5])
    expect(cards[0]).toMatchObject({ title: 'Tarefas abertas', to: '/work/tasks' })
    expect(cards[1]).toMatchObject({ to: '/work/tasks?tab=atrasadas', tone: 'warning', critical: true })
    expect(cards[2]).toMatchObject({ tone: 'error', critical: true })
    expect(cards[3]).toMatchObject({ to: '/work/tasks?tab=hoje' })
  })

  it('calcula conclusão consolidada sem dividir por zero', () => {
    expect(workCompletionPercent(fixture())).toBe(25)

    const empty = fixture()
    empty.kpis.total_open = 0
    empty.kpis.concluidas = 0
    expect(workCompletionPercent(empty)).toBe(0)
  })

  it('deriva o nível operacional e a distância para a próxima faixa sem inventar histórico', () => {
    expect(workOperationalLevel(fixture())).toMatchObject({
      percent: 25,
      label: 'Ganhando ritmo',
      nextLabel: 'Ritmo consistente',
      remainingToNext: 10,
      tone: 'error'
    })

    const highPerformance = fixture()
    highPerformance.kpis.total_open = 2
    highPerformance.kpis.concluidas = 38
    highPerformance.kpis.atrasadas = 0
    highPerformance.kpis.em_multa = 0

    expect(workOperationalLevel(highPerformance)).toMatchObject({
      percent: 95,
      label: 'Alta performance',
      nextLabel: null,
      remainingToNext: 0,
      tone: 'success'
    })

    const empty = fixture()
    empty.kpis.total_open = 0
    empty.kpis.concluidas = 0
    empty.kpis.atrasadas = 0
    empty.kpis.em_multa = 0

    expect(workOperationalLevel(empty)).toMatchObject({
      percent: 0,
      label: 'Sem carga',
      nextLabel: null,
      remainingToNext: 0,
      tone: 'info'
    })
  })

  it('resolve departamentos, links e ordenação por carga aberta', () => {
    const rows = buildWorkDepartmentRows(fixture(), departments)

    expect(rows.map(row => row.name)).toEqual(['Sem departamento', 'Fiscal'])
    expect(rows[0]).toMatchObject({
      open: 12,
      to: '/work/tasks',
      overdueTo: '/work/tasks?tab=atrasadas'
    })
    expect(rows[1]).toMatchObject({
      completedPercent: 20,
      to: '/work/tasks?department_id=20',
      overdueTo: '/work/tasks?tab=atrasadas&department_id=20'
    })
  })

  it('expõe /work/tasks como path-base da fila', () => {
    expect(workQueuePath()).toBe('/work/tasks')
  })

  it('compõe /work como ledger operacional denso e sem decoração circular', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/work/index.vue'), 'utf8')

    for (const token of [
      'api.work.kpis()',
      'Promise.allSettled',
      'lastGood',
      'sessionEpoch',
      'ShellPagePanel',
      'ShellKpiStrip',
      'workOperationalLevel',
      'work-dashboard-performance',
      'work-dashboard-completion',
      'work-dashboard-level',
      'work-dashboard-departments',
      'work-dashboard-departments-table',
      'work-dashboard-departments-mobile',
      '<table',
      'divide-y divide-default',
      'md:hidden',
      'work-dashboard-risks',
      'work-dashboard-unassigned-processes'
    ]) {
      expect(page).toContain(token)
    }

    expect(page.match(/<ShellKpiStrip/g)).toHaveLength(1)
    expect(page).not.toContain('Operação do escritório')
    expect(page).not.toContain('conic-gradient')
    expect(page).not.toContain('<svg')
    expect(page).not.toContain('ring ring-default')
    expect(page).not.toContain('<ShellSectionCard')
    expect(page).not.toContain('<UCard')
    expect(page).not.toContain('<UPageCard')
    expect(page).not.toMatch(/(?:text|bg|border)-(?:gray|slate|zinc|red|orange|green)-/)
    expect(page).not.toContain('overflow-x-auto')
    expect(page).not.toMatch(/min-w-\[[^\]]+\]/)
  })

  it('preserva loading, erro sem snapshot e stale com última leitura válida', () => {
    const page = readFileSync(resolve(process.cwd(), 'app/pages/work/index.vue'), 'utf8')

    for (const token of [
      'const lastGood = ref<WorkKpis | null>(null)',
      'const hadSnapshot = lastGood.value !== null',
      'if (lastGood.value)',
      'data.value = lastGood.value',
      'stale.value = true',
      'loading && !data',
      'error && !data',
      'stale && error',
      'work-dashboard-loading',
      'role="status"',
      'aria-busy="true"',
      'work-dashboard-error',
      'work-dashboard-stale',
      'Tentar novamente'
    ]) {
      expect(page).toContain(token)
    }

    expect(page.indexOf('stale && error')).toBeLessThan(page.indexOf('loading && !data'))
    expect(page.indexOf('loading && !data')).toBeLessThan(page.indexOf('error && !data'))
  })

  it('separa visão geral e fila na navegação e atualiza o cockpit Início', () => {
    const navigation = readFileSync(resolve(process.cwd(), 'app/utils/work-navigation.ts'), 'utf8')
    const taskIndex = readFileSync(resolve(process.cwd(), 'app/pages/work/tasks/index.vue'), 'utf8')
    const homeBlock = readFileSync(resolve(process.cwd(), 'app/components/home/WorkKpisBlock.vue'), 'utf8')

    expect(navigation).toContain('id: \'work-overview\'')
    expect(navigation).toContain('label: \'Visão geral\'')
    expect(navigation).toContain('to: \'/work/tasks\'')
    expect(taskIndex).toContain('WorkQueueWorkspace')
    expect(homeBlock).toContain('label="Visão estratégica"')
    expect(homeBlock).toContain('/work/tasks?tab=atrasadas')
    expect(homeBlock).not.toContain('/work?tab=')
  })
})

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')
const calendar = source('app/pages/work/calendar.vue')

describe('work calendar composition', () => {
  it('preserva a exceção mestre–detalhe com um único chrome e collapse', () => {
    expect(calendar).toContain('id="work-calendar-main"')
    expect(calendar).toContain('id="work-calendar-rail"')
    expect(calendar).toContain('USlideover')
    expect(calendar).not.toContain('ShellDataTable')
    expect(calendar.match(/<UDashboardToolbar/g)).toHaveLength(1)
    expect(calendar.match(/<UDashboardSidebarCollapse/g)).toHaveLength(1)
  })

  it('usa listas densas com divisores, sem card ou borda arredondada por tarefa', () => {
    const month = calendar.split('data-testid="work-calendar-month"')[1]?.split('data-testid="work-calendar-week"')[0] ?? ''
    const week = calendar.split('data-testid="work-calendar-week"')[1]?.split('data-testid="work-calendar-day"')[0] ?? ''
    const day = calendar.split('data-testid="work-calendar-day"')[1]?.split('</UDashboardPanel>')[0] ?? ''
    const rail = calendar.split('id="work-calendar-rail"')[1] ?? ''

    expect(month).toContain('border-s border-t border-default')
    expect(month).not.toContain('rounded')
    expect(week).toContain('data-testid="work-calendar-week-task-list"')
    expect(day).toContain('data-testid="work-calendar-day-task-list"')
    expect(rail).toContain('data-testid="work-calendar-rail-task-list"')
    expect(rail).toContain('data-testid="work-calendar-mobile-task-list"')
    expect(week).toContain('divide-y divide-default')
    expect(day).toContain('divide-y divide-default border-y border-default')
    expect(rail).toContain('divide-y divide-default border-y border-default')
    expect(calendar).not.toContain('<UCard')
    expect(week).not.toMatch(/v-for="item in lane\.items"[\s\S]{0,220}rounded/)
    expect(rail).not.toMatch(/v-for="item in railItems"[\s\S]{0,220}rounded/)
  })

  it('entrega semana estreita responsiva sem largura mínima ou scroll horizontal artificial', () => {
    const week = calendar.split('data-testid="work-calendar-week"')[1]?.split('data-testid="work-calendar-day"')[0] ?? ''

    expect(week).toContain('grid-cols-1')
    expect(week).toContain('sm:grid-cols-2')
    expect(week).toContain('md:grid-cols-4')
    expect(week).toContain('2xl:grid-cols-7')
    expect(calendar).toContain('overflow-x-clip')
    expect(calendar).not.toContain('overflow-x-auto')
    expect(calendar).not.toMatch(/min-w-\[[^\]]+\]/)
  })

  it('mantém intervalo e dia independentes, incluindo última carga válida e retry contextual', () => {
    expect(calendar).toContain('const loading = ref(false)')
    expect(calendar).toContain('const dayLoading = ref(false)')
    expect(calendar).toContain('const loadError = ref<string | null>(null)')
    expect(calendar).toContain('const dayError = ref<string | null>(null)')
    expect(calendar).toContain('lastGoodInterval')
    expect(calendar).toContain('usingStaleInterval')
    expect(calendar).toContain('workCalendarSnapshotForKey(requestedKey, lastGoodInterval.value)')
    expect(calendar).toContain('requestedKey !== calendarLoadKeys.value.interval')
    expect(calendar).toContain('work-calendar-interval-stale')
    expect(calendar).toContain('work-calendar-day-error')
    expect(calendar).toContain('work-calendar-rail-day-error')
    expect(calendar).toContain('work-calendar-mobile-day-error')
    expect(calendar).toContain('@click="loadInterval"')
    expect(calendar).toContain('@click="loadDay"')
    expect(calendar).toContain('workCalendarLoadPlan(previous, next)')
  })

  it('preserva URL, filtros e navegação canônica para tarefas', () => {
    const range = source('app/composables/useWorkCalendarRange.ts')

    expect(range).toContain('const v = String(route.query.view || \'month\')')
    expect(range).toContain('const raw = String(route.query.date || \'\')')
    expect(range).toContain('...route.query')
    expect(calendar).toContain('department_id')
    expect(calendar).toContain('assignee_membership_id')
    expect(calendar).toContain('client_id')
    expect(calendar).toContain('status')
    expect(calendar).toContain('risk')
    expect(calendar).toContain('...filterParams.value')
    expect(calendar).toContain('navigateTo(`/work/tasks/${item.id}`)')
  })

  it('mantém controles e itens operáveis por teclado com nomes acessíveis', () => {
    const day = calendar.split('data-testid="work-calendar-day-task-list"')[1]?.split('</UDashboardPanel>')[0] ?? ''
    const desktopRail = calendar.split('data-testid="work-calendar-rail-task-list"')[1]?.split('</UDashboardPanel>')[0] ?? ''
    const mobileRail = calendar.split('data-testid="work-calendar-mobile-task-list"')[1] ?? ''

    expect(calendar).toContain('aria-label="Período anterior"')
    expect(calendar).toContain('aria-label="Próximo período"')
    expect(calendar).toContain('aria-label="Visualização do calendário"')
    expect(calendar).toContain('aria-label="Abrir painel do dia"')
    expect(calendar).toContain(':aria-label="`Abrir agenda de ${formatDueDate(lane.date)}`"')
    expect(day).not.toContain(':aria-label="`Abrir tarefa ${item.title}`"')
    expect(desktopRail).not.toContain(':aria-label="`Abrir tarefa ${item.title}`"')
    expect(mobileRail).not.toContain(':aria-label="`Abrir tarefa ${item.title}`"')
    expect(calendar).toContain('const taskContext = (item: OperationalTaskSummary)')
    expect(calendar).toContain('<p v-if="taskContext(item)"')
    expect(calendar).toContain('].filter(Boolean).join(\' · \')')
    expect(desktopRail).toContain('title="Nenhuma tarefa nesta lista"')
    expect(calendar).not.toMatch(/<li[^>]*@click=/)
  })
})

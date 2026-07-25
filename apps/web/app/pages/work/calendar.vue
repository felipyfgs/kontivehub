<script setup lang="ts">
/**
 * Calendário operacional — Mês / Semana / Dia.
 * Shell Home (navbar + toolbar); UCalendar só como minicalendário.
 * Sem grade horária nem compromissos fictícios.
 */
import { CalendarDate, type DateValue } from '@internationalized/date'
import { breakpointsTailwind } from '@vueuse/core'
import type { OperationalTaskSummary } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import {
  formatDueDate,
  highestRiskColor,
  taskStatusLabel,
  workRiskLabel
} from '~/utils/work-labels'
import ShellScrollableTabs from '~/components/shell/ScrollableTabs.vue'
import { useWorkCalendarRange } from '~/composables/useWorkCalendarRange'
import {
  workCalendarLoadPlan,
  workCalendarSnapshotForKey,
  type WorkCalendarSnapshot
} from '~/utils/work-calendar-loading'

interface DayAgg {
  date: string
  total: number
  overdue?: number
  fine?: number
  completed?: number
  open?: number
  max_severity?: number
  items?: OperationalTaskSummary[]
}

const api = useApi()
const route = useRoute()
const toast = useToast()
const { sessionEpoch } = useDashboard()
const {
  view, date, range, label, setView, setDate, navigate, monthGrid, weekDates, parseYmd
} = useWorkCalendarRange()

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')

const days = ref<DayAgg[]>([])
const dayItems = ref<OperationalTaskSummary[]>([])
const loading = ref(false)
const dayLoading = ref(false)
const loadError = ref<string | null>(null)
const dayError = ref<string | null>(null)
const usingStaleInterval = ref(false)
const lastGoodInterval = ref<WorkCalendarSnapshot<DayAgg[]> | null>(null)
const railOpen = ref(false)
const railTab = ref<'tarefas' | 'atrasadas' | 'concluidas'>('tarefas')
let intervalLoadSeq = 0
let dayLoadSeq = 0

const viewItems = [
  { label: 'Mês', value: 'month' },
  { label: 'Semana', value: 'week' },
  { label: 'Dia', value: 'day' }
]

const selectedView = computed({
  get: () => view.value,
  set: (v: string | number) => { void setView(String(v) as 'month' | 'week' | 'day') }
})

const dayMap = computed(() => {
  const m = new Map<string, DayAgg>()
  for (const d of days.value) m.set(d.date, d)
  return m
})

const calendarModel = computed({
  get: () => {
    const { y, m, d } = parseYmd(date.value)
    return new CalendarDate(y, m, d)
  },
  set: (value: DateValue | undefined | null) => {
    if (!value) return
    void setDate(`${value.year}-${String(value.month).padStart(2, '0')}-${String(value.day).padStart(2, '0')}`)
  }
})

const filterParams = computed(() => {
  const q = route.query
  const out: Record<string, string | number> = {}
  if (q.department_id) out.department_id = Number(q.department_id)
  if (q.assignee_membership_id) out.assignee_membership_id = Number(q.assignee_membership_id)
  if (q.client_id) out.client_id = Number(q.client_id)
  if (q.status) out.status = String(q.status)
  if (q.risk) out.risk = String(q.risk)
  return out
})

const calendarLoadKeys = computed(() => {
  const context = JSON.stringify({
    filters: filterParams.value,
    session: sessionEpoch.value
  })
  return {
    interval: JSON.stringify({
      context,
      from: range.value.from,
      to: range.value.to
    }),
    day: JSON.stringify({
      context,
      date: date.value
    })
  }
})

async function loadInterval() {
  const seq = ++intervalLoadSeq
  const epoch = sessionEpoch.value
  const requestedKey = calendarLoadKeys.value.interval
  const requestedRange = { ...range.value }
  const requestedFilters = { ...filterParams.value }
  const matchingSnapshot = workCalendarSnapshotForKey(requestedKey, lastGoodInterval.value)
  loading.value = true
  loadError.value = null
  usingStaleInterval.value = false
  days.value = matchingSnapshot ?? []
  try {
    const res = await api.work.calendar(
      requestedRange.from,
      requestedRange.to,
      requestedFilters
    )
    if (
      seq !== intervalLoadSeq
      || epoch !== sessionEpoch.value
      || requestedKey !== calendarLoadKeys.value.interval
    ) return
    days.value = res.data.days as DayAgg[]
    lastGoodInterval.value = { key: requestedKey, data: days.value }
    loadError.value = null
    usingStaleInterval.value = false
  } catch (e) {
    if (
      seq !== intervalLoadSeq
      || epoch !== sessionEpoch.value
      || requestedKey !== calendarLoadKeys.value.interval
    ) return
    loadError.value = apiErrorMessage(e, 'Falha ao carregar calendário.')
    const fallback = workCalendarSnapshotForKey(requestedKey, lastGoodInterval.value)
    if (fallback !== null) {
      days.value = fallback
      usingStaleInterval.value = true
      toast.add({ title: loadError.value + ' Exibindo última carga válida.', color: 'warning' })
    } else {
      days.value = []
      usingStaleInterval.value = false
      toast.add({ title: loadError.value, color: 'error' })
    }
  } finally {
    if (
      seq === intervalLoadSeq
      && epoch === sessionEpoch.value
      && requestedKey === calendarLoadKeys.value.interval
    ) loading.value = false
  }
}

async function loadDay() {
  const seq = ++dayLoadSeq
  const epoch = sessionEpoch.value
  const requestedKey = calendarLoadKeys.value.day
  const requestedDate = date.value
  const requestedFilters = { ...filterParams.value }
  dayLoading.value = true
  dayError.value = null
  try {
    const res = await api.work.calendarDay(requestedDate, {
      per_page: 50,
      ...requestedFilters
    })
    if (
      seq !== dayLoadSeq
      || epoch !== sessionEpoch.value
      || requestedKey !== calendarLoadKeys.value.day
    ) return
    dayItems.value = res.data as OperationalTaskSummary[]
  } catch (e) {
    if (
      seq !== dayLoadSeq
      || epoch !== sessionEpoch.value
      || requestedKey !== calendarLoadKeys.value.day
    ) return
    dayError.value = apiErrorMessage(e, 'Falha ao carregar o dia.')
    dayItems.value = []
    toast.add({ title: dayError.value, color: 'error' })
  } finally {
    if (
      seq === dayLoadSeq
      && epoch === sessionEpoch.value
      && requestedKey === calendarLoadKeys.value.day
    ) dayLoading.value = false
  }
}

async function openDay(d: string) {
  // setDate dispara o watcher que chama loadDay — evitar fetch duplicado
  await setDate(d)
  if (isMobile.value) railOpen.value = true
}

const weekLanes = computed(() => weekDates(date.value).map(d => ({
  date: d,
  agg: dayMap.value.get(d),
  items: (dayMap.value.get(d)?.items || []) as OperationalTaskSummary[]
})))

const monthCells = computed(() => {
  const { y, m } = parseYmd(date.value)
  return monthGrid(y, m).map(cell => ({
    ...cell,
    agg: dayMap.value.get(cell.date)
  }))
})

const railItems = computed(() => {
  const list = dayItems.value
  if (railTab.value === 'atrasadas') {
    return list.filter(i => i.risks?.includes('ATRASADA') || i.risks?.includes('EM_MULTA'))
  }
  if (railTab.value === 'concluidas') {
    return list.filter(i => i.status === 'CONCLUIDA' || i.status === 'DISPENSADA')
  }
  return list
})

const severityClass = (agg?: DayAgg) => {
  if (!agg?.total) return ''
  if ((agg.fine || 0) > 0 || (agg.max_severity || 0) >= 3) return 'bg-error/15 text-error'
  if ((agg.overdue || 0) > 0 || (agg.max_severity || 0) >= 2) return 'bg-warning/15 text-warning'
  return 'bg-primary/10 text-primary'
}

const taskContext = (item: OperationalTaskSummary) => [
  item.process?.client?.name,
  item.process?.title
].filter(Boolean).join(' · ')

watch(calendarLoadKeys, (next, previous) => {
  const plan = workCalendarLoadPlan(previous, next)
  if (plan.interval) void loadInterval()
  if (plan.day) void loadDay()
}, { immediate: true, flush: 'sync' })

watch(sessionEpoch, () => {
  days.value = []
  dayItems.value = []
  lastGoodInterval.value = null
  loadError.value = null
  usingStaleInterval.value = false
})
</script>

<template>
  <UDashboardPanel id="work-calendar-main" data-testid="work-calendar" class="min-w-0">
    <template #header>
      <UDashboardNavbar title="Calendário operacional" data-testid="page-navbar">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex w-full min-w-0 flex-wrap items-center gap-1">
          <UButton
            icon="i-lucide-chevron-left"
            color="neutral"
            variant="ghost"
            aria-label="Período anterior"
            @click="() => { void navigate(-1) }"
          />
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            label="Hoje"
            @click="() => { void navigate(0) }"
          />
          <UButton
            icon="i-lucide-chevron-right"
            color="neutral"
            variant="ghost"
            aria-label="Próximo período"
            @click="() => { void navigate(1) }"
          />
          <span
            class="order-last w-full truncate pt-1 text-sm font-medium sm:order-none sm:ms-2 sm:w-auto sm:pt-0"
            aria-live="polite"
          >
            {{ label }}
          </span>
          <ShellScrollableTabs
            v-model="selectedView"
            :items="viewItems"
            size="xs"
            class="min-w-0 max-w-full sm:ms-2 sm:max-w-none"
            aria-label="Visualização do calendário"
            test-id="work-calendar-view-tabs"
          />
          <UButton
            class="ms-auto lg:hidden"
            icon="i-lucide-panel-right"
            color="neutral"
            variant="ghost"
            aria-label="Abrir painel do dia"
            @click="() => { railOpen = true }"
          />
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <h1 class="sr-only">
        Calendário operacional
      </h1>

      <div v-if="loadError && !usingStaleInterval" class="p-4">
        <UAlert color="error" :title="loadError">
          <template #actions>
            <UButton size="xs" label="Tentar de novo" @click="loadInterval" />
          </template>
        </UAlert>
      </div>

      <div v-else-if="loading && !days.length && !usingStaleInterval" class="p-4 space-y-3">
        <USkeleton class="h-64 w-full" />
      </div>

      <div v-else class="min-w-0 overflow-x-clip">
        <div
          v-if="loadError && usingStaleInterval"
          class="px-2 pt-2 sm:px-4 sm:pt-4"
          data-testid="work-calendar-interval-stale"
        >
          <UAlert
            color="warning"
            variant="subtle"
            title="Exibindo a última carga válida"
            :description="loadError"
          >
            <template #actions>
              <UButton
                size="xs"
                color="warning"
                variant="soft"
                label="Tentar de novo"
                :loading="loading"
                @click="loadInterval"
              />
            </template>
          </UAlert>
        </div>

        <!-- Mês -->
        <div v-if="view === 'month'" class="p-2 sm:p-4" data-testid="work-calendar-month">
          <div class="mb-2 grid grid-cols-7 text-center text-xs font-medium text-muted">
            <span v-for="wd in ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom']" :key="wd">{{ wd }}</span>
          </div>
          <div class="grid grid-cols-7 border-s border-t border-default">
            <button
              v-for="cell in monthCells"
              :key="cell.date"
              type="button"
              class="min-h-14 border-e border-b border-default p-1 text-left transition-colors hover:bg-elevated/60 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary sm:min-h-16"
              :class="[
                !cell.inMonth && 'opacity-40',
                cell.date === date && 'relative z-10 ring-2 ring-inset ring-primary',
                severityClass(cell.agg)
              ]"
              :aria-label="`${cell.date}${cell.agg?.total ? `, ${cell.agg.total} tarefas` : ''}`"
              @click="openDay(cell.date)"
            >
              <span class="text-xs font-medium">{{ parseYmd(cell.date).d }}</span>
              <span v-if="cell.agg?.total" class="mt-1 block text-xs font-semibold">
                {{ cell.agg.total }}
              </span>
            </button>
          </div>
        </div>

        <!-- Semana: grupos responsivos por data, sem eixo de horas ou scroll horizontal -->
        <div v-else-if="view === 'week'" class="p-2 sm:p-4" data-testid="work-calendar-week">
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2 md:grid-cols-4 2xl:grid-cols-7">
            <section
              v-for="lane in weekLanes"
              :key="lane.date"
              class="min-w-0 border-b border-default py-2"
              :class="lane.date === date ? 'border-b-2 border-primary' : ''"
            >
              <button
                type="button"
                class="mb-1 flex w-full items-center justify-between gap-2 text-left text-sm font-medium hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :aria-label="`Abrir agenda de ${formatDueDate(lane.date)}`"
                @click="openDay(lane.date)"
              >
                <span class="truncate">{{ formatDueDate(lane.date) }}</span>
                <span v-if="lane.agg?.total" class="shrink-0 text-xs text-muted">
                  {{ lane.agg.total }} {{ lane.agg.total === 1 ? 'tarefa' : 'tarefas' }}
                </span>
              </button>
              <ul class="divide-y divide-default" data-testid="work-calendar-week-task-list">
                <li
                  v-for="item in lane.items"
                  :key="item.id"
                >
                  <button
                    type="button"
                    class="w-full py-1.5 text-left text-xs hover:bg-elevated/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-label="`Abrir tarefa ${item.title}`"
                    @click="navigateTo(`/work/tasks/${item.id}`)"
                  >
                    <span class="block truncate font-medium">{{ item.title }}</span>
                    <span class="block truncate text-muted">{{ item.process?.client?.name }}</span>
                  </button>
                </li>
                <li v-if="!lane.items.length" class="py-1.5 text-xs text-muted">
                  Nenhuma tarefa
                </li>
              </ul>
            </section>
          </div>
        </div>

        <!-- Dia: fila detalhada -->
        <div v-else class="p-2 sm:p-4" data-testid="work-calendar-day">
          <div v-if="dayLoading" class="space-y-2">
            <USkeleton v-for="i in 5" :key="i" class="h-14 w-full" />
          </div>
          <div
            v-else-if="dayError"
            data-testid="work-calendar-day-error"
          >
            <UAlert color="error" :title="dayError">
              <template #actions>
                <UButton
                  size="xs"
                  variant="soft"
                  label="Tentar de novo"
                  @click="loadDay"
                />
              </template>
            </UAlert>
          </div>
          <div v-else-if="!dayItems.length">
            <UEmpty
              icon="i-lucide-calendar-off"
              :title="`Nenhuma tarefa em ${formatDueDate(date)}`"
              size="sm"
            />
          </div>
          <ul
            v-else
            class="divide-y divide-default border-y border-default"
            data-testid="work-calendar-day-task-list"
          >
            <li
              v-for="item in dayItems"
              :key="item.id"
            >
              <button
                type="button"
                class="flex w-full items-start justify-between gap-2 px-1 py-2.5 text-left hover:bg-elevated/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                @click="navigateTo(`/work/tasks/${item.id}`)"
              >
                <div class="min-w-0">
                  <p class="truncate font-medium">
                    {{ item.title }}
                  </p>
                  <p v-if="taskContext(item)" class="truncate text-xs text-muted">
                    {{ taskContext(item) }}
                  </p>
                </div>
                <UBadge
                  size="sm"
                  variant="subtle"
                  :color="highestRiskColor(item.risks)"
                  :label="item.risks?.[0] ? workRiskLabel(item.risks[0]) : taskStatusLabel(item.status)"
                />
              </button>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <!-- Rail desktop -->
  <UDashboardPanel
    id="work-calendar-rail"
    class="hidden lg:flex"
    resizable
    :default-size="22"
    :min-size="18"
    :max-size="28"
  >
    <template #header>
      <UDashboardNavbar title="Dia selecionado" data-testid="work-calendar-rail-navbar" :toggle="false" />
    </template>

    <template #body>
      <div class="flex min-w-0 flex-col gap-3 overflow-x-clip p-3">
        <UCalendar v-model="calendarModel" class="max-w-full" />
        <ShellScrollableTabs
          v-model="railTab"
          :items="[
            { label: 'Tarefas', value: 'tarefas' },
            { label: 'Atrasadas', value: 'atrasadas' },
            { label: 'Concluídas', value: 'concluidas' }
          ]"
          size="xs"
          aria-label="Filtro do dia selecionado"
          test-id="work-calendar-rail-tabs"
        />
        <div v-if="dayLoading" class="space-y-2">
          <USkeleton v-for="i in 4" :key="i" class="h-10 w-full" />
        </div>
        <UAlert
          v-else-if="dayError"
          color="error"
          :title="dayError"
          data-testid="work-calendar-rail-day-error"
        >
          <template #actions>
            <UButton
              size="xs"
              variant="soft"
              label="Tentar de novo"
              @click="loadDay"
            />
          </template>
        </UAlert>
        <ul v-else class="divide-y divide-default border-y border-default" data-testid="work-calendar-rail-task-list">
          <li
            v-for="item in railItems"
            :key="item.id"
          >
            <button
              type="button"
              class="w-full px-1 py-2 text-left text-sm hover:bg-elevated/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              @click="navigateTo(`/work/tasks/${item.id}`)"
            >
              <span class="block truncate font-medium">{{ item.title }}</span>
              <span class="block truncate text-xs text-muted">{{ taskStatusLabel(item.status) }}</span>
            </button>
          </li>
          <li v-if="!railItems.length" class="list-none">
            <UEmpty
              icon="i-lucide-inbox"
              title="Nenhuma tarefa nesta lista"
              size="sm"
            />
          </li>
        </ul>
      </div>
    </template>
  </UDashboardPanel>

  <!-- Rail mobile -->
  <USlideover v-model:open="railOpen" title="Dia selecionado" class="lg:hidden">
    <template #body>
      <div class="flex min-w-0 flex-col gap-3 overflow-x-clip">
        <UCalendar v-model="calendarModel" class="max-w-full" />
        <ShellScrollableTabs
          v-model="railTab"
          :items="[
            { label: 'Tarefas', value: 'tarefas' },
            { label: 'Atrasadas', value: 'atrasadas' },
            { label: 'Concluídas', value: 'concluidas' }
          ]"
          size="xs"
          aria-label="Filtro do dia selecionado"
          test-id="work-calendar-mobile-rail-tabs"
        />
        <div v-if="dayLoading" class="space-y-2">
          <USkeleton v-for="i in 4" :key="i" class="h-10 w-full" />
        </div>
        <UAlert
          v-else-if="dayError"
          color="error"
          :title="dayError"
          data-testid="work-calendar-mobile-day-error"
        >
          <template #actions>
            <UButton
              size="xs"
              variant="soft"
              label="Tentar de novo"
              @click="loadDay"
            />
          </template>
        </UAlert>
        <ul v-else class="divide-y divide-default border-y border-default" data-testid="work-calendar-mobile-task-list">
          <li
            v-for="item in railItems"
            :key="item.id"
          >
            <button
              type="button"
              class="w-full px-1 py-2 text-left text-sm hover:bg-elevated/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              @click="navigateTo(`/work/tasks/${item.id}`)"
            >
              <span class="block truncate font-medium">{{ item.title }}</span>
              <span class="block truncate text-xs text-muted">{{ taskStatusLabel(item.status) }}</span>
            </button>
          </li>
          <li v-if="!railItems.length" class="list-none">
            <UEmpty
              icon="i-lucide-inbox"
              title="Nenhuma tarefa"
              size="sm"
            />
          </li>
        </ul>
      </div>
    </template>
  </USlideover>
</template>
